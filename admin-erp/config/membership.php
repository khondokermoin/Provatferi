<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fee-policy calendar
    |--------------------------------------------------------------------------
    |
    | The application runs in UTC (config/app.php) but the organisation lives in
    | Bangladesh, six hours ahead. A fee policy that "takes effect on 1 December"
    | means 1 December ON THE ORGANISATION'S CALENDAR, and an application is
    | quoted the policy of the day it was submitted THERE — not the UTC day.
    | Without this an application submitted at 01:00 on 1 December in Dhaka (19:00
    | UTC on 30 November) would be quoted the old fee. Fee-policy dates use it,
    | and so does a membership's joining date (and the year in its member
    | number) at approval (Membership Registry task 2), and the monthly dues
    | calendar (task 4). It is the same clock the admin shows every date and
    | time on (config('app.display_timezone'), 2026-10-08) and defaults to it.
    |
    */

    'timezone' => env('MEMBERSHIP_TIMEZONE', env('APP_DISPLAY_TIMEZONE', 'Asia/Dhaka')),

    /*
    |--------------------------------------------------------------------------
    | Member numbers (Membership task 3, 2026-10-07)
    |--------------------------------------------------------------------------
    |
    | A member number is "{prefix}-{type code}-{year}-{sequence}", e.g.
    | PLCC-LM-2026-0001: the prefix below, the membership type's own code
    | (membership_types.code), the year of approval on the calendar above, and
    | a counter of its own for that type and year (App\Services\NumberSequence).
    | Application numbers are "APP-{year}-{sequence}" with one counter per year.
    | See docs/MEMBERSHIP_NUMBERING.md.
    |
    */

    'number_prefix' => 'PLCC',

];
