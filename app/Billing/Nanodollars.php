<?php

namespace App\Billing;

use InvalidArgumentException;

/**
 * Money is stored as an integer, to avoid having to calculate a float.  This will preserve the
 * accuracy and allow the 9 decimal places.
 *
 * Money is stored as integer nano-dollars (1 USD = 1,000,000,000)
 */
final class Nanodollars
{
    public const PER_DOLLAR = 1_000_000_000;

    /**
     * Convert an exact decimal string such as "0.046125000" or "12.50" to nano-dollars.
     */
    public static function fromDecimal(string $amount): int
    {
        // regex matches if the string is already in nano-dollars
        if (! is_numeric($amount) || ! preg_match('/^-?\d+(\.\d{1,9})?$/', $amount)) {
            throw new InvalidArgumentException("Invalid USD amount [{$amount}].");
        }

        return (int) bcmul($amount, (string) self::PER_DOLLAR, 0);
    }

    /**
     * Convert money to decimal in Pixelforge's format.
     */
    public static function toDecimal(int $nanos): string
    {
        // divide the float numbers
        return bcdiv((string) $nanos, (string) self::PER_DOLLAR, 9);
    }

    /**
     * Divide, rounding up. Used whenever we must round so that we never under-charge.
     */
    public static function divideRoundUp(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
