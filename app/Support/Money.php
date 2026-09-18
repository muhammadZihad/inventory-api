<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Converts monetary values between API dollars and calculation-safe cents.
 *
 * All arithmetic in the application happens in integer cents; dollars are a
 * boundary format used by the database columns, request payloads, and JSON
 * responses.
 */
class Money
{
    /**
     * Convert a dollar amount from requests, storage, or SQL aggregates into integer cents.
     *
     * Numeric inputs are normalized before parsing so that database aggregates
     * returned as floats (including large values that would otherwise render in
     * exponent notation) convert without error.
     */
    public static function dollarsToCents(string|int|float|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        $normalized = is_string($amount)
            ? trim($amount)
            : number_format((float) $amount, 2, '.', '');

        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $normalized)) {
            throw new InvalidArgumentException('Invalid monetary amount.');
        }

        $negative = str_starts_with($normalized, '-');
        [$dollars, $cents] = array_pad(explode('.', ltrim($normalized, '-'), 2), 2, '0');
        $cents = str_pad(substr($cents, 0, 2), 2, '0');

        $total = ((int) $dollars * 100) + (int) $cents;

        return $negative ? -$total : $total;
    }

    /**
     * Convert integer cents into a two-decimal dollar string for storage or output.
     */
    public static function centsToDollars(int|string|null $cents): string
    {
        $cents = (int) ($cents ?? 0);

        return number_format($cents / 100, 2, '.', '');
    }
}
