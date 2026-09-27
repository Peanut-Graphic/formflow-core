<?php

namespace Peanut\FormCore\Tests;

use Peanut\FormCore\Crypto\Encryptor;
use PHPUnit\Framework\TestCase;

/**
 * The authenticated V2 format (XChaCha20-Poly1305, HKDF key over the full key
 * material) and its phase-1 rollout: read both formats, write legacy unless
 * authenticated writes are enabled, migrate lazily via needsReencrypt().
 */
final class EncryptorV2Test extends TestCase
{
    private const KEY_CONST = 'a-configured-encryption-key-that-is-long-enough-32+';

    private static function writer(?string $constant = self::KEY_CONST, string $salt = 'site-salt'): Encryptor
    {
        return Encryptor::fromKeyMaterial($constant, $salt, true);
    }

    private static function reader(?string $constant = self::KEY_CONST, string $salt = 'site-salt'): Encryptor
    {
        return Encryptor::fromKeyMaterial($constant, $salt);
    }

    public function test_authenticated_writes_produce_the_v2_format_and_round_trip(): void
    {
        $stored = self::writer()->encrypt('sftp-password');

        $this->assertStringStartsWith('ffc2:', $stored);
        $this->assertSame('sftp-password', self::writer()->decrypt($stored));
    }

    public function test_a_phase_one_reader_decrypts_v2_so_a_rollback_to_it_loses_nothing(): void
    {
        $this->assertSame('api-token', self::reader()->decrypt(self::writer()->encrypt('api-token')));
    }

    public function test_phase_one_default_still_writes_the_legacy_format(): void
    {
        $this->assertStringStartsNotWith('ffc2:', self::reader()->encrypt('x'));
    }

    public function test_v2_reads_values_written_in_the_legacy_format(): void
    {
        $this->assertSame('old-secret', self::writer()->decrypt(self::reader()->encrypt('old-secret')));
    }

    public function test_tampering_with_any_byte_fails_closed(): void
    {
        $stored  = self::writer()->encrypt('account-number-123456');
        $payload = base64_decode(substr($stored, 5), true);

        for ($i = 0; $i < strlen($payload); $i += 7) {
            $tampered = $payload;
            $tampered[$i] = chr(ord($tampered[$i]) ^ 0x01);

            $this->assertSame('', self::writer()->decrypt('ffc2:' . base64_encode($tampered)), "byte {$i}");
        }
    }

    public function test_a_different_key_cannot_decrypt(): void
    {
        $stored = self::writer()->encrypt('secret');

        $this->assertSame('', self::writer('another-configured-encryption-key-32chars+')->decrypt($stored));
    }

    public function test_v2_key_uses_the_whole_constant_not_its_first_32_bytes(): void
    {
        // Legacy truncation made these two constants the same key.
        $a = self::KEY_CONST . '-suffix-one';
        $b = self::KEY_CONST . '-suffix-two';
        $this->assertSame(Encryptor::deriveKey($a, ''), Encryptor::deriveKey($b, ''));

        $this->assertSame('', self::writer($b)->decrypt(self::writer($a)->encrypt('secret')));
    }

    public function test_v2_key_uses_the_whole_wp_salt_when_no_constant_is_set(): void
    {
        $stored = self::writer(null, 'salt-one')->encrypt('secret');

        $this->assertSame('secret', self::writer(null, 'salt-one')->decrypt($stored));
        $this->assertSame('', self::writer(null, 'salt-two')->decrypt($stored));
    }

    public function test_nonces_are_unique_per_write(): void
    {
        $writer = self::writer();

        $this->assertNotSame($writer->encrypt('same'), $writer->encrypt('same'));
    }

    public function test_malformed_v2_values_fail_closed(): void
    {
        foreach (['ffc2:', 'ffc2:!!!not-base64', 'ffc2:' . base64_encode('too-short')] as $bad) {
            $this->assertSame('', self::writer()->decrypt($bad), $bad);
        }
    }

    public function test_needs_reencrypt_flags_only_legacy_values_under_authenticated_writes(): void
    {
        $legacy = self::reader()->encrypt('x');
        $v2     = self::writer()->encrypt('x');

        $this->assertTrue(self::writer()->needsReencrypt($legacy));
        $this->assertFalse(self::writer()->needsReencrypt($v2));
        $this->assertFalse(self::writer()->needsReencrypt(''));
        $this->assertFalse(self::reader()->needsReencrypt($legacy));
    }

    public function test_arrays_round_trip_through_v2(): void
    {
        $data = ['host' => 'sftp.example.com', 'password' => 'p@ss', 'port' => 22];

        $this->assertSame($data, self::writer()->decryptArray(self::writer()->encryptArray($data)));
    }

    public function test_the_plain_constructor_keeps_working_for_existing_callers(): void
    {
        $legacyKey = Encryptor::deriveKey(self::KEY_CONST, 'site-salt');
        $old = new Encryptor($legacyKey);
        $new = new Encryptor($legacyKey, null, true);

        $this->assertSame('v', $old->decrypt($new->encrypt('v')));
        $this->assertSame('v', $new->decrypt($old->encrypt('v')));
    }
}
