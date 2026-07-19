<?php
/**
 * Pure helpers for handling sensitive values — masking for display and one-way
 * hashing for comparison.
 *
 * Extracted from the copies in FormFlow Pro and FormFlow Lite, which had
 * DIVERGED: both repos independently discovered the same `substr($data, -0)`
 * bug — PHP treats -0 as 0, so a "show no trailing characters" mask returned
 * the ENTIRE value — and each shipped a different fix. That is exactly the cost
 * A6 describes: one bug, found twice, fixed twice, in two places, with no
 * guarantee the next fix reaches both.
 *
 * Everything here is static and side-effect free (no WordPress), so the
 * behaviour is provable in isolation.
 *
 * @package Peanut\FormCore
 * @since 0.3.0
 */

namespace Peanut\FormCore\Crypto;

if (!defined('ABSPATH')) {
    exit;
}

final class SensitiveValue
{
    /**
     * Mask a value for display, keeping a few characters visible at each end.
     *
     * When the value is too short to mask meaningfully (i.e. the visible
     * windows would cover it entirely) the whole thing is masked rather than
     * partially revealed — failing toward less disclosure, not more.
     *
     * @param string $data          Value to mask.
     * @param int    $visibleStart  Characters to reveal at the start.
     * @param int    $visibleEnd    Characters to reveal at the end.
     * @return string
     */
    public static function mask(string $data, int $visibleStart = 0, int $visibleEnd = 4): string
    {
        // Negative windows are meaningless and would corrupt the arithmetic
        // below; treat them as "reveal nothing".
        $visibleStart = max(0, $visibleStart);
        $visibleEnd   = max(0, $visibleEnd);

        $length = strlen($data);
        if ($length <= ($visibleStart + $visibleEnd)) {
            return str_repeat('*', $length);
        }

        $start = $visibleStart > 0 ? substr($data, 0, $visibleStart) : '';

        // Guard the -0 trap explicitly: substr($data, -0) === substr($data, 0)
        // === the whole string, which would leak everything precisely when the
        // caller asked for nothing to be visible.
        // nosemgrep: peanut-negative-length-substr -- only evaluated when $visibleEnd > 0
        $end = $visibleEnd > 0 ? substr($data, -$visibleEnd) : '';

        $middle = str_repeat('*', $length - $visibleStart - $visibleEnd);

        return $start . $middle . $end;
    }

    /**
     * One-way hash for comparison.
     */
    public static function hash(string $data): string
    {
        return hash('sha256', $data);
    }

    /**
     * Constant-time verification of a value against its hash.
     */
    public static function verifyHash(string $data, string $hash): bool
    {
        return hash_equals($hash, self::hash($data));
    }
}
