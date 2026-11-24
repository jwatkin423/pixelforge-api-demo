<?php

namespace App\Billing;

use InvalidArgumentException;

/**
 * Money is stored as integer nanodollars (1 USD = 1,000,000,000) so that Pixelforge's
 * nine-decimal prices are represented exactly and never touch floating point.
 */
final class Nanodollars
{
    public const PER_DOLLAR = 1_000_000_000;

    /**
     * Convert an exact decimal string such as "0.046125000" or "12.50" to nanodollars.
     */
    public static function fromDecimal(string $amount): int
    {
        if (! is_numeric($amount) || ! preg_match('/^-?\d+(\.\d{1,9})?$/', $amount)) {
            throw new InvalidArgumentException("Invalid USD amount [{$amount}].");
        }

        return (int) bcmul($amount, (string) self::PER_DOLLAR, 0);
    }

    /**
     * Convert nanodollars to an exact nine-decimal string, matching Pixelforge's format.
     */
    public static function toDecimal(int $nanos): string
    {
        return bcdiv((string) $nanos, (string) self::PER_DOLLAR, 9);
    }

    /**
     * Divide, rounding up. Used whenever we must round so that we never under-charge.
     */
    public static function ceilDiv(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
