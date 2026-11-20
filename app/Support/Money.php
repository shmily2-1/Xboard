<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    /** Convert a validated major-unit price to integer cents with decimal half-up rounding. */
    public static function toCents(int|float|string $amount): int
    {
        if (is_int($amount)) {
            return $amount * 100;
        }

        // Keep decimal strings exact; binary floats such as 1.005 cannot be
        // multiplied and rounded reliably without first converting to decimal text.
        $raw = is_string($amount) ? trim($amount) : sprintf('%.14F', $amount);
        if (!preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $raw, $matches)) {
            if (!is_numeric($raw)) {
                throw new InvalidArgumentException('Amount must be numeric.');
            }
            $raw = sprintf('%.14F', (float) $raw);
            preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $raw, $matches);
        }

        $sign = ($matches[1] ?? '') === '-' ? -1 : 1;
        $whole = (int) ltrim($matches[2], '0');
        $fraction = str_pad($matches[3] ?? '', 3, '0');
        $cents = ($whole * 100) + (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            ++$cents;
        }

        return $sign * $cents;
    }
}
