<?php

use Illuminate\Support\Carbon;

/*
 * CROSS-004/ADM-013 date policy: admin's chrome is Bengali-first (see the
 * Phase 1 language pass), so admin's own date displays should be too,
 * rather than mixing Bengali labels with English "05 Sep 2026" dates. This
 * is a display-only concern — nothing here touches how dates are stored,
 * queried, or bound to <input type="date"> (those still use Y-m-d).
 *
 * Public and admin are allowed to differ in DETAIL (admin needs
 * date+time for audit trails; public only ever shows a date), but not in
 * ARBITRARY formatting — this is the one place both are defined, so any
 * future change to the policy is a one-line edit, not a per-view hunt.
 * Documented alongside the shared token system in DESIGN_SYSTEM.md.
 */

if (! function_exists('bn_digits')) {
    function bn_digits(string $value): string
    {
        static $map = ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯'];

        return strtr($value, $map);
    }
}

if (! function_exists('bn_month_name')) {
    function bn_month_name(int $month): string
    {
        static $names = [
            1 => 'জানুয়ারি', 2 => 'ফেব্রুয়ারি', 3 => 'মার্চ', 4 => 'এপ্রিল',
            5 => 'মে', 6 => 'জুন', 7 => 'জুলাই', 8 => 'আগস্ট',
            9 => 'সেপ্টেম্বর', 10 => 'অক্টোবর', 11 => 'নভেম্বর', 12 => 'ডিসেম্বর',
        ];

        return $names[$month] ?? '';
    }
}

/** "5 সেপ্টেম্বর ২০২৬" — date only, no time. */
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

/** "5 সেপ্টেম্বর ২০২৬, ১৪:৩০" — date plus 24-hour time. */
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

/** "সেপ্টেম্বর ২০২৬" — used for committee/membership term ranges. */
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
 * ADM-002 status-label policy: every workflow-status slug used across the
 * app (activities, job postings, job applications, memberships, membership
 * applications, and the various active/inactive toggles) gets exactly ONE
 * Bengali display label, defined here once. This is the single source both
 * `status-badge.blade.php` and every Model/Controller `STATUSES` constant's
 * display value are written from — a slug's label never gets re-invented
 * per view. The slugs themselves ('active', 'draft', ...) are the real,
 * unchanged database values, form field values, and Rule::in() targets —
 * only the Bengali text shown to a human ever comes from this map.
 */
if (! function_exists('status_label')) {
    function status_label(string $status): string
    {
        static $labels = [
            'active' => 'সক্রিয়',
            'inactive' => 'নিষ্ক্রিয়',
            'draft' => 'খসড়া',
            'published' => 'প্রকাশিত',
            'archived' => 'সংরক্ষিত',
            'open' => 'খোলা',
            'closed' => 'বন্ধ',
            'suspended' => 'স্থগিত',
            'expired' => 'মেয়াদোত্তীর্ণ',
            'pending' => 'পর্যালোচনার অপেক্ষায়',
            'under_review' => 'পর্যালোচনাধীন',
            'need_information' => 'তথ্য প্রয়োজন',
            'approved' => 'অনুমোদিত',
            'rejected' => 'প্রত্যাখ্যাত',
            'cancelled' => 'বাতিল',
            'submitted' => 'জমাকৃত',
            'shortlisted' => 'বাছাইকৃত',
            'selected' => 'নির্বাচিত',
        ];

        return $labels[$status] ?? ucwords(str_replace('_', ' ', $status));
    }
}
