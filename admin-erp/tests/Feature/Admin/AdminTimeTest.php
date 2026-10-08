<?php

namespace Tests\Feature\Admin;

use App\Models\Notice;
use App\Support\AdminTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Admin date/time presentation (2026-10-08, docs/DATES_AND_TIMES.md): the database keeps UTC, the admin reads
 * Asia/Dhaka (UTC+6). Timestamps are converted; calendar DATES and wall-clock values are never moved.
 *
 * The cases A–I are the owner's list: A 17:59 UTC stays on the same Dhaka day · B 18:00 UTC is the next Dhaka day ·
 * C 23:59 UTC reads +6 h · D year boundary · E leap day · F a DATE does not shift · G a timestamp does · H Bangla
 * numerals · I English numerals.
 */
class AdminTimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private static function utc(string $value): Carbon
    {
        return Carbon::parse($value, 'UTC');
    }

    public function test_the_application_stays_utc_and_the_admin_clock_is_dhaka_everywhere(): void
    {
        $this->assertSame('UTC', config('app.timezone'), 'storage and the app clock stay UTC');
        $this->assertSame('Asia/Dhaka', AdminTime::timezone());
        $this->assertSame(AdminTime::timezone(), config('membership.timezone'), 'the business calendar and the display clock are one');
        $this->assertSame(AdminTime::timezone(), Notice::DISPLAY_TIMEZONE);
    }

    public function test_a_1759_utc_stays_on_the_same_dhaka_day(): void
    {
        $this->assertSame('7 October 2026, 23:59', admin_datetime(self::utc('2026-10-07 17:59:00')));
        $this->assertSame('7 October 2026', admin_date(self::utc('2026-10-07 17:59:59')));
    }

    public function test_b_1800_utc_is_the_next_dhaka_day(): void
    {
        $this->assertSame('8 October 2026, 00:00', admin_datetime(self::utc('2026-10-07 18:00:00')));
        $this->assertSame('8 October 2026', admin_date(self::utc('2026-10-07 18:00:00')));
    }

    public function test_c_2359_utc_reads_six_hours_later(): void
    {
        $this->assertSame('8 October 2026, 05:59', admin_datetime(self::utc('2026-10-07 23:59:00')));
        $this->assertSame('05:59', admin_time(self::utc('2026-10-07 23:59:00')));
    }

    public function test_d_the_year_turns_at_1800_utc_on_31_december(): void
    {
        $this->assertSame('31 December 2026, 23:59', admin_datetime(self::utc('2026-12-31 17:59:00')));
        $this->assertSame('1 January 2027, 00:30', admin_datetime(self::utc('2026-12-31 18:30:00')));

        Carbon::setTestNow(self::utc('2026-12-31 18:30:00'));
        CarbonImmutable::setTestNow(self::utc('2026-12-31 18:30:00'));
        $this->assertSame(2027, AdminTime::year(), 'the organisation is already in 2027');
        $this->assertSame('2027-01-01', AdminTime::today());
    }

    public function test_e_leap_day(): void
    {
        $this->assertSame('29 February 2028, 00:00', admin_datetime(self::utc('2028-02-28 18:00:00')));
        $this->assertSame('1 March 2028, 00:00', admin_datetime(self::utc('2028-02-29 18:00:00')));
        $this->assertSame('29 February 2028', calendar_date('2028-02-29'));
        $this->assertSame('2028-02-29', AdminTime::day('2028-02-29'));
        $this->assertNull(AdminTime::day('2027-02-29'), 'no leap day in 2027');
    }

    public function test_f_a_calendar_date_never_shifts(): void
    {
        // A DATE column read by Eloquent is midnight UTC; a 'Y-m-d' string has no time at all. Neither moves.
        $this->assertSame('8 October 2026', calendar_date('2026-10-08'));
        $this->assertSame('8 October 2026', calendar_date(Carbon::parse('2026-10-08', 'UTC')->startOfDay()));
        $this->assertSame('October 2026', calendar_month_year('2026-10-01'));

        // Even on a clock BEHIND UTC — where converting a stored midnight WOULD move it to the previous day — a DATE
        // stays the stored day, while a timestamp follows the clock.
        config(['app.display_timezone' => 'America/New_York']);
        $this->assertSame('8 October 2026', calendar_date(Carbon::parse('2026-10-08', 'UTC')->startOfDay()));
        $this->assertSame('7 October 2026', admin_date(Carbon::parse('2026-10-08', 'UTC')->startOfDay()));
    }

    public function test_g_a_timestamp_is_converted_once_whatever_form_it_arrives_in(): void
    {
        // The owner's example: an action at 2026-10-07 18:30 UTC is 8 October 2026, 00:30 in Dhaka — not 7 October.
        $expected = '8 October 2026, 00:30';
        $this->assertSame($expected, admin_datetime(self::utc('2026-10-07 18:30:00')), 'an Eloquent timestamp (UTC Carbon)');
        $this->assertSame($expected, admin_datetime('2026-10-07 18:30:00'), 'a raw database string is read as UTC');
        $this->assertSame($expected, admin_datetime('2026-10-07T18:30:00+00:00'), 'an ISO string with its offset');
        $this->assertSame($expected, admin_datetime(CarbonImmutable::parse('2026-10-08 00:30:00', 'Asia/Dhaka')), 'already on the Dhaka clock: not converted twice');
        $this->assertSame('—', admin_datetime(null));
        $this->assertSame('—', calendar_date(null));
    }

    public function test_h_bangla_numerals_and_month_names(): void
    {
        app()->setLocale('bn');
        $this->assertSame('৮ অক্টোবর ২০২৬, ০০:৩০', admin_datetime(self::utc('2026-10-07 18:30:00')));
        $this->assertSame('৮ অক্টোবর ২০২৬', admin_date(self::utc('2026-10-07 18:30:00')));
        $this->assertSame('০০:৩০', admin_time(self::utc('2026-10-07 18:30:00')));
        $this->assertSame('২৯ ফেব্রুয়ারি ২০২৮', calendar_date('2028-02-29'));
        $this->assertSame('৩ মিনিট আগে', AdminTime::relative(self::utc('2026-10-07 18:27:00'), self::utc('2026-10-07 18:30:00')));
    }

    public function test_i_english_numerals_and_month_names(): void
    {
        app()->setLocale('en');
        $this->assertSame('8 October 2026, 00:30', admin_datetime(self::utc('2026-10-07 18:30:00')));
        $this->assertSame('00:30', admin_time(self::utc('2026-10-07 18:30:00')));
        $this->assertSame('3 minutes ago', AdminTime::relative(self::utc('2026-10-07 18:27:00'), self::utc('2026-10-07 18:30:00')));
        $this->assertDoesNotMatchRegularExpression('/[০-৯]/u', admin_datetime(self::utc('2026-10-07 18:30:00')));
    }

    public function test_a_wall_clock_value_is_shown_exactly_as_it_was_typed(): void
    {
        // An activity at 18:00 Bangladesh time is stored "2026-10-10 18:00:00" — and must read 18:00, not 00:00.
        $this->assertSame('10 October 2026, 18:00', wallclock_datetime(self::utc('2026-10-10 18:00:00')));
        $this->assertSame('10 October 2026', wallclock_date('2026-10-10 23:30:00'));
    }

    public function test_a_datetime_local_input_round_trips_without_drift(): void
    {
        $stored = self::utc('2026-10-07 18:30:00');
        $this->assertSame('2026-10-08T00:30', AdminTime::toInput($stored), 'the form shows Bangladesh time');
        $this->assertTrue(AdminTime::fromInput('2026-10-08T00:30')->eq($stored), 'saving the shown value keeps the instant');
        $this->assertSame('UTC', AdminTime::fromInput('2026-10-08T00:30')->getTimezone()->getName());
        $this->assertNull(AdminTime::fromInput(''));
        $this->assertNull(AdminTime::toInput(null));
    }

    public function test_an_organisation_day_is_the_utc_range_from_1800_to_1800(): void
    {
        $this->assertSame('2026-10-07 18:00:00', AdminTime::startOfDayUtc('2026-10-08')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-08 18:00:00', AdminTime::endOfDayUtc('2026-10-08')->format('Y-m-d H:i:s'));
        $this->assertNull(AdminTime::day('2026-13-01'));
        $this->assertNull(AdminTime::day('8 October'));
    }

    public function test_today_is_the_dhaka_day_from_1800_utc(): void
    {
        Carbon::setTestNow(self::utc('2026-10-07 17:59:00'));
        CarbonImmutable::setTestNow(self::utc('2026-10-07 17:59:00'));
        $this->assertSame('2026-10-07', AdminTime::today());

        Carbon::setTestNow(self::utc('2026-10-07 18:00:00'));
        CarbonImmutable::setTestNow(self::utc('2026-10-07 18:00:00'));
        $this->assertSame('2026-10-08', AdminTime::today(), 'the UTC date is still the 7th; the organisation is on the 8th');
    }
}
