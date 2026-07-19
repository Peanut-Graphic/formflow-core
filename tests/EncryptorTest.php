<?php

namespace Peanut\FormCore\Tests;

use Peanut\FormCore\Crypto\Encryptor;
use PHPUnit\Framework\TestCase;

/**
 * The headline requirement for this class is NOT "does it round-trip itself" —
 * it is "does it still read what the OLD code wrote". Stored records were
 * written by the plugins' previous implementation; if the extracted version
 * derives a different key or changes the envelope, every one of those becomes
 * permanently unreadable.
 *
 * So the legacy algorithm is reproduced verbatim below and the two are proven
 * interchangeable in BOTH directions.
 */
final class EncryptorTest extends TestCase
{
    private const KEY_CONST = 'a-configured-encryption-key-that-is-long-enough-32+';

    /** Verbatim copy of the pre-extraction plugin implementation. */
    private static function legacyKey(?string $constant, string $salt): string
    {
        if ($constant !== null && strlen($constant) >= 32) {
            return substr($constant, 0, 32);
        }
        return substr(hash('sha256', $salt), 0, 32);
    }

    private static function legacyEncrypt(string $data, string $key): string
    {
        if (empty($data)) { return ''; }
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    private static function legacyDecrypt(string $data, string $key): string
    {
        if (empty($data)) { return ''; }
        $decoded = base64_decode($data, true);
        if ($decoded === false) { return ''; }
        $iv = substr($decoded, 0, 16);
        $encrypted = substr($decoded, 16);
        if (strlen($iv) !== 16) { return ''; }
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $decrypted !== false ? $decrypted : '';
    }

    /** @dataProvider payloads */
    public function test_decrypts_data_written_by_the_legacy_implementation(string $plaintext): void
    {
        // THE regression that matters: stored records must still be readable.
        $key       = self::legacyKey(self::KEY_CONST, 'irrelevant');
        $stored    = self::legacyEncrypt($plaintext, $key);
        $encryptor = new Encryptor(Encryptor::deriveKey(self::KEY_CONST, 'irrelevant'));

        $this->assertSame($plaintext, $encryptor->decrypt($stored));
    }

    /** @dataProvider payloads */
    public function test_legacy_implementation_can_read_new_output(string $plaintext): void
    {
        // The other direction, so a partial rollout (some code new, some old)
        // cannot corrupt reads either.
        $encryptor = new Encryptor(Encryptor::deriveKey(self::KEY_CONST, 'irrelevant'));
        $written   = $encryptor->encrypt($plaintext);
        $key       = self::legacyKey(self::KEY_CONST, 'irrelevant');

        $this->assertSame($plaintext, self::legacyDecrypt($written, $key));
    }

    public static function payloads(): array
    {
        return [
            'plain'      => ['hello world'],
            'unicode'    => ['héllo wörld 日本語 🎉'],
            'multiline'  => ["line one\nline two\r\nthree"],
            'special'    => ['!@#$%^&*()_+-=[]{}|;:",.<>?/\\'],
            'json'       => ['{"account":"1234567890","pin":"0000"}'],
            'long'       => [str_repeat('abcdefghij', 500)],
            'exact-block'=> [str_repeat('x', 16)],  // exactly one AES block
            'block-plus' => [str_repeat('x', 17)],
        ];
    }

    public function test_key_derivation_matches_legacy_on_both_branches(): void
    {
        // Configured key is TRUNCATED, salt fallback is HASHED — swapping which
        // branch hashes would invalidate every stored record.
        $this->assertSame(
            self::legacyKey(self::KEY_CONST, 'salt'),
            Encryptor::deriveKey(self::KEY_CONST, 'salt'),
            'configured-key branch drifted'
        );
        $this->assertSame(
            self::legacyKey(null, 'some-wp-salt'),
            Encryptor::deriveKey(null, 'some-wp-salt'),
            'salt-fallback branch drifted'
        );
        // A too-short constant must fall through to the salt, not be padded.
        $this->assertSame(
            self::legacyKey('too-short', 'some-wp-salt'),
            Encryptor::deriveKey('too-short', 'some-wp-salt'),
            'short-key fallthrough drifted'
        );
    }

    public function test_derived_key_is_always_32_bytes(): void
    {
        $this->assertSame(32, strlen(Encryptor::deriveKey(self::KEY_CONST, 's')));
        $this->assertSame(32, strlen(Encryptor::deriveKey(null, 's')));
        $this->assertSame(32, strlen(Encryptor::deriveKey('short', 's')));
    }

    public function test_fresh_iv_per_call_so_equal_values_do_not_look_equal(): void
    {
        $e = new Encryptor(Encryptor::deriveKey(self::KEY_CONST, 's'));
        $this->assertNotSame($e->encrypt('same'), $e->encrypt('same'));
    }

    public function test_empty_and_garbage_are_handled_without_throwing(): void
    {
        $e = new Encryptor(Encryptor::deriveKey(self::KEY_CONST, 's'));
        $this->assertSame('', $e->encrypt(''));
        $this->assertSame('', $e->decrypt(''));
        $this->assertSame('', $e->decrypt('!!!not-base64!!!'));
        $this->assertSame('', $e->decrypt(base64_encode('short')));
        $this->assertSame('', $e->decrypt(base64_encode(str_repeat('x', 40))), 'undecryptable payload');
    }

    public function test_wrong_key_does_not_decrypt(): void
    {
        $written = (new Encryptor(Encryptor::deriveKey(self::KEY_CONST, 's')))->encrypt('secret');
        $other   = new Encryptor(Encryptor::deriveKey('a-completely-different-key-32-chars-long!', 's'));
        $this->assertSame('', $other->decrypt($written));
    }

    public function test_array_round_trip_and_failure_modes(): void
    {
        $e = new Encryptor(Encryptor::deriveKey(self::KEY_CONST, 's'));
        $data = ['name' => 'Ada', 'tags' => ['x', 'y'], 'n' => 42, 'nested' => ['deep' => true]];
        $this->assertSame($data, $e->decryptArray($e->encryptArray($data)));
        $this->assertSame([], $e->decryptArray(''));
        $this->assertSame([], $e->decryptArray('garbage'));
        $this->assertSame([], $e->decryptArray($e->encrypt('not-json')));
    }
}
