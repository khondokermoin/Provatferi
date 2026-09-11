<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines (SYSTEM-007)
    |--------------------------------------------------------------------------
    |
    | Mirrors vendor/laravel/framework/.../lang/en/auth.php — these are the
    | strings LoginRequest::authenticate() and the throttle path pull via
    | trans('auth.*'). Without this file the app fell back to the framework's
    | bundled English regardless of any Blade-level Bengali work, which is
    | exactly what surfaced as "These credentials do not match our records."
    | on the login screen (AUTH-006).
    |
    */

    'failed' => 'এই তথ্য দিয়ে কোনো অ্যাকাউন্ট খুঁজে পাওয়া যায়নি।',
    'password' => 'দেওয়া পাসওয়ার্ডটি সঠিক নয়।',
    'throttle' => 'অনেকবার চেষ্টা করা হয়েছে। অনুগ্রহ করে :seconds সেকেন্ড পর আবার চেষ্টা করুন।',

];
