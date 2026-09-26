<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // The Next.js institutional site (provatferi.org) — this admin app never
    // calls it, but needs its base URL to build public links it hands out
    // (committee registration/correction links, §22/§27) since those forms
    // are Next.js pages, not Laravel routes.
    'public_site' => [
        'url' => env('PUBLIC_SITE_URL', 'https://provatferi.org'),
    ],

    // Mail Center (Hostinger official Mail API). The token is scoped in
    // hPanel to a specific set of mailboxes (info@/support@/security@ —
    // never admin@ or no-reply@, see docs/MAIL_ROLES.md) and is never
    // logged, echoed, or exposed to the frontend. `webhook_secret` is the
    // one-time secret Hostinger returns when the message.received webhook
    // is created — HostingerMailWebhookController compares it against the
    // inbound request's own Authorization header on every delivery.
    'hostinger_mail' => [
        'token' => env('HOSTINGER_MAIL_API_TOKEN'),
        'webhook_secret' => env('HOSTINGER_MAIL_WEBHOOK_SECRET'),
    ],

];
