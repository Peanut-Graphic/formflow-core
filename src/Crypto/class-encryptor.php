<?php
/**
 * Encryption for data at rest, shared by FormFlow Pro and FormFlow Lite.
 *
 * Two formats:
 *
 *  - LEGACY (read + default write): AES-256-CBC, 16-byte IV prepended, base64.
 *    Extracted byte-for-byte from the plugins. It has no integrity check, so a
 *    stored value can be altered undetectably, and its key is a truncation
 *    (a configured constant cut to 32 bytes; the wp_salt fallback cut to 32
 *    hex characters, i.e. 128 bits).
 *
 *  - V2 (read; written when authenticated writes are on): XChaCha20-Poly1305
 *    AEAD with a random 192-bit nonce, framed as "ffc2:" . base64(nonce . ct).
 *    Tampering or a wrong key fails decryption. Its key is HKDF-SHA256 over the
 *    FULL key material, not a truncation. The prefix cannot collide with legacy
 *    output, whose base64 alphabet has no ':'.
 *
 * THE CONSTRAINT THAT SHAPES THIS FILE: existing ciphertext must keep
 * decrypting, and a site that rolls a plugin back must still read what the
 * newer version wrote. So the legacy key derivation and format are reproduced
 * exactly, and the rollout is two-phase: this version reads both formats and
 * still WRITES legacy unless authenticated writes are enabled; a later version
 * flips the default once every consumer that might be rolled back to can read
 * V2. Callers migrate stored values lazily with needsReencrypt().
 *
 * Key derivation is a pure function of (constant value, fallback salt) so it is
 * testable without WordPress.
 *
 * @package Peanut\FormCore
 * @since 0.5.0
 */

namespace Peanut\FormCore\Crypto;

if (!defined('ABSPATH')) {
    exit;
}

final class Encryptor
{
    private const METHOD     = 'AES-256-CBC';
    private const IV_LENGTH  = 16;
    private const MIN_KEY_LENGTH = 32;

    private const V2_PREFIX = 'ffc2:';
    private const V2_INFO   = 'formflow-core/v2/data-at-rest';
    private const V2_AD     = 'formflow-core/v2';

    private string $key;

    private string $v2Key;

    private bool $authenticatedWrites;

    /**
     * @param string      $key                 Legacy 32-byte key (see deriveKey()).
     * @param string|null $v2Key               32-byte V2 key; when null it is derived from $key.
     *                                         Prefer fromKeyMaterial(), which derives it from the
     *                                         full-entropy inputs instead of the truncated key.
     * @param bool        $authenticatedWrites Write V2 (true) or legacy (false, the default for
     *                                         phase 1 of the rollout — see the file docblock).
     */
    public function __construct(string $key, ?string $v2Key = null, bool $authenticatedWrites = false)
    {
        $this->key                 = $key;
        $this->v2Key               = $v2Key ?? self::hkdf($key);
        $this->authenticatedWrites = $authenticatedWrites;
    }

    /**
     * Build from the raw key inputs: the legacy key exactly as before, and the
     * V2 key from the untruncated material.
     *
     * @param string|null $constantValue Value of the plugin's *_ENCRYPTION_KEY, or null.
     * @param string      $fallbackSalt  wp_salt('auth') equivalent.
     */
    public static function fromKeyMaterial(
        ?string $constantValue,
        string $fallbackSalt,
        bool $authenticatedWrites = false
    ): self {
        $material = $constantValue !== null && strlen($constantValue) >= self::MIN_KEY_LENGTH
            ? 'constant:' . $constantValue
            : 'wp_salt:' . $fallbackSalt;

        return new self(self::deriveKey($constantValue, $fallbackSalt), self::hkdf($material), $authenticatedWrites);
    }

    /**
     * Derive the LEGACY encryption key exactly as the plugins always have.
     *
     * A configured key is TRUNCATED to 32 bytes (not hashed); only the
     * wp_salt fallback is hashed. Preserved verbatim — changing which branch
     * hashes would invalidate every record already written.
     *
     * @param string|null $constantValue Value of the plugin's *_ENCRYPTION_KEY, or null.
     * @param string      $fallbackSalt  wp_salt('auth') equivalent.
     * @return string 32-byte key.
     */
    public static function deriveKey(?string $constantValue, string $fallbackSalt): string
    {
        if ($constantValue !== null && strlen($constantValue) >= self::MIN_KEY_LENGTH) {
            return substr($constantValue, 0, self::MIN_KEY_LENGTH);
        }

        return substr(hash('sha256', $fallbackSalt), 0, self::MIN_KEY_LENGTH);
    }

    /**
     * Build from a plugin's key constant, falling back to wp_salt('auth').
     *
     * @param string $keyConstant e.g. 'ISF_ENCRYPTION_KEY'.
     */
    public static function fromKeyConstant(string $keyConstant, bool $authenticatedWrites = false): self
    {
        $configured = defined($keyConstant) ? (string) constant($keyConstant) : null;
        $fallback   = function_exists('wp_salt') ? (string) wp_salt('auth') : '';

        return self::fromKeyMaterial($configured, $fallback, $authenticatedWrites);
    }

    /**
     * Encrypt a string. Returns '' for empty input (preserved behaviour).
     *
     * @throws \RuntimeException when the cipher fails.
     */
    public function encrypt(string $data): string
    {
        if ($data === '') {
            return '';
        }

        return $this->authenticatedWrites ? $this->encryptV2($data) : $this->encryptLegacy($data);
    }

    /**
     * Decrypt either format. Returns '' on any failure — including a V2 value
     * that was tampered with or written under a different key — so callers
     * treat empty as "unavailable" rather than surfacing a garbled value.
     */
    public function decrypt(string $data): string
    {
        if ($data === '') {
            return '';
        }

        if (self::isV2($data)) {
            return $this->decryptV2($data);
        }

        return $this->decryptLegacy($data);
    }

    /**
     * True when a stored value should be rewritten: it is a legacy value and
     * this encryptor writes V2. Callers use it to migrate records lazily
     * (decrypt, then encrypt and save) as they are read.
     */
    public function needsReencrypt(string $stored): bool
    {
        return $this->authenticatedWrites && $stored !== '' && !self::isV2($stored);
    }

    /**
     * Encrypt an array by JSON-encoding it first.
     */
    public function encryptArray(array $data): string
    {
        return $this->encrypt((string) json_encode($data));
    }

    /**
     * Decrypt to an array; [] when the payload is unreadable or not an array.
     */
    public function decryptArray(string $data): array
    {
        $json = $this->decrypt($data);
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function isV2(string $data): bool
    {
        return strncmp($data, self::V2_PREFIX, strlen(self::V2_PREFIX)) === 0;
    }

    private static function hkdf(string $material): string
    {
        return hash_hkdf('sha256', $material, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, self::V2_INFO);
    }

    private function encryptV2(string $data): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($data, self::V2_AD, $nonce, $this->v2Key);

        return self::V2_PREFIX . base64_encode($nonce . $ciphertext);
    }

    private function decryptV2(string $data): string
    {
        $decoded = base64_decode(substr($data, strlen(self::V2_PREFIX)), true);
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        if ($decoded === false || strlen($decoded) < $nonceLength + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            return '';
        }

        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($decoded, $nonceLength),
                self::V2_AD,
                substr($decoded, 0, $nonceLength),
                $this->v2Key
            );
        } catch (\SodiumException $e) {
            return '';
        }

        return $plaintext !== false ? $plaintext : '';
    }

    private function encryptLegacy(string $data): string
    {
        $iv = openssl_random_pseudo_bytes(self::IV_LENGTH);

        $encrypted = openssl_encrypt($data, self::METHOD, $this->key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new \RuntimeException('Encryption failed');
        }

        return base64_encode($iv . $encrypted);
    }

    private function decryptLegacy(string $data): string
    {
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return '';
        }

        $iv        = substr($decoded, 0, self::IV_LENGTH);
        $encrypted = substr($decoded, self::IV_LENGTH);

        if (strlen($iv) !== self::IV_LENGTH) {
            return '';
        }

        $decrypted = openssl_decrypt($encrypted, self::METHOD, $this->key, OPENSSL_RAW_DATA, $iv);

        return $decrypted !== false ? $decrypted : '';
    }
}
