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
    | UTC on 30 November) would be quoted the old fee. Only fee-policy dates use
    | it; nothing else in the application changes timezone.
    |
    */

    'timezone' => env('MEMBERSHIP_TIMEZONE', 'Asia/Dhaka'),

];
