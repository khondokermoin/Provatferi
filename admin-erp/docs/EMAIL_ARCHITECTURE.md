# E-mail architecture

The one reference for which institutional address does what, how the
application uses them, and how to test that on production. It replaces
`MAIL_ROLES.md`, which was retired on 2026-10-14.

The code that enforces this lives in three places:

- `config/institution.php`: the addresses, one per role.
- `App\Support\InstitutionMail`: which address goes into which header.
- `tests/Feature/Mail/EmailIdentityTest.php`, plus the public site's
  `institutional/__tests__/mail-identity.test.mts`: the tests that hold the
  line.

## The five mailboxes

| Role | Address | What it is for | Public? |
|---|---|---|---|
| `public` | `info@provatferi.org` | The organisation's public contact: website footer and contact page, `Organization` schema, the settings API (`site.email`), receipt letterheads, general enquiries | **Yes.** It is published, never used as a sender. |
| `support` | `support@provatferi.org` | Help for members, applicants and account holders; the **Reply-To** of every user-facing automated mail; the "questions about your application" line on the public site | Yes, where a person needs help with their own case |
| `security` | `security@provatferi.org` | Security communication: suspicious sign-ins, credential or account incidents, vulnerability reports; the **Reply-To** of security mail | Yes, as the security contact |
| `noreply` | `no-reply@provatferi.org` | The **visible From** (and envelope sender) of everything the application sends | Seen as a sender only; nobody is asked to write to it |
| `internal` | `admin@provatferi.org` | Internal administration: the recipient of staff notices, and the super-admin login | **No. Never.** |

No other address is used. A new mailbox is an owner decision, not a code change.

## Four concepts, never mixed

| Concept | Where it is set | Production value |
|---|---|---|
| **SMTP authentication**: which mailbox the transport logs in as | `MAIL_USERNAME` / `MAIL_PASSWORD`, in the server's `.env` only | A private mailbox. It is not written here because this repository is public; `php artisan mail:identity` on the server shows it. |
| **Visible From** | `config/mail.php` `from` = `institution.email.noreply` | `Provatferi Literary and Cultural Center <no-reply@provatferi.org>` |
| **Reply-To** | `InstitutionMail::support()` / `security()` | `Provatferi Support <support@>` or `Provatferi Security <security@>` |
| **Public contact** | the `site.email` setting (seeded from `institution.email.public`) | `info@provatferi.org` |

Do not assume the SMTP login may send as another address: test it. On
2026-10-14 Hostinger accepted `MAIL FROM` for every institutional address from
the production login. Whole-message delivery and authentication (SPF, DKIM,
DMARC) are tested by the procedure under "Production testing" below.

## Message classes

Every message the application builds belongs to exactly one class. The class,
not the notification, decides the identity headers.

| Class | Call | From | Reply-To | Recipient | Used by |
|---|---|---|---|---|---|
| support | `InstitutionMail::support($message)` | no-reply@ | `Provatferi Support <support@>` | the person | ERP and member password reset; member portal invitation (account setup); membership decision (approved / rejected / needs information); volunteer application received and decided; committee correction request and decision; public profile reviewed |
| security | `InstitutionMail::security($message)` | no-reply@ | `Provatferi Security <security@>` | the person | Nothing yet. This is the route the first security notice (new sign-in, password changed, MFA change …) must take. |
| internal | `InstitutionMail::internal($message)` | no-reply@ | **none** | `InstitutionMail::internalRecipient()` (admin@) | The new volunteer application notice |

**Security mail is From no-reply@ with Reply-To security@ (the canonical
choice).** Every automated message keeps one sender identity: one DKIM and
DMARC alignment and one sender reputation, and people learn that automated
mail only ever comes from `no-reply@`. Bounces and auto-replies go to the
envelope sender (`no-reply@`), so they never flood the mailbox where real
incident reports arrive. The security role is carried by the Reply-To and by
the message text, which names `security@` as the contact.

**Internal notices have no Reply-To.** Nothing in them needs an answer: the work
happens in the ERP. Leaving the header out also means the class can never offer
the internal mailbox to anyone as an address to write to, even if it were
misused for an outside recipient. Until 2026-10-14 the notice named the internal
mailbox as its own Reply-To.

The footer of every mail (HTML and plain text) names the support role as the
help address and the organisation's public name (`mail.from.name`), never the
internal app name.

## Configuration

`config/institution.php`: every `INSTITUTION_*` variable is optional, because
the defaults are the production values.

```dotenv
INSTITUTION_PUBLIC_EMAIL="info@provatferi.org"
INSTITUTION_SUPPORT_EMAIL="support@provatferi.org"
INSTITUTION_SECURITY_EMAIL="security@provatferi.org"
INSTITUTION_NOREPLY_EMAIL="no-reply@provatferi.org"   # also the From (config/mail.php reads the same variable)
INSTITUTION_INTERNAL_EMAIL="admin@provatferi.org"
MAIL_FROM_NAME="Provatferi Literary and Cultural Center"
MAIL_USERNAME=…   # SMTP login: server .env only
MAIL_PASSWORD=…   # server .env only; never in Git, a log, a page or a report
```

An `.env` written before 2026-10-14 resolves exactly as before:
`MAIL_FROM_ADDRESS`, `MAIL_REPLY_TO_SUPPORT`, `MAIL_REPLY_TO_SECURITY` and
`MAIL_OPERATIONS_ADDRESS` are still read when the new variable is unset.
`MAIL_REPLY_TO_ADDRESS` (the old "general" Reply-To, `info@`) is retired:
`info@` is never an automated Reply-To.

SMTP: `smtp.hostinger.com`, port `465`, `MAIL_SCHEME=smtps` (implicit TLS, so
there is no cleartext window to downgrade). `MAIL_ENCRYPTION` is not read by
Laravel 12.

Run `php artisan mail:identity` (or add `--json`) to print every role, the From,
the mailer and SMTP host, and whether a password is set (never the password).
It exits non-zero on any problem. The release pipeline runs the same check
(`InstitutionMail::problems()`) in `smoke-test-isolated` and refuses to switch to
a release that fails it.

`problems()` reports:

- a role that is missing, or is not exactly one plain address;
- a From that is not the `noreply` role, or a From name that is not one line;
- a sender, Reply-To or public role that points at a private mailbox;
- a Reply-To role, or the public contact, that is the automated sender;
- an SMTP mailer without a login;
- a production mailer that keeps whole messages instead of sending them
  (`log`, `array`, or a failover to `log`), which would put reset links in the
  log.

## Adding a new e-mail workflow

1. Write a `Notification` with a `toMail()` that builds the `MailMessage`, then
   returns it through **one** class: `InstitutionMail::support($message)`,
   `security($message)` or `internal($message)`.
   - A person may need help with it: **support**.
   - It is about the security of an account or of the system: **security**.
   - It goes to staff: **internal**, sent to
     `InstitutionMail::internalRecipient()`.
2. Never call `->from()`, `->replyTo()` or `->sender()` yourself, and never write
   an `@provatferi.org` address in code. `EmailIdentityTest` fails on either.
3. Add the new class to `applicationMessages()` in `EmailIdentityTest` with its
   class. The test fails until every notification in `app/Notifications` is
   listed and classified.
4. Send after the response (`dispatch(...)->afterResponse()`, as
   `SendVolunteerApplicationNotifications` does) and log a failure at `error`
   level with the class and message only. Never log the body, a link or a
   token.

## Keeping admin@ private

The internal mailbox, and the SMTP login, are **private**
(`InstitutionMail::isPrivate()`):

- No class puts a private mailbox in a From or Reply-To. A role misconfigured to
  point at one is left out, and `problems()` reports it.
- The public contact cannot be set to a private mailbox: the admin Settings
  screen refuses it, because `site.email` is published on the website, in the
  API and on receipts.
- `EmailIdentityTest` requests every public page and API route with the
  internal mailbox present as a staff account and fails if any response
  contains it. It also scans the admin's client-side assets for credential
  names and private addresses.
- The public site may show only `info@` and `support@`. Both live in
  `institutional/lib/content.ts` (`org.email`, `org.supportEmail`). Its test
  scans the sources, and every production build scans the browser bundle
  (`scripts/verify-client-bundle.mjs`, the `postbuild` step) and fails on any
  other `@provatferi.org` address, any mail-credential or server-only variable
  name, or the value of `REVALIDATE_SECRET`.
- This repository is public. Do not write which mailbox the SMTP transport logs
  in as, or any credential, into it.

## Security rules

- **Credentials** live in the server's `.env` only. Scripts read them through
  config and report booleans ("password set"), never a value or a length.
- **Header injection.** Every recipient enters through the `email` validation
  rule, and Symfony's `Address` checks it again; both refuse CR/LF (tested).
  Role addresses are used only when they are exactly one plain address.
  Subjects and display names are flattened to one line (`headerText()`), so a
  line break in data (a committee or posting name) can never start a header.
- **Logs** may record a message's class, the application number, the recipient
  when needed, the delivery state, the exception class and message. Never a
  body, a reset link or token, or a credential. The reset flow is tested to log
  neither its token nor its link. Never call Symfony's `getDebug()` into a log:
  it contains the SMTP AUTH line.
- **Reset links** expire (staff: `auth.passwords.users.expire`, members:
  `auth.passwords.members.expire`) and are single-use.

## DNS (verified 2026-10-14, unchanged by this work)

| Record | Value |
|---|---|
| MX | `5 mx1.hostinger.com`, `10 mx2.hostinger.com` |
| SPF | `v=spf1 include:_spf.mail.hostinger.com ~all` |
| DKIM | `hostingermail-a._domainkey` (active key); `-b` and `-c` publish an empty key (Hostinger's rotation slots) |
| DMARC | `v=DMARC1; p=none; rua=mailto:postmaster@…, mailto:dmarc@…; pct=100; adkim=s; aspf=s` |

Strict alignment holds for application mail: the From domain, the DKIM `d=`
and the envelope domain are all `provatferi.org`. Open points for the owner:
the DMARC report addresses are not among the five mailboxes, and `p=none` only
monitors.

## WordPress installs (cms., archive.)

They do not use this configuration. WordPress sends with PHP `mail()` as
`wordpress@cms.provatferi.org` / `wordpress@archive.provatferi.org`. Neither is
one of the five identities, and there is no SMTP login. Bringing them in line
means an SMTP plugin, configured by the owner in wp-admin, logging in to
Hostinger and sending From `no-reply@` with Reply-To `support@`. It is not done
from this repository, because it puts a mailbox credential inside WordPress.

## Production testing

Disposable recipients only. The kit is `deploy/qa/mail-roles-qa.php`, uploaded
under a one-off name from `deploy/qa/cron-guard.php new-session` and run from
cron jobs. It never prints a password, a token, a link or a body.

1. `php artisan mail:identity` on the server: no problems.
2. Create a disposable inbox (for example with the mail.tm API).
   `subjects-create <tag> <staff-address> <member-address>` makes a role-less,
   unverified, inactive staff account and an inactive member on disposable
   addresses.
3. Real password resets over HTTPS: `POST /forgot-password` (with the page's
   CSRF token) and `POST /api/v1/member/auth/password/email`.
4. `send <tag> <inbox>`: one message of every class, built by the real
   notification classes. The support, security and internal ones also go to the
   internal mailbox.
5. In the disposable inbox, read the raw messages. Check From, Reply-To, To and
   Subject, and verify SPF, DKIM and DMARC from the raw source (for example
   with the `mailauth` package, using the `Received` chain).
6. `reply <tag>`: the kit opens the internal mailbox over IMAP with the SMTP
   login's credentials. It answers each test copy the way a mail client does
   (to its Reply-To, else its From) and asks the server for delivery status
   notifications. It replies only to the organisation's own role addresses.
7. `verify <tag>` a few minutes later. Each reply must have a `delivered` report
   for the expected role (`support@`, `security@`), and no reply may have come
   back to the internal mailbox.
8. `subjects-cleanup`, then `cleanup <tag>`, which deletes exactly this tag's
   messages from the internal mailbox. Remove the kit and the cron jobs, and
   delete the disposable inbox. The test replies stay in `support@` and
   `security@` (subject `Re: [MAIL TEST <tag> …]`) for their readers to delete.
