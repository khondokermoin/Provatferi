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

        // Shared HMAC secret for POST {url}/api/revalidate (see
        // App\Services\PublicSiteRevalidator). Server-side only: never sent
        // over the wire, only used to sign. Unset (local dev, tests) means
        // revalidation is silently skipped rather than failing. Read through
        // config() — not env() — at the call site, because `config:cache`
        // makes env() return null outside config files in production.
        'revalidate_secret' => env('REVALIDATE_SECRET'),

        /*
         * Shared HMAC secret(s) for the signed client context the public site sends with every forwarded public
         * request (App\Support\PublicClientContext, docs/RATE_LIMITING_ARCHITECTURE.md). Server-side only on BOTH
         * sides: it is never sent to a browser, so a browser can never forge a context.
         *
         * A COMMA-SEPARATED LIST, newest first, to make rotation a non-event: add the new secret to both
         * applications and deploy, then remove the old one once nothing signs with it any more. Signing always uses
         * the first; verification accepts any. Unset (local dev, tests) means no request is ever treated as
         * carrying a verified visitor — the limiter then keys on the proxy identity, which is correct rather than
         * convenient.
         */
        'client_context_secrets' => env('PUBLIC_CLIENT_CONTEXT_SECRETS', env('PUBLIC_CLIENT_CONTEXT_SECRET')),
    ],

    // Mail Center (Hostinger official Mail API). The token is scoped in
    // hPanel to a specific set of mailboxes (info@/support@/security@ —
    // never admin@ or no-reply@, see docs/EMAIL_ARCHITECTURE.md) and is never
    // logged, echoed, or exposed to the frontend. `webhook_secret` is the
    // one-time secret Hostinger returns when the message.received webhook
    // is created — HostingerMailWebhookController compares it against the
    // inbound request's own Authorization header on every delivery.
    'hostinger_mail' => [
        'token' => env('HOSTINGER_MAIL_API_TOKEN'),
        'webhook_secret' => env('HOSTINGER_MAIL_WEBHOOK_SECRET'),
    ],

];
