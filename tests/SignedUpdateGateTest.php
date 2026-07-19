<?php

namespace Peanut\FormCore\Tests;

use Peanut\FormCore\Update\SignedUpdateGate;
use PHPUnit\Framework\TestCase;

/**
 * Pins the gate's enforcement model. The headline case is
 * test_refuses_an_unsigned_package_for_our_plugin — that is precisely the
 * FormFlow-Lite hole (L1): packages installed with transport trust only.
 */
final class SignedUpdateGateTest extends TestCase
{
    private const PLUGIN = 'formflow-lite/formflow-lite.php';
    private const URL    = 'https://peanutgraphic.com/downloads/formflow-lite-3.2.25.zip';

    private string $pub;
    private string $secret;
    private string $bytes;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('libsodium unavailable');
        }
        $kp            = sodium_crypto_sign_keypair();
        $this->pub     = base64_encode(sodium_crypto_sign_publickey($kp));
        $this->secret  = sodium_crypto_sign_secretkey($kp);
        $this->bytes   = 'PK' . str_repeat('lite-package', 50);
        $GLOBALS['__wp_http']  = [];
        $GLOBALS['__wp_files'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['__wp_http'] = $GLOBALS['__wp_files'] = [];
        parent::tearDown();
    }

    private function gate(): SignedUpdateGate
    {
        return new SignedUpdateGate(self::PLUGIN, ['peanutgraphic.com', 'github.com'], $this->pub, 'formflow-lite');
    }

    private function serve(string $url, string $bytes, ?string $manifestJson = 'auto'): void
    {
        $GLOBALS['__wp_files'][$url] = $bytes;
        if ($manifestJson === 'auto') {
            $manifestJson = json_encode([
                'sha256'    => hash('sha256', $bytes),
                'signature' => base64_encode(sodium_crypto_sign_detached($bytes, $this->secret)),
            ]);
        }
        if ($manifestJson !== null) {
            $GLOBALS['__wp_http'][$url . '.manifest.json'] = $manifestJson;
        }
    }

    public function test_installs_a_correctly_signed_package(): void
    {
        $this->serve(self::URL, $this->bytes);
        $result = $this->gate()->verifyPackageSignature(false, self::URL, null, ['plugin' => self::PLUGIN]);

        $this->assertIsString($result, 'verified package should yield a local file path');
        $this->assertSame($this->bytes, file_get_contents($result));
        @unlink($result);
    }

    public function test_refuses_an_unsigned_package_for_our_plugin(): void
    {
        // THE L1 CASE: package served with no manifest beside it. Before this
        // gate, FormFlow Lite installed exactly this, unverified.
        $this->serve(self::URL, $this->bytes, null);
        $result = $this->gate()->verifyPackageSignature(false, self::URL, null, ['plugin' => self::PLUGIN]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertStringContainsString('manifest missing', $result->get_error_message());
    }

    public function test_refuses_a_package_signed_by_the_wrong_key(): void
    {
        $other = sodium_crypto_sign_keypair();
        $this->serve(self::URL, $this->bytes, json_encode([
            'sha256'    => hash('sha256', $this->bytes),
            'signature' => base64_encode(sodium_crypto_sign_detached($this->bytes, sodium_crypto_sign_secretkey($other))),
        ]));
        $result = $this->gate()->verifyPackageSignature(false, self::URL, null, ['plugin' => self::PLUGIN]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertStringContainsString('signature verification', $result->get_error_message());
    }

    public function test_refuses_tampered_bytes_even_with_a_matching_hash(): void
    {
        $evil = $this->bytes . 'backdoor';
        $this->serve(self::URL, $evil, json_encode([
            'sha256'    => hash('sha256', $evil), // attacker updated the hash too
            'signature' => base64_encode(sodium_crypto_sign_detached($this->bytes, $this->secret)),
        ]));
        $result = $this->gate()->verifyPackageSignature(false, self::URL, null, ['plugin' => self::PLUGIN]);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_refuses_our_plugin_served_from_an_untrusted_host(): void
    {
        $url = 'https://evil.test/formflow-lite.zip';
        $this->serve($url, $this->bytes);
        $result = $this->gate()->verifyPackageSignature(false, $url, null, ['plugin' => self::PLUGIN]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertStringContainsString('untrusted host', $result->get_error_message());
    }

    public function test_stands_aside_for_a_different_plugin(): void
    {
        $result = $this->gate()->verifyPackageSignature(false, 'https://example.org/other-plugin.zip', null, ['plugin' => 'other/other.php']);
        $this->assertFalse($result, 'must not interfere with third-party plugin updates');
    }

    public function test_stands_aside_for_unidentified_third_party_packages(): void
    {
        // No hook_extra identity AND not one of our hosts -> not our business.
        $result = $this->gate()->verifyPackageSignature(false, 'https://example.org/some.zip', null, []);
        $this->assertFalse($result);
    }

    public function test_enforces_on_our_host_even_without_hook_extra_identity(): void
    {
        $this->serve(self::URL, $this->bytes, null); // unsigned, our host, no identity
        $result = $this->gate()->verifyPackageSignature(false, self::URL, null, []);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_ignores_empty_package_url(): void
    {
        $this->assertFalse($this->gate()->verifyPackageSignature(false, '', null, ['plugin' => self::PLUGIN]));
    }
}
