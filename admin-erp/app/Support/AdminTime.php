<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * THE ONE PLACE THE ADMIN TURNS DATES AND TIMES INTO TEXT (2026-10-08; docs/DATES_AND_TIMES.md).
 *
 * Storage does not change: the application runs in UTC (config('app.timezone')) and every timestamp column holds UTC.
 * What an admin READS — and every "today" of the organisation's calendar — is on the organisation's clock instead,
 * config('app.display_timezone') = Asia/Dhaka (UTC+6, no daylight saving).
 *
 * A value is one of three kinds, and each kind has its own entry point. They are never mixed:
 *
 *  TIMESTAMP   an instant, stored in UTC: created_at, approved_at, verified_at, published_at, last_login_at, a season's
 *              opens_at … — converted:  2026-10-07 18:30 UTC → "8 October 2026, 00:30".
 *              dateTime() · date() · time() · relative() · local()
 *  DATE        a day on the organisation's calendar, stored as a date: start_date, due_date, a payment's received_at,
 *              effective_from, a deadline … — shown exactly as stored, NEVER converted (converting a stored midnight
 *              could move it to another day).                 calendarDate() · calendarMonthYear()
 *  WALL CLOCK  a local date-time typed in Dhaka time and stored as typed (an activity's start and end) — shown exactly as
 *              stored, never converted.                       wallClockDateTime() · wallClockDate()
 *
 * Text follows the admin language: Bengali digits and month names under `bn`, Western digits and English month names
 * under `en` (bn_digits(), bn_month_name()). Times are 24-hour. Blade calls the global helpers in app/helpers.php
 * (admin_datetime(), calendar_date() …), which are thin wrappers around this class.
 */
final class AdminTime
{
    /** The organisation's timezone: every admin date and time is shown on this clock. */
    public static function timezone(): string
    {
        return (string) config('app.display_timezone', 'Asia/Dhaka');
    }

    /** Now, on the organisation's clock. */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    /** Today on the organisation's calendar, 'Y-m-d' — the default of a date field, the "today" of a filter. */
    public static function today(): string
    {
        return self::now()->toDateString();
    }

    /** This year on the organisation's calendar. */
    public static function year(): int
    {
        return self::now()->year;
    }

    // ------------------------------------------------------------------ TIMESTAMP (converted)

    /**
     * A stored timestamp as a moment on the organisation's clock, or null. A string is read as UTC — the way the
     * database stores it, whatever the PHP default timezone.
     */
    public static function local(DateTimeInterface|string|null $timestamp): ?CarbonImmutable
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }
        $moment = $timestamp instanceof DateTimeInterface
            ? CarbonImmutable::instance($timestamp)
            : CarbonImmutable::parse($timestamp, 'UTC');

        return $moment->setTimezone(self::timezone());
    }

    /** "৮ অক্টোবর ২০২৬, ০০:৩০" / "8 October 2026, 00:30" */
    public static function dateTime(DateTimeInterface|string|null $timestamp): string
    {
        $local = self::local($timestamp);

        return $local === null ? '—' : self::words($local->year, $local->month, $local->day).', '.self::clock($local);
    }

    /** The organisation's calendar day of a timestamp: "৮ অক্টোবর ২০২৬" / "8 October 2026". */
    public static function date(DateTimeInterface|string|null $timestamp): string
    {
        $local = self::local($timestamp);

        return $local === null ? '—' : self::words($local->year, $local->month, $local->day);
    }

    /** "০০:৩০" / "00:30" */
    public static function time(DateTimeInterface|string|null $timestamp): string
    {
        $local = self::local($timestamp);

        return $local === null ? '—' : self::clock($local);
    }

    /** "৩ মিনিট আগে" / "3 minutes ago" — relative to now (or to $now), in the admin language. */
    public static function relative(DateTimeInterface|string|null $timestamp, ?DateTimeInterface $now = null): string
    {
        $local = self::local($timestamp);
        if ($local === null) {
            return '—';
        }
        $text = $local->locale(admin_locale_is_bn() ? 'bn' : 'en')
            ->diffForHumans($now === null ? null : CarbonImmutable::instance($now), ['syntax' => CarbonInterface::DIFF_RELATIVE_TO_NOW]);

        return bn_digits($text);
    }

    // ------------------------------------------------------------------ DATE (never converted)

    /** A calendar date exactly as stored: "৮ অক্টোবর ২০২৬" / "8 October 2026". Never moved by a timezone. */
    public static function calendarDate(DateTimeInterface|string|null $date): string
    {
        $parts = self::storedParts($date);

        return $parts === null ? '—' : self::words(...$parts);
    }

    /** "অক্টোবর ২০২৬" / "October 2026" — a term or a month, exactly as stored. */
    public static function calendarMonthYear(DateTimeInterface|string|null $date): string
    {
        $parts = self::storedParts($date);

        return $parts === null ? '—' : bn_month_name($parts[1]).' '.bn_digits((string) $parts[0]);
    }

    // ------------------------------------------------------------------ WALL CLOCK (never converted)

    /** A local date-time stored as it was typed (an activity's start): shown as stored, with its time. */
    public static function wallClockDateTime(DateTimeInterface|string|null $value): string
    {
        $parts = self::storedParts($value);
        if ($parts === null) {
            return '—';
        }
        $stored = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse((string) $value, 'UTC');

        return self::words(...$parts).', '.self::clock($stored);
    }

    /** The day of a local date-time stored as typed. */
    public static function wallClockDate(DateTimeInterface|string|null $value): string
    {
        return self::calendarDate($value);
    }

    // ------------------------------------------------------------------ admin inputs for TIMESTAMP fields

    /** The value of an <input type="datetime-local"> for a stored timestamp: the organisation's local time. */
    public static function toInput(?DateTimeInterface $timestamp): ?string
    {
        return $timestamp === null ? null : self::local($timestamp)?->format('Y-m-d\TH:i');
    }

    /** An admin's datetime-local input (organisation time) as the UTC moment to store. */
    public static function fromInput(?string $local): ?Carbon
    {
        $local = trim((string) $local);

        return $local === '' ? null : Carbon::parse($local, self::timezone())->utc();
    }

    /** A real calendar day typed into a filter ('Y-m-d'), or null for anything else. */
    public static function day(?string $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    /**
     * The first instant of a calendar day ('Y-m-d') on the organisation's calendar, in UTC — for filtering a
     * timestamp column by an organisation day: [startOfDayUtc(d), endOfDayUtc(d)).
     */
    public static function startOfDayUtc(string $day): Carbon
    {
        return Carbon::parse($day.' 00:00:00', self::timezone())->utc();
    }

    /** The first instant of the day AFTER $day, in UTC (the exclusive end of a day filter). */
    public static function endOfDayUtc(string $day): Carbon
    {
        return Carbon::parse($day.' 00:00:00', self::timezone())->addDay()->utc();
    }

    // ------------------------------------------------------------------ internals

    /** [year, month, day] of a value exactly as stored — no timezone is applied. */
    private static function storedParts(DateTimeInterface|string|null $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return [(int) $value->format('Y'), (int) $value->format('n'), (int) $value->format('j')];
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($value), $m) === 1) {
            return [(int) $m[1], (int) $m[2], (int) $m[3]];
        }
        $parsed = CarbonImmutable::parse($value, 'UTC');

        return [$parsed->year, $parsed->month, $parsed->day];
    }

    private static function words(int $year, int $month, int $day): string
    {
        return bn_digits((string) $day).' '.bn_month_name($month).' '.bn_digits((string) $year);
    }

    private static function clock(DateTimeInterface $moment): string
    {
        return bn_digits($moment->format('H:i'));
    }
}
