<?php

/*
|--------------------------------------------------------------------------
| The institution's e-mail identities (docs/EMAIL_ARCHITECTURE.md)
|--------------------------------------------------------------------------
|
| THE one place the application learns which address plays which role. Code
| never writes an @provatferi.org literal: it asks App\Support\InstitutionMail,
| which reads this file. Four concepts are kept apart on purpose:
|
|   SMTP authentication  `smtp_auth` — the mailbox the SMTP transport logs in
|                        as (MAIL_USERNAME; its password is MAIL_PASSWORD, in
|                        the environment only). Internal: never a From, never
|                        a Reply-To, never published.
|   visible From         `noreply` — every automated message is From
|                        "<mail.from.name> <noreply>". config/mail.php's `from`
|                        reads the same environment variables, so the two
|                        cannot disagree.
|   Reply-To             `support` on everything a member, applicant or account
|                        holder may need help with; `security` on security mail.
|   public contact       `public` — the address the organisation publishes
|                        (website, settings API, letterheads). Never a sender.
|
| `internal` is the internal administration mailbox: the RECIPIENT of staff
| notices, nothing else.
|
| Every INSTITUTION_* variable is optional: the defaults are the production
| values. The MAIL_* names in the fallbacks are the variables this project used
| before 2026-10-14, still honoured so an existing .env resolves exactly as it
| did. No password or token belongs in this file.
|
*/

return [

    'email' => [
        'public' => env('INSTITUTION_PUBLIC_EMAIL', 'info@provatferi.org'),
        'support' => env('INSTITUTION_SUPPORT_EMAIL', env('MAIL_REPLY_TO_SUPPORT', 'support@provatferi.org')),
        'security' => env('INSTITUTION_SECURITY_EMAIL', env('MAIL_REPLY_TO_SECURITY', 'security@provatferi.org')),
        'noreply' => env('INSTITUTION_NOREPLY_EMAIL', env('MAIL_FROM_ADDRESS', 'no-reply@provatferi.org')),
        'internal' => env('INSTITUTION_INTERNAL_EMAIL', env('MAIL_OPERATIONS_ADDRESS', 'admin@provatferi.org')),
        'smtp_auth' => env('MAIL_USERNAME'),
    ],

    // The display names that go with the Reply-To roles. The sender's name is
    // mail.from.name.
    'email_names' => [
        'support' => 'Provatferi Support',
        'security' => 'Provatferi Security',
    ],

];
