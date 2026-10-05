<?php

namespace App\Support;

/**
 * Money handled as DECIMAL STRINGS, never as floats.
 *
 * Fees are stored as decimal(10,2) and travel as strings ("500.00") from the database to the API to the page, so no
 * value is ever turned into a binary float on the way (0.1 + 0.2 is not 0.3 there). There is deliberately no
 * arithmetic here — nothing in the fee model adds or multiplies amounts; the only operations needed are "is this a
 * valid amount", "is it more than zero" and "are these two the same".
 */
final class Money
{
    /** The largest value a decimal(10,2) column holds. */
    public const MAX = '99999999.99';

    /**
     * Normalises an input to a two-decimal string ("500" -> "500.00", "12.5" -> "12.50"), or null when it is not a
     * plain, non-negative amount with at most two decimal places and at most eight integer digits. Strict on purpose:
     * "1e3", "-0", " 5 ", "5,00", "0.125" and "" are all refused rather than guessed at or rounded.
     */
    public static function parse(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }
            $value = (string) $value; // PHP prints the shortest round-trip form: 12.5 -> "12.5", 1.0E-5 -> "1.0E-5" (refused below)
        }

        if (! is_string($value)) {
            return null;
        }

        if (preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/', trim($value), $m) !== 1) {
            return null;
        }

        return (ltrim($m[1], '0') ?: '0').'.'.str_pad($m[2] ?? '', 2, '0');
    }

    /** True for any valid amount above zero. */
    public static function isPositive(?string $amount): bool
    {
        $normalised = $amount === null ? null : self::parse($amount);

        return $normalised !== null && preg_match('/[1-9]/', $normalised) === 1;
    }

    /** Value equality of two amounts however they were written ("5" equals "5.00"). */
    public static function equals(?string $a, ?string $b): bool
    {
        $a = $a === null ? null : self::parse($a);
        $b = $b === null ? null : self::parse($b);

        return $a !== null && $a === $b;
    }

    /**
     * "৳500", "৳1,500", "৳99.50" — taka sign, thousands grouped in threes, the decimals shown only when there are some
     * (a whole-taka fee reads "৳500", not "৳500.00"). Digits stay Western here; the admin helper bn_money() maps them
     * to Bengali digits for the Bangla UI.
     */
    public static function display(?string $amount): string
    {
        $normalised = $amount === null ? null : self::parse($amount);
        if ($normalised === null) {
            return '—';
        }

        [$whole, $fraction] = explode('.', $normalised);
        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));

        return '৳'.$grouped.($fraction === '00' ? '' : '.'.$fraction);
    }
}
