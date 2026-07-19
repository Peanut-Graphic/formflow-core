<?php

namespace Peanut\FormCore\Tests;

use Peanut\FormCore\Crypto\EncryptionKeyNotice;
use PHPUnit\Framework\TestCase;

/**
 * Pins the key-status contract that both tiers now share.
 *
 * This capability existed only in FormFlow Lite (free) and was absent from Pro
 * (paid) — a tier inversion, since paying users got less warning about their
 * own data-at-rest key. Shared here so both get it from one place.
 */
final class EncryptionKeyNoticeTest extends TestCase
{
    public function test_warns_when_the_key_constant_is_not_defined(): void
    {
        $status = EncryptionKeyNotice::keyStatus('FFCORE_TEST_KEY_UNDEFINED');
        $this->assertSame('warning', $status['status']);
        $this->assertSame('key_not_defined', $status['code']);
        $this->assertStringContainsString('FFCORE_TEST_KEY_UNDEFINED', $status['message']);
    }

    public function test_errors_when_the_key_is_too_short_for_aes256(): void
    {
        define('FFCORE_TEST_KEY_SHORT', str_repeat('a', 31));
        $status = EncryptionKeyNotice::keyStatus('FFCORE_TEST_KEY_SHORT');
        $this->assertSame('error', $status['status']);
        $this->assertSame('key_too_short', $status['code']);
    }

    public function test_ok_at_exactly_the_minimum_length(): void
    {
        // Boundary: 32 is valid for AES-256, so it must NOT be reported short.
        define('FFCORE_TEST_KEY_OK', str_repeat('a', EncryptionKeyNotice::MIN_KEY_LENGTH));
        $status = EncryptionKeyNotice::keyStatus('FFCORE_TEST_KEY_OK');
        $this->assertSame('ok', $status['status']);
        $this->assertSame('key_ok', $status['code']);
    }

    public function test_generated_key_is_long_enough_to_satisfy_its_own_check(): void
    {
        // A generator that emits keys its own validator rejects would be a
        // nasty trap in the admin notice ("paste this" -> still an error).
        $key = EncryptionKeyNotice::generateKey();
        $this->assertGreaterThanOrEqual(EncryptionKeyNotice::MIN_KEY_LENGTH, strlen($key));
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $key);
        $this->assertNotSame(EncryptionKeyNotice::generateKey(), $key, 'keys must not repeat');
    }
}
