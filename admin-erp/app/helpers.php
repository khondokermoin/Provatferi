<?php

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
 *
 * 2026-10-08: the DATE helpers were replaced (bn_date/bn_datetime/bn_month_year → admin_datetime, calendar_date …),
 * because the old names did not say whether a value is a UTC timestamp or a calendar date — see the block below.
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

/*
 * DATES AND TIMES (2026-10-08, docs/DATES_AND_TIMES.md). The database keeps UTC; an admin reads Asia/Dhaka. Every
 * value shown is one of three kinds, and the helper names say which — the old bn_date()/bn_datetime() said nothing,
 * so timestamps were shown as UTC dates (an approval at 01:00 in Dhaka read as the previous day). All of them are thin
 * wrappers around App\Support\AdminTime:
 *
 *   TIMESTAMP (an instant in UTC: *_at)  admin_datetime() "৮ অক্টোবর ২০২৬, ০০:৩০" · admin_date() · admin_time() · admin_relative()
 *   DATE (a calendar day, never moved)   calendar_date() "৮ অক্টোবর ২০২৬" · calendar_month_year() "অক্টোবর ২০২৬"
 *   WALL CLOCK (typed Dhaka time, as     wallclock_datetime() · wallclock_date()   — an activity's start and end
 *   stored)
 */

/** A TIMESTAMP on the organisation's clock, date and 24-hour time: "৮ অক্টোবর ২০২৬, ০০:৩০" / "8 October 2026, 00:30". */
if (! function_exists('admin_datetime')) {
    function admin_datetime(\DateTimeInterface|string|null $timestamp): string
    {
        return \App\Support\AdminTime::dateTime($timestamp);
    }
}

/** The organisation's calendar day of a TIMESTAMP: "৮ অক্টোবর ২০২৬" / "8 October 2026". */
if (! function_exists('admin_date')) {
    function admin_date(\DateTimeInterface|string|null $timestamp): string
    {
        return \App\Support\AdminTime::date($timestamp);
    }
}

/** The organisation's 24-hour time of a TIMESTAMP: "০০:৩০" / "00:30". */
if (! function_exists('admin_time')) {
    function admin_time(\DateTimeInterface|string|null $timestamp): string
    {
        return \App\Support\AdminTime::time($timestamp);
    }
}

/** A TIMESTAMP relative to now: "৩ মিনিট আগে" / "3 minutes ago". */
if (! function_exists('admin_relative')) {
    function admin_relative(\DateTimeInterface|string|null $timestamp): string
    {
        return \App\Support\AdminTime::relative($timestamp);
    }
}

/** A calendar DATE exactly as stored, never converted: "৮ অক্টোবর ২০২৬" / "8 October 2026". */
if (! function_exists('calendar_date')) {
    function calendar_date(\DateTimeInterface|string|null $date): string
    {
        return \App\Support\AdminTime::calendarDate($date);
    }
}

/** A calendar DATE's month and year, as stored: "অক্টোবর ২০২৬" / "October 2026" — committee and membership terms. */
if (! function_exists('calendar_month_year')) {
    function calendar_month_year(\DateTimeInterface|string|null $date): string
    {
        return \App\Support\AdminTime::calendarMonthYear($date);
    }
}

/** A WALL-CLOCK date-time typed in Dhaka time and stored as typed (an activity), with its time, never converted. */
if (! function_exists('wallclock_datetime')) {
    function wallclock_datetime(\DateTimeInterface|string|null $value): string
    {
        return \App\Support\AdminTime::wallClockDateTime($value);
    }
}

/** The day of a WALL-CLOCK date-time, never converted. */
if (! function_exists('wallclock_date')) {
    function wallclock_date(\DateTimeInterface|string|null $value): string
    {
        return \App\Support\AdminTime::wallClockDate($value);
    }
}

/** "৳৫০০" / "৳500", "৳১,৫০০" / "৳1,500" — a membership fee. Locale-aware digits like every helper above; decimals only when present. */
if (! function_exists('bn_money')) {
    function bn_money(?string $amount): string
    {
        return bn_digits(\App\Support\Money::display($amount));
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

/**
 * Builds a status <select> options array (slug => label) from a Model's own
 * STATUSES constant, but sourcing every label through status_label() instead
 * of the constant's own hardcoded value. Phase 3: a handful of models'
 * STATUSES constants had drifted from the canonical statuses.php wording
 * (e.g. Committee's 'archived' said "আর্কাইভ" while the badge — which already
 * went through status_label() — said "সংরক্ষিত" for the same slug). This
 * closes that gap rather than adding a second one: the constant's KEY SET is
 * still the source of truth for validation (Rule::in(array_keys(...))), only
 * the displayed label now always matches the badge, in whichever language
 * the admin is reading.
 *
 * @param  array<string, string>|array<int, string>  $keys  A STATUSES constant, or a plain list of slugs.
 * @return array<string, string>
 */
if (! function_exists('status_options')) {
    function status_options(array $keys): array
    {
        $slugs = array_is_list($keys) ? $keys : array_keys($keys);

        return collect($slugs)->mapWithKeys(fn (string $slug) => [$slug => status_label($slug)])->all();
    }
}

/**
 * The non-status counterpart to status_label()/status_options(): labels for
 * Model "option" constants (notice types, employment types, ...) sourced
 * from lang/{bn,en}/options.php. ADMIN-SIDE ONLY — see that file's own
 * docblock for why several of the constants it mirrors must never have
 * their own raw value or public-API usage touched.
 */
if (! function_exists('option_label')) {
    function option_label(string $group, string $key): string
    {
        $catalogKey = "options.{$group}.{$key}";
        $label = __($catalogKey);

        return $label === $catalogKey ? ucwords(str_replace('_', ' ', $key)) : $label;
    }
}

/** @param  array<string, mixed>|array<int, string>  $keys  A label-map constant, or a plain list of keys. */
if (! function_exists('option_options')) {
    function option_options(string $group, array $keys): array
    {
        $slugs = array_is_list($keys) ? $keys : array_keys($keys);

        return collect($slugs)->mapWithKeys(fn (string $slug) => [$slug => option_label($group, $slug)])->all();
    }
}

/**
 * PhotoUploadService, ApplicationDocumentService and NoticeFileService are
 * shared with public API controllers (public committee registration,
 * membership application, volunteer application, member profile) serving
 * provatferi.org, where error text must stay Bangla per Phase 2's documented
 * "validation messages are not locale-aware" limitation — so those services
 * always throw a raw Bangla RuntimeException message and are never touched
 * to call __() themselves. This is the one place an ADMIN controller (e.g.
 * NoticeController/RecruitmentController catching that same exception to
 * show the admin user) converts a recognised message to English under the
 * `en` admin locale. An unrecognised message — a future wording change in
 * one of the services — is returned unchanged rather than silently dropped.
 */
if (! function_exists('upload_error_label')) {
    function upload_error_label(string $message): string
    {
        if (admin_locale_is_bn()) {
            return $message;
        }

        static $map = [
            'সিভির আকার সর্বোচ্চ ৫ MB হতে পারে।' => 'The CV can be at most 5 MB.',
            'শুধুমাত্র PDF ফাইল গ্রহণযোগ্য।' => 'Only PDF files are accepted.',
            'ফাইলটি পড়া যায়নি।' => 'The file could not be read.',
            'ফাইলটি একটি বৈধ PDF নয়।' => 'The file is not a valid PDF.',
            'ফাইল সংরক্ষণ করা যায়নি।' => 'The file could not be saved.',
            'সংযুক্তির আকার সর্বোচ্চ ১০ MB হতে পারে।' => 'The attachment can be at most 10 MB.',
            'শুধুমাত্র PDF সংযুক্তি গ্রহণযোগ্য।' => 'Only PDF attachments are accepted.',
            'সংযুক্তি সংরক্ষণ করা যায়নি।' => 'The attachment could not be saved.',
            'ফাইলের আকার সর্বোচ্চ সীমার চেয়ে বড়।' => 'The file size exceeds the maximum allowed.',
            'শুধুমাত্র JPG, JPEG, PNG বা WEBP ফাইল গ্রহণযোগ্য।' => 'Only JPG, JPEG, PNG, or WEBP files are accepted.',
            'ফাইলটি একটি বৈধ ছবি নয়।' => 'The file is not a valid image.',
            'ছবিটি ক্ষতিগ্রস্ত বা অসম্পূর্ণ।' => 'The image is corrupted or incomplete.',
            'ছবি সংরক্ষণ করা যায়নি।' => 'The image could not be saved.',
            'মূল ছবিটি খুঁজে পাওয়া যায়নি।' => 'The original image could not be found.',
        ];

        return $map[$message] ?? $message;
    }
}

/**
 * Absolute filesystem root for the 'public' disk (approved public uploads).
 *
 * Called from config/filesystems.php, so it must work with nothing but
 * Composer's autoloader loaded — no facades, no container, no config().
 *
 * Why this exists instead of storage_path('app/public'): symlink() is in
 * this host's php.ini disable_functions, so `storage:link` can never run
 * here and the stock layout is permanently unreachable over HTTP. The live
 * vhost docroot (public_html/admin) is used instead — it is web-served
 * directly and, unlike public_path(), is never renamed by the atomic release
 * switch, so uploads persist across deploys with no sync step. See the
 * 'public' disk comment in config/filesystems.php for the full rationale.
 *
 * Resolution order:
 *   1. PUBLIC_UPLOADS_ROOT — set explicitly by release-manager.php's `stage`
 *      on every production release; authoritative when present.
 *   2. Auto-detected production docroot, as a safety net if that env var is
 *      ever missing: <laravel-admin>/../public_html/admin/storage, used only
 *      when public_html/admin actually exists on disk.
 *   3. storage_path('app/public') — local dev, where storage:link works.
 */
if (! function_exists('public_uploads_root')) {
    function public_uploads_root(): string
    {
        $explicit = $_ENV['PUBLIC_UPLOADS_ROOT'] ?? $_SERVER['PUBLIC_UPLOADS_ROOT'] ?? getenv('PUBLIC_UPLOADS_ROOT');
        if (is_string($explicit) && $explicit !== '') {
            return rtrim($explicit, '/\\');
        }

        $base = \dirname(__DIR__); // app/ -> Laravel base path
        $docroot = \dirname($base).'/public_html/admin';
        if (is_dir($docroot)) {
            return $docroot.'/storage';
        }

        return $base.'/storage/app/public';
    }
}
