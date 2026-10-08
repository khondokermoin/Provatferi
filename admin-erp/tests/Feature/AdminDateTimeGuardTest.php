<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Keeps admin dates and times on the right formatter (2026-10-08, docs/DATES_AND_TIMES.md). A TIMESTAMP (cast
 * 'datetime': an instant stored in UTC) must go through admin_date()/admin_datetime()/admin_time(); a calendar DATE (cast
 * 'date') through calendar_date()/calendar_month_year(); an activity's wall-clock time through wallclock_*(). Which is
 * which is read from the models' own casts, so a new column is checked the day it is added.
 */
class AdminDateTimeGuardTest extends TestCase
{
    /** @return array{dates: array<string, true>, timestamps: array<string, true>} column names by cast, across every model */
    private function casts(): array
    {
        $dates = $timestamps = [];
        foreach (File::allFiles(app_path('Models')) as $file) {
            preg_match_all("/'([a-z_]+)'\s*=>\s*'(date|datetime|immutable_date|immutable_datetime)(?::[^']*)?'/", $file->getContents(), $m, PREG_SET_ORDER);
            foreach ($m as [, $column, $cast]) {
                str_contains($cast, 'datetime') ? $timestamps[$column] = true : $dates[$column] = true;
            }
        }
        foreach (['created_at', 'updated_at', 'deleted_at'] as $column) {
            $timestamps[$column] = true;
        }
        // A name used as a date by one model and as a timestamp by another cannot be judged by name alone.
        foreach (array_keys(array_intersect_key($dates, $timestamps)) as $ambiguous) {
            unset($dates[$ambiguous], $timestamps[$ambiguous]);
        }

        return ['dates' => $dates, 'timestamps' => $timestamps];
    }

    /** @return array<string, string> path => contents of every Blade view and every app PHP file */
    private function sources(): array
    {
        $files = [];
        foreach ([...File::allFiles(resource_path('views')), ...File::allFiles(app_path())] as $file) {
            if (in_array($file->getExtension(), ['php'], true)) {
                $files[str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname())] = $file->getContents();
            }
        }

        return $files;
    }

    public function test_the_ambiguous_helpers_are_gone_for_good(): void
    {
        $found = [];
        foreach ($this->sources() as $path => $source) {
            if (preg_match('/\b(bn_date|bn_datetime|bn_month_year)\(/', $source) === 1 && ! str_ends_with($path, 'helpers.php')) {
                $found[] = $path;
            }
        }
        $this->assertSame([], $found, 'bn_date()/bn_datetime()/bn_month_year() did not say whether a value is a timestamp or a date');
    }

    public function test_every_formatted_column_goes_through_the_formatter_of_its_kind(): void
    {
        ['dates' => $dates, 'timestamps' => $timestamps] = $this->casts();
        $this->assertArrayHasKey('verified_at', $timestamps);
        $this->assertArrayHasKey('received_at', $dates, 'a payment\'s received_at is a calendar DATE despite its name');

        $wrong = [];
        foreach ($this->sources() as $path => $source) {
            preg_match_all('/\b(calendar_date|calendar_month_year|admin_date|admin_datetime|admin_time|wallclock_date|wallclock_datetime)\(\s*\$[A-Za-z_]+(?:\??->[A-Za-z_]+)*\??->([a-z_]+)\b(?!\()/', $source, $m, PREG_SET_ORDER);
            foreach ($m as [, $helper, $column]) {
                $isDateHelper = str_starts_with($helper, 'calendar_');
                $isWallClock = str_starts_with($helper, 'wallclock_');
                if ($isDateHelper && isset($timestamps[$column])) {
                    $wrong[] = "{$path}: {$helper}(…->{$column}) — {$column} is a UTC timestamp: use admin_date()/admin_datetime()";
                } elseif (! $isDateHelper && ! $isWallClock && isset($dates[$column])) {
                    $wrong[] = "{$path}: {$helper}(…->{$column}) — {$column} is a calendar date: use calendar_date()";
                } elseif ($isWallClock && ! in_array($column, ['start_datetime', 'end_datetime'], true)) {
                    $wrong[] = "{$path}: {$helper}(…->{$column}) — only an activity's start/end are wall-clock values";
                }
            }
        }
        $this->assertSame([], $wrong);
    }

    public function test_no_view_formats_a_timestamp_or_reads_the_utc_clock_by_hand(): void
    {
        $found = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            $source = $file->getContents();
            foreach (['/_at\??->format\(/' => '->format() on a timestamp', "/\bdate\(\s*'Y/" => "date('Y…') (the UTC year)", '/now\(\)->(toDateString|format)\(/' => 'now() formatted (the UTC day)'] as $pattern => $what) {
                if (preg_match($pattern, $source) === 1) {
                    $found[] = $file->getRelativePathname().': '.$what;
                }
            }
        }
        $this->assertSame([], $found, 'use the admin date helpers / App\Support\AdminTime');
    }

    public function test_no_admin_query_filters_a_timestamp_by_a_utc_day(): void
    {
        ['timestamps' => $timestamps] = $this->casts();
        $found = [];
        foreach (File::allFiles(app_path('Http/Controllers')) as $file) {
            preg_match_all("/whereDate\(\s*'([a-z_]+)'/", $file->getContents(), $m);
            foreach ($m[1] as $column) {
                if (isset($timestamps[$column])) {
                    $found[] = $file->getRelativePathname().": whereDate('{$column}') compares the UTC day — use AdminTime::startOfDayUtc()/endOfDayUtc()";
                }
            }
        }
        $this->assertSame([], $found);
    }
}
