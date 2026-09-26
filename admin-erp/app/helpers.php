<?php

use Illuminate\Support\Carbon;

/*
 * CROSS-004/ADM-013 date policy: admin's chrome is Bengali-first (see the
 * Phase 1 language pass), so admin's own date displays should be too, rather
 * than mixing Bengali labels with English "05 Sep 2026" dates. This is a
 * display-only concern — nothing here touches how dates are stored, queried,
 * or bound to <input type="date"> (those still use Y-m-d).
 *
 * Public and admin are allowed to differ in DETAIL (admin needs date+time for
 * audit trails; public only ever shows a date), but not in ARBITRARY
 * formatting — this is the one place both are defined, so any future change
 * to the policy is a one-line edit, not a per-view hunt. Documented alongside
 * the shared token system in DESIGN_SYSTEM.md.
 *
 * PHASE 3 (bilingual admin): these functions are now LOCALE-AWARE. The names
 * keep their `bn_` prefix — renaming them across ~98 views would be churn for
 * no behavioural gain — but each one now renders Bengali digits and Bengali
 * month names only while the admin UI locale is `bn`, and Western digits with
 * English month names under `en`. The policy above is unchanged; it simply
 * now has a second language.
 */

if (! function_exists('admin_locale_is_bn')) {
    /** The one place the locale branch is decided, so every helper below agrees. */
    function admin_locale_is_bn(): bool
    {
        return app()->getLocale() === 'bn';
    }
}

if (! function_exists('bn_digits')) {
    /** Bengali numerals under `bn`; unchanged Western numerals under `en`. */
    function bn_digits(string $value): string
    {
        if (! admin_locale_is_bn()) {
            return $value;
        }

        static $map = ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯'];

        return strtr($value, $map);
    }
}

if (! function_exists('bn_number')) {
    /** Locale-aware digits for an int/float coming from a count or total. */
    function bn_number(int|float|string|null $value): string
    {
        return bn_digits((string) ($value ?? 0));
    }
}

if (! function_exists('bn_month_name')) {
    function bn_month_name(int $month): string
    {
        static $bn = [
            1 => 'জানুয়ারি', 2 => 'ফেব্রুয়ারি', 3 => 'মার্চ', 4 => 'এপ্রিল',
            5 => 'মে', 6 => 'জুন', 7 => 'জুলাই', 8 => 'আগস্ট',
            9 => 'সেপ্টেম্বর', 10 => 'অক্টোবর', 11 => 'নভেম্বর', 12 => 'ডিসেম্বর',
        ];
        static $en = [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
        ];

        $names = admin_locale_is_bn() ? $bn : $en;

        return $names[$month] ?? '';
    }
}

/** "৫ সেপ্টেম্বর ২০২৬" / "5 September 2026" — date only, no time. */
if (! function_exists('bn_date')) {
    function bn_date(Carbon|string|null $value): string
    {
        if (! $value) {
            return '—';
        }
        $date = $value instanceof Carbon ? $value : Carbon::parse($value);

        return bn_digits((string) $date->day).' '.bn_month_name($date->month).' '.bn_digits((string) $date->year);
    }
}

/** "৫ সেপ্টেম্বর ২০২৬, ১৪:৩০" / "5 September 2026, 14:30" — date plus 24-hour time. */
if (! function_exists('bn_datetime')) {
    function bn_datetime(Carbon|string|null $value): string
    {
        if (! $value) {
            return '—';
        }
        $date = $value instanceof Carbon ? $value : Carbon::parse($value);

        return bn_date($date).', '.bn_digits($date->format('H:i'));
    }
}

/** "সেপ্টেম্বর ২০২৬" / "September 2026" — used for committee/membership term ranges. */
if (! function_exists('bn_month_year')) {
    function bn_month_year(Carbon|string|null $value): string
    {
        if (! $value) {
            return '—';
        }
        $date = $value instanceof Carbon ? $value : Carbon::parse($value);

        return bn_month_name($date->month).' '.bn_digits((string) $date->year);
    }
}

/*
 * ADM-002 status-label policy: every workflow-status slug used across the app
 * (activities, job postings, job applications, memberships, membership
 * applications, and the various active/inactive toggles) gets exactly ONE
 * display label per language. Phase 3 moved the label text itself into
 * lang/{bn,en}/statuses.php so the same slug renders in whichever language
 * the admin is reading; this function remains the single entry point that
 * `status-badge.blade.php` and every Model/Controller `STATUSES` constant's
 * display value are written from — a slug's label is never re-invented per
 * view.
 *
 * The slugs themselves ('active', 'draft', ...) are the real, unchanged
 * database values, form field values and Rule::in() targets. An unknown slug
 * falls back to a humanised form of itself rather than rendering blank.
 */
if (! function_exists('status_label')) {
    function status_label(string $status): string
    {
        $key = "statuses.{$status}";
        $label = __($key);

        return $label === $key ? ucwords(str_replace('_', ' ', $status)) : $label;
    }
}
