<?php
/**
 * Package Verifier — the cryptographic core of Peanut's signed plugin updates.
 *
 * Pure and WordPress-free by design: every method is static, side-effect free,
 * and depends only on PHP + libsodium, so the sha256 + Ed25519 logic can be
 * proven in isolation with a throwaway keypair (the production signing key is
 * not in any repo).
 *
 * FAIL-CLOSED is the contract. Every branch — missing manifest field, decode
 * failure, wrong key/signature length, unavailable libsodium, untrusted host —
 * returns false. A verifier that returns true on "I couldn't check" is a
 * facade, which is exactly the defect class this package exists to retire.
 *
 * @package Peanut\FormCore
 * @since 0.2.0
 */

namespace Peanut\FormCore\Update;

if (!defined('ABSPATH')) {
    exit;
}

final class PackageVerifier
{
    /**
     * Verify raw package bytes against a signed manifest.
     *
     * Checks BOTH halves: the sha256 must match the bytes (integrity) AND the
     * detached Ed25519 signature must verify against the given public key
     * (authenticity). sha256 alone proves nothing — an attacker who can swap
     * the zip can swap the hash beside it; only the signature is unforgeable.
     *
     * @param string $zipBytes     Raw package bytes.
     * @param array  $manifest     Decoded manifest; expects 'sha256' + 'signature'.
     * @param string $base64Pubkey Base64 Ed25519 public key to verify against.
     * @return bool True iff sha256 matches AND the signature verifies.
     */
    public static function verifyBytes(string $zipBytes, array $manifest, string $base64Pubkey): bool
    {
        if (empty($manifest['sha256']) || empty($manifest['signature'])) {
            return false;
        }

        // Constant-time compare — a timing-variant compare on a hash is a weak
        // but real oracle, and hash_equals costs nothing here.
        if (!hash_equals((string) $manifest['sha256'], hash('sha256', $zipBytes))) {
            return false;
        }

        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }

        $sig = base64_decode((string) $manifest['signature'], true);
        $key = base64_decode($base64Pubkey, true);

        if ($sig === false || $key === false
            || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($sig, $zipBytes, $key);
    }

    /**
     * True only for an HTTPS URL whose host is a trusted package host, or a
     * subdomain of one.
     *
     * Matching is on a LABEL BOUNDARY, never a substring: "github.com" matches
     * "codeload.github.com" but "peanutgraphic.com" must NOT match
     * "evilpeanutgraphic.com" or "peanutgraphic.com.attacker.test". Substring
     * host checks are how this gate gets silently bypassed — a real bug found in
     * peanut-connect, where the package URL never contained the expected literal
     * so verification was skipped entirely for every CDN-served release.
     *
     * @param string        $url          Package URL.
     * @param array<string> $trustedHosts Bare hostnames, e.g. ['peanutgraphic.com','github.com'].
     * @return bool
     */
    public static function isTrustedPackageUrl(string $url, array $trustedHosts): bool
    {
        if ($url === '' || empty($trustedHosts)) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));

        // TLS is non-negotiable: host trust means nothing over a channel an
        // attacker can rewrite.
        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        foreach ($trustedHosts as $expected) {
            $expected = strtolower(trim((string) $expected));
            if ($expected === '') {
                continue;
            }
            if ($host === $expected || str_ends_with($host, '.' . $expected)) {
                return true;
            }
        }

        return false;
    }
}
