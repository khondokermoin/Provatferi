<?php

namespace App\Support;

/**
 * One comparable form for a phone number, so the same mobile written two ways is recognised as one when approval looks
 * for an existing member (and so members.phone, which is UNIQUE, stores one spelling). Bangladeshi mobiles arrive as
 * "01712-345678", "+880 1712 345678", "8801712345678" or "1712345678"; all of them become "01712345678". Anything that
 * is not recognisably Bangladeshi keeps its digits as they are (a leading "+" is dropped with the other punctuation).
 */
final class PhoneNumber
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return null;
        }

        // +880 1XXXXXXXXX — the country code, then the national number without its trunk 0. ("+88 01XXXXXXXXX", the
        // common local way of writing it, is the very same 13 digits.)
        if (strlen($digits) === 13 && str_starts_with($digits, '880')) {
            return '0'.substr($digits, 3);
        }
        // the national number without its trunk 0
        if (strlen($digits) === 10 && str_starts_with($digits, '1')) {
            return '0'.$digits;
        }

        return $digits;
    }
}
