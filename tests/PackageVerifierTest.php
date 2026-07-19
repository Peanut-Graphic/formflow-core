<?php

namespace Peanut\FormCore\Tests;

use Peanut\FormCore\Update\PackageVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Proves the signed-update crypto core end to end with a throwaway keypair,
 * and pins FAIL-CLOSED behaviour on every failure branch.
 */
final class PackageVerifierTest extends TestCase
{
    private string $bytes;
    private string $pub;
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('libsodium unavailable');
        }
        $kp           = sodium_crypto_sign_keypair();
        $this->pub    = base64_encode(sodium_crypto_sign_publickey($kp));
        $this->secret = sodium_crypto_sign_secretkey($kp);
        $this->bytes  = 'PK' . str_repeat('formflow-lite-package-bytes', 40);
    }

    private function manifestFor(string $bytes, ?string $secret = null): array
    {
        return [
            'sha256'    => hash('sha256', $bytes),
            'signature' => base64_encode(sodium_crypto_sign_detached($bytes, $secret ?? $this->secret)),
        ];
    }

    public function test_accepts_a_correctly_signed_package(): void
    {
        $this->assertTrue(
            PackageVerifier::verifyBytes($this->bytes, $this->manifestFor($this->bytes), $this->pub)
        );
    }

    public function test_rejects_tampered_bytes(): void
    {
        $manifest = $this->manifestFor($this->bytes);
        $this->assertFalse(
            PackageVerifier::verifyBytes($this->bytes . 'evil', $manifest, $this->pub),
            'Modified package bytes must not verify'
        );
    }

    public function test_rejects_a_signature_from_the_wrong_key(): void
    {
        // The attack this exists to stop: a validly-formed package signed by
        // someone who is not us.
        $other  = sodium_crypto_sign_keypair();
        $forged = $this->manifestFor($this->bytes, sodium_crypto_sign_secretkey($other));
        $this->assertFalse(PackageVerifier::verifyBytes($this->bytes, $forged, $this->pub));
    }

    public function test_rejects_when_sha256_matches_but_signature_does_not(): void
    {
        // Proves the signature is actually load-bearing, not decorative: an
        // attacker who swaps the zip AND its hash still cannot forge the sig.
        $evil     = $this->bytes . 'swapped';
        $manifest = $this->manifestFor($this->bytes);
        $manifest['sha256'] = hash('sha256', $evil);
        $this->assertFalse(PackageVerifier::verifyBytes($evil, $manifest, $this->pub));
    }

    public function test_fails_closed_on_missing_or_empty_manifest_fields(): void
    {
        $good = $this->manifestFor($this->bytes);
        foreach ([[], ['sha256' => $good['sha256']], ['signature' => $good['signature']],
                  ['sha256' => '', 'signature' => ''],] as $i => $manifest) {
            $this->assertFalse(
                PackageVerifier::verifyBytes($this->bytes, $manifest, $this->pub),
                "manifest case #$i must fail closed"
            );
        }
    }

    public function test_fails_closed_on_undecodable_or_wrong_length_values(): void
    {
        $m = $this->manifestFor($this->bytes);
        $this->assertFalse(PackageVerifier::verifyBytes($this->bytes, ['sha256' => $m['sha256'], 'signature' => '!!not-base64!!'], $this->pub));
        $this->assertFalse(PackageVerifier::verifyBytes($this->bytes, ['sha256' => $m['sha256'], 'signature' => base64_encode('short')], $this->pub));
        $this->assertFalse(PackageVerifier::verifyBytes($this->bytes, $m, base64_encode('not-a-real-key')));
        $this->assertFalse(PackageVerifier::verifyBytes($this->bytes, $m, '!!not-base64!!'));
    }

    /** @dataProvider trustedUrls */
    public function test_accepts_trusted_hosts_and_their_subdomains(string $url): void
    {
        $this->assertTrue(PackageVerifier::isTrustedPackageUrl($url, ['peanutgraphic.com', 'github.com']), $url);
    }

    public static function trustedUrls(): array
    {
        return [
            ['https://peanutgraphic.com/downloads/formflow-lite.zip'],
            ['https://www.peanutgraphic.com/x.zip'],
            // GitHub redirects release assets here — the host that peanut-connect's
            // substring check silently failed to match, skipping verification.
            ['https://codeload.github.com/Peanut-Graphic/formflow-lite/zip/refs/tags/v1'],
            ['https://github.com/Peanut-Graphic/formflow-lite/releases/download/v1/x.zip'],
        ];
    }

    /** @dataProvider untrustedUrls */
    public function test_rejects_untrusted_insecure_or_lookalike_hosts(string $url): void
    {
        $this->assertFalse(PackageVerifier::isTrustedPackageUrl($url, ['peanutgraphic.com', 'github.com']), $url);
    }

    public static function untrustedUrls(): array
    {
        return [
            ['http://peanutgraphic.com/x.zip'],              // not https
            ['https://evil.test/x.zip'],                     // wrong host
            ['https://evilpeanutgraphic.com/x.zip'],         // prefix lookalike
            ['https://peanutgraphic.com.evil.test/x.zip'],   // suffix trick
            ['https://notgithub.com/x.zip'],
            ['ftp://peanutgraphic.com/x.zip'],               // wrong scheme
            ['not-a-url'],
            [''],
        ];
    }

    public function test_rejects_everything_when_no_trusted_hosts_configured(): void
    {
        $this->assertFalse(PackageVerifier::isTrustedPackageUrl('https://peanutgraphic.com/x.zip', []));
    }
}
