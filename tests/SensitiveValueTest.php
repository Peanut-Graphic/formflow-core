<?php

namespace Peanut\FormCore\Tests;

use Peanut\FormCore\Crypto\SensitiveValue;
use PHPUnit\Framework\TestCase;

/**
 * Pins the masking contract — including the substr(-0) whole-value leak that
 * FormFlow Pro and Lite each discovered and fixed separately.
 */
final class SensitiveValueTest extends TestCase
{
    public function test_masks_middle_and_reveals_requested_windows(): void
    {
        $this->assertSame('****5678', SensitiveValue::mask('12345678', 0, 4));
        $this->assertSame('12****78', SensitiveValue::mask('12345678', 2, 2));
        $this->assertSame('1234****', SensitiveValue::mask('12345678', 4, 0));
    }

    /**
     * THE regression: substr($data, -0) returns the WHOLE string in PHP, so a
     * "reveal nothing at the end" mask used to leak the entire value.
     */
    public function test_visible_end_zero_reveals_nothing(): void
    {
        $masked = SensitiveValue::mask('4111111111111111', 0, 0);
        $this->assertSame(str_repeat('*', 16), $masked);
        $this->assertStringNotContainsString('4111', $masked);
    }

    public function test_negative_windows_are_treated_as_zero(): void
    {
        // Must not produce a longer/garbled string or leak via negative maths.
        $this->assertSame(str_repeat('*', 8), SensitiveValue::mask('12345678', -5, -5));
    }

    public function test_short_values_are_fully_masked_rather_than_partly_revealed(): void
    {
        // Failing toward less disclosure: too short to mask meaningfully -> hide all.
        $this->assertSame('****', SensitiveValue::mask('1234', 0, 4));
        $this->assertSame('***', SensitiveValue::mask('123', 2, 2));
        $this->assertSame('', SensitiveValue::mask(''));
    }

    public function test_mask_length_always_matches_input_length(): void
    {
        foreach (['a', 'ab', 'abcd', 'abcdefghij', str_repeat('x', 64)] as $value) {
            foreach ([[0, 0], [0, 4], [2, 2], [4, 0], [1, 1]] as [$s, $e]) {
                $this->assertSame(
                    strlen($value),
                    strlen(SensitiveValue::mask($value, $s, $e)),
                    "mask changed length for '$value' ($s,$e)"
                );
            }
        }
    }

    public function test_hash_is_sha256_and_verifies(): void
    {
        $this->assertSame(hash('sha256', 'secret'), SensitiveValue::hash('secret'));
        $this->assertTrue(SensitiveValue::verifyHash('secret', SensitiveValue::hash('secret')));
        $this->assertFalse(SensitiveValue::verifyHash('secret', SensitiveValue::hash('other')));
        $this->assertFalse(SensitiveValue::verifyHash('secret', ''));
    }
}
