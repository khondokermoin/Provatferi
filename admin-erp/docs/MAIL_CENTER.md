# Mail Center

The admin ERP's Mail Center: institutional mailboxes, used through the panel by the staff an administrator assigns to
them — without anyone receiving a mailbox password or a provider credential. Built 2026-10-24 (the "Staff Dashboard +
Role-Based Mail Center" order), with the partnerships mailbox of the Director of Social Development & Partnerships as the
first limited use.

Related: `docs/EMAIL_ARCHITECTURE.md` (which address plays which role in mail the application sends by itself — unchanged
by the Mail Center) and `docs/MFA_SECURITY.md` (two-step verification).

## Architecture

```
browser ──► Laravel (admin.provatferi.org, session + two-step) ──► MailboxAccess (which mailbox, which action)
        ◄── sanitized page                                      ──► MailProvider ──► Hostinger Mail API ──► mailbox
```

- Every mailbox, message, attachment and action is authorised on the server, per request, for the signed-in account
  (`App\Services\Mail\MailboxAccess`). Nothing the browser sends — a mailbox id, a folder, a message number, an attachment
  position, a form field — reaches the provider before that check, and a folder must exist in the mailbox's real folder
  list.
- The provider is reached only through `App\Services\Mail\MailProvider`; `HostingerMailProvider` implements it over the
  official Hostinger Mail API PHP SDK (`hostinger/mail-api-php-sdk`, wrapped by `App\Services\HostingerMailService`). The
  rest of the application sees plain data objects (`App\Services\Mail\Data\*`), never SDK models or provider responses.
- **The ERP stores no message content.** Lists, messages and attachments are fetched live; only folder counts are cached,
  for 60 seconds, shared by everyone using a mailbox (`MailFolders`). The audit trail identifies a message by folder and
  number only.

Code: `app/Services/Mail/`, `app/Http/Controllers/Admin/Mail/`, `app/Http/Middleware/MailCenterPrivacy.php`,
`app/Models/{Mailbox,MailboxGrant,MailAuditEvent}.php`, `config/mail_center.php`, `resources/views/admin/mail/`,
`public/js/provatferi-mail.js`.

## Mailbox registry

Table `mailboxes` — operational metadata only:

| Column | Meaning |
|---|---|
| `address` | the mailbox, lower case; **never changes** once registered (grants and the audit trail point at the row) |
| `display_name` | the sender name on mail sent from it through the Mail Center, e.g. `Provatferi / Partnerships` |
| `description` | a purpose note for staff |
| `provider`, `provider_mailbox_id` | `hostinger_mail_api` and the provider's own, non-secret mailbox id, recorded by the connection check |
| `enabled`, `sort_order` | offered in the Mail Center or not; order in the list |
| `status`, `status_checked_at` | `connected` / `not_in_scope` (the token does not include it) / `provider_unconfigured` / `unavailable` / `unchecked` |

There is no password, token or provider secret in the table, and there must never be one.

Admin → Mail → **Mailboxes** (permission `mail.manage_access`, every change behind a fresh two-step code): register a
mailbox of the institution's own domain (the domain is taken from `institution.email.public`; other domains are refused),
edit its name, purpose, order and whether it is offered, re-check its connection, and create its Archive folder (Hostinger
creates none by default; archiving needs one). The page lists the mailboxes the token reaches that are not registered yet.

## Provider integration (Hostinger Mail API)

Capabilities verified against the official SDK documentation (1.18) and Hostinger's own help pages on 2026-10-24, and
used as follows — nothing the API does not offer is pretended:

| Mail Center | API | Notes |
|---|---|---|
| mailboxes the token reaches | `GET /v1/me` | cached 5 minutes under a keyed digest of the token |
| folders, unread counts | `listFolders` | RFC 6154 special-use roles (Inbox, Sent, Drafts, Junk, Trash, Archive) |
| message list | `listMessages` (page, 25 per page, newest first) | provider-side paging: one request per page whatever the mailbox size |
| search | `searchMessages` with ONE typed criterion (`text`, `from` or `subject`) | the words are a JSON value, never query syntax |
| open a message | `getMessage` + `getMessageText` | **the provider marks the message read** when its text is fetched |
| attachment | `getMessageAttachment` | by the provider's id taken from the server-side attachment list |
| read / unread, flag | `patchMessage` (`\Seen`, `\Flagged`) | |
| archive, trash, back to Inbox | `moveMessage` | no permanent delete in the Mail Center |
| send, reply, forward | `sendEmail` (`inReplyTo` / `forwardOf` {uid, folder}) | sent **as the mailbox itself**; the provider copies Message-Id/References, flags the source `\Answered` / `$Forwarded`, saves a copy in Sent |
| new-mail webhook | `message.received`, Bearer secret | optional, see "Unread badge" |

Not available, so not built: a message **preview/snippet** in the list (the list response has none, and the only way to
read text marks the message read — previews would silently change every message to "read" or cost two extra provider
calls per message per page); the **Reply-To** header of a received message (the API returns From/To/Cc/Bcc only: Reply
answers the sender); drafts (Drafts is shown read-only).

Every provider failure becomes a `MailProviderException` with a kind — `unconfigured`, `not_in_scope`, `not_found`,
`rate_limited`, `rejected`, `auth`, `unavailable` — and the provider's HTTP status. Its message and response body are never
shown, stored or logged (the server log gets the kind, the status and the exception class). Calls time out after 6 s
(connect) / 30 s.

### Connecting the mail service (owner, once)

0. On 2026-10-24 partnerships@ was an **alias** delivered into another mailbox, not a mailbox of its own (see "Production
   state" below): first make it a real mailbox (hPanel → **Emails** → provatferi.org: remove the alias, create the
   mailbox `partnerships@`). The Mail API reads and sends for mailboxes; an alias has no mailbox of its own.
1. hPanel → **Emails** → provatferi.org → **Agentic Mail** → **API access** → **Create API token**: a descriptive name,
   **Selected mailboxes** (tick every mailbox the Mail Center should offer — at least `partnerships@`), create. The token
   is shown **once**.
2. Put it in the server's `.env` — `/home/u951246149/domains/provatferi.org/laravel-admin/.env`, through hPanel's File
   Manager — as `HOSTINGER_MAIL_API_TOKEN=<token>`. Never in chat, e-mail, Git, a ticket or this panel.
3. Rebuild the configuration cache (`php artisan config:cache` — a one-shot cron, or the next release does it).
4. Admin → Mail → Mailboxes → **Check connection** on each mailbox: `connected`.

The token is never entered in, shown by, or sent to the panel; `deploy/qa/mail-center-qa.php audit` reports only whether
it is set.

## Permission model

Two layers, both assignable in the panel without code:

**Role permissions** (module `mail`, Users & Permissions → Roles):

| Permission | Means |
|---|---|
| `mail.access` | may open the Mail Center (and sees only the mailboxes it has a grant for) |
| `mail.manage_all` | every **enabled** mailbox, every action, without grants (Super Admin level) |
| `mail.manage_access` | register mailboxes, assign mailbox access, read the mail audit log |

(The 2026-09 actions `mail.view/read/send/manage/delete` — defined for a Mail Center that was never built, and never
enforced — were removed by migration `2026_10_24_100300`. On production none of them existed.)

**Mailbox grants** (table `mailbox_grants`: one row per mailbox and role, or per mailbox and user):

| Capability | Allows |
|---|---|
| Read | folders, message list, search, open a message, download its attachments |
| Send | a new message from the mailbox |
| Reply | reply / reply all to a message of the mailbox |
| Forward | forward a message of the mailbox (and choose which of its attachments go with it) |
| Message state | read/unread, flag, archive, move to Trash and back |

Reply, Forward and Message state **include Read** (ticking one ticks Read; the server enforces the same). What an account
may do in a mailbox is the **union** of its own grant and its roles' grants — read from the database on every request,
so a revoked grant stops working on the very next request. The Mail Center opens only with `mail.access` (or
`mail.manage_all`): a grant without it has no effect, and the grid says so.

A mailbox the account may not use answers **404** — exactly like one that does not exist — on every route; a capability it
lacks on a mailbox it can see answers **403**. No code anywhere looks at who a person is: access is roles, permissions and
grants only.

### Assigning access

Users & Permissions → Roles → a role → **Mailbox access**, or Users → an account → **Mailbox access** (for a single
person, in addition to their roles; the grid shows what the roles already give):

`Mailbox | Read | Send | Reply | Forward | Message state`

Needs `mail.manage_access` and a two-step code entered in the last 15 minutes (`mfa.recent`, strict: an account without
two-step verification cannot hand out mailbox access at all). Super Admin's role is not edited here: it has every mailbox
through `mail.manage_all`. Every change is audited (before → after, per mailbox), and an account that a change makes
required to use two-step verification is recorded as newly required, as with role permissions.

## Super Admin

Super Admin has every permission, so `mail.manage_all`: every enabled mailbox, every action, plus the registry, the access
grids and the audit log. Nothing in the panel — for Super Admin either — shows, accepts or stores a provider credential,
SMTP password or mailbox password.

## Limited staff (the partnerships use)

A normal role — for example "Social Development & Partnerships" — with only `mail.access`, and on the partnerships mailbox
Read, Send, Reply, Forward and Message state. Such an account sees: the dashboard (its own panels), its profile, Account
Security, and the Mail Center with **only** partnerships@. Every other mailbox is a 404 by any URL, id, message number,
attachment position or form field; user and role management, settings, payments, membership finance, the mailbox registry,
the mail audit log and every Super Admin screen are refused by their own permissions. The menu shows only what the account
can open: a heading with nothing under it is not shown.

## Staff dashboard

Every account gets its own panels first (`DashboardController::staff`): who is signed in and with which role, two-step
verification (on/off, required or recommended), its mailboxes with Inbox unread counts and a link to the Mail Center, the
latest messages of its first readable mailbox (fetched live, never stored), its recent sent/replied/forwarded mail (from
the audit trail: action, mailbox, time, number of recipients — no subjects), and its recent activity (security events
about it and its Mail Center actions). Organisation-wide figures and lists follow only for accounts allowed to see them,
and are no longer computed for anyone else.

## Mail Center pages

- Desktop (≥ 1200 px): mailboxes and folders | message list | open message. Below 1200 px the list and the message take
  turns (a "Back to the list" button); below 768 px the mailbox and folder lists become a scrolling strip above.
- List: sender (or recipient in Sent), subject, time, unread dot and bold, attachment, flag, replied and forwarded
  indicators; the sender's address on the second line. Search inside the current folder of the current mailbox only.
- Light/dark: the panel's theme tokens; the message itself is shown on a white "paper" surface in both themes (e-mail is
  written for white). Bangla and English: every string in `lang/{bn,en}/admin.php` under `mail.*`.
- Icons: the panel's Tabler icon set (SVG-designed icons served as the panel's subset icon font); no emoji.

## Reading mail safely

Incoming HTML is treated as hostile (`MailBodyRenderer`):

1. `cid:` pictures are pointed at the ERP's own attachment route for that message.
2. **HTML Purifier** (`ezyang/htmlpurifier` 4.19, an allow-list sanitizer; `MailHtmlSanitizer`): what is not explicitly
   allowed is dropped. Gone always: script, style, link, iframe, frame, object, embed, applet, form and every control, meta,
   base, every `on*` attribute, ids, `javascript:`/`vbscript:`/`data:text` URIs, and every CSS property that can load a
   resource (`url()`, background, list-style-image) or cover the page (position, z-index, top/left…, opacity, filter).
   Links keep http, https and mailto only and open in a new tab with `rel="noopener noreferrer nofollow"`.
3. A second pass over the sanitized tree: the message's own inline pictures and validated `data:` images stay; **remote
   images are replaced by a blank placeholder until the reader clicks "Show images"** (a remote image tells its sender
   when, and from which address, the message was read); `background` attributes go; anything with `url(`/`expression(` in
   a style goes; only http/https/mailto links stay.
4. The result is a complete document with its own Content-Security-Policy (`script-src 'none'`, `default-src 'none'`,
   images only from the ERP and `data:` — plus `https:`/`http:` after "Show images" — no frames, no forms, no `<base>`) and
   `no-referrer`, shown in an `<iframe srcdoc sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox">`:
   no scripts, no form submission, no navigation of the panel. The panel's own DOM never contains message HTML.

HTML parts larger than 2 MB are not parsed: the text part is shown. Plain-text messages are escaped character by
character; only http(s) addresses become links.

## Attachments

`GET /admin/mail/{mailbox}/messages/{uid}/attachments/{position}?folder=…` — needs Read on the mailbox. The position only
picks from the attachment list the provider returns for that message of that mailbox; the provider's attachment id never
leaves the server, so no request can name another mailbox's object or a path. Responses: `application/octet-stream` with
`Content-Disposition: attachment` and a sanitized file name (RFC 6266/5987 encoding) — except raster images (PNG, JPEG,
GIF, WebP) whose **bytes** match an image type, which may be shown inline. Always `X-Content-Type-Options: nosniff`,
`Content-Security-Policy: sandbox; default-src 'none'…`, `Cross-Origin-Resource-Policy: same-origin`, no-store. PDFs and
everything else download. Downloads and previews are audited (position, type, size — not the name or content).

## Compose, reply, forward

- **No From field exists.** The provider sends as the mailbox the request is authorised for; the display name comes from
  the registry. A request's `from`, `sender`, `reply_to`, `display_name` or `mailbox_id` fields are never read.
- To, Cc, Bcc: comma-separated; `Name <address>` keeps the address; each address must pass strict RFC validation; at most
  50 recipients. A line break, NUL or other control character anywhere in To/Cc/Bcc/Subject is refused (header
  injection); the framework trims such characters at the very end of a field before anything sees them.
- Subject ≤ 255 characters; body ≤ 100 000 characters (sent as text plus a minimal HTML version built from the escaped
  text). Attachments: at most 10 files, 10 MB each, 15 MB in total; programs and scripts (`exe`, `bat`, `js`, `ps1`, `jar`,
  `sh`, `iso`, …) are refused.
- **Reply** / **Reply all**: to the sender (reply all adds the other To/Cc recipients, never the mailbox itself), subject
  `Re: …`, threaded by the provider through `inReplyTo`. The original is quoted below the reply ("On <date>, <sender>
  wrote:", `> ` lines) — fetched from the mailbox when sending, never from the form. Needs Reply; a read-only grant cannot
  reach the reply route (403).
- **Forward**: needs Forward. A clearly marked "Forwarded message" block (From, Date, Subject, To, Cc, then the text),
  `forwardOf` for threading. The original's attachments go with it **only if ticked** (none by default), fetched from the
  provider at send time.
- Both the form (GET) and the send (POST) check the capability, so a form opened before access was revoked cannot be used.

## Limits and duplicate protection

`config/mail_center.php` → `rate_limits`, all keyed by **account + mailbox + action** (never by IP address alone):
send 20/hour, reply 60/hour, forward 30/hour, any outgoing message 5/minute per account and mailbox, 100/hour for a whole
mailbox (sender reputation), search 30/minute, attachments 60/minute. Route-level caps per account: 30 writes/minute, 60
unread polls/minute (each page asks once on load).

Every compose form carries a one-time key (`form_key`). `MailSender` claims it atomically before sending: a double click,
a resubmitted page or a retry of the same form sends nothing and says so (audited as `message.duplicate_blocked`). A
**refusal** by the provider frees the key (fix and send again); **no answer** (a time-out) keeps it — the message may have
gone out — and the page says to look in Sent before writing again. The send form also disables itself and shows a
page-level "Sending…" state (`provatferi-form-guard.js`, `provatferi-mail.js`).

## Unread badge

The top bar shows an envelope with the account's total Inbox unread (only for Mail Center users). The page never waits
for the provider: `provatferi-mail.js` asks `GET /admin/mail/unread` after the page loads and every 90 seconds, **only
while the tab is visible** (no background polling, no sound). Counts come from the shared 60-second folder cache, so the
badge costs at most one provider request per mailbox per minute in total.

The existing `message.received` webhook receiver (`POST /api/webhooks/hostinger-mail`, Bearer secret
`HOSTINGER_MAIL_WEBHOOK_SECRET` checked with `hash_equals`, idempotent by event id, throttled) is optional: when
registered (`php artisan mail:webhook:register`), an event for a registered mailbox only drops that mailbox's cached
counts, so the next badge asks the provider — it never trusts the payload and stores no content. It creates no
notification (the earlier broadcast notification would have named mailboxes to people who cannot open them). Polling alone
keeps the badge correct without it.

## Audit

Table `mail_audit_events` (`MailAuditLogger`, append-only): `mailbox.opened` (at most once per account and mailbox per 30
minutes), `message.opened`, `message.sent`, `message.replied`, `message.forwarded`, `message.send_failed`,
`message.duplicate_blocked`, `attachment.downloaded`, `message.state_changed`, `mailbox.registered`, `mailbox.updated`,
`mailbox.checked`, `mailbox.folder_created`, `access.changed` (whose access: `role:<id>` / `user:<id>`, before → after).
Each row: actor, mailbox, action, folder + message number, time, IP address and browser (as the security audit does),
and `context` with counts, flags and result codes. The logger refuses any context key that could carry a credential or
content (`token`, `password`, `authorization`, `body`, `subject`, `content`, `filename`, …): no subject, body, attachment
name or content, token or header is ever recorded. Admin → Mail → **Mail audit log** (`mail.manage_access`).

## Two-step verification

- Any Mail Center permission (`mail.access`, `mail.manage_all`, `mail.manage_access`) **or any mailbox grant** — through
  a role or directly — makes an account **privileged** under `MfaPolicy` (`docs/MFA_SECURITY.md`): when the owner turns on
  the privileged-account rule, every mail user must enrol before reaching anything. Before that, the role's own
  "Require two-step verification" switch makes it required for that role at once (recommended for every mail role).
- Changing the registry or anyone's mailbox access needs a code from the last 15 minutes (strict step-up).
- Nothing in the Mail Center bypasses `EnsureMfa`: its routes are ordinary admin web routes.

## Privacy

Every Mail Center response: `Cache-Control: no-store, private`, `X-Robots-Tag: noindex, nofollow, noarchive`,
`Referrer-Policy: no-referrer`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, and the pages carry
`<meta name="robots" content="noindex, nofollow">`. All routes are authenticated admin routes (`admin/mail…`,
`admin/mail-admin…`); none is in the public API, the public website, its client bundle or a sitemap.

## Adding a future mailbox (programs@, fundraising@, projects@ …)

1. The owner creates the mailbox in hPanel (Emails → Mailboxes) and includes it in the Mail API token (a token with
   "Selected mailboxes" must be edited or replaced; "All mailboxes" includes new ones automatically).
2. Admin → Mail → Mailboxes → **Register a mailbox**: address, sender name, order. Check connection → `connected`.
3. Open the role (or account) that should use it → **Mailbox access** → tick the capabilities → save (two-step code).

No controller, route, view or permission is added for a mailbox. (On 2026-10-24 programs@, fundraising@ and projects@ did
not exist at Hostinger: the MX answered "User doesn't exist".)

## When the mail service is unavailable

| Situation | What staff see | HTTP |
|---|---|---|
| no token on the server | "The Mail Center is not connected to the mail service yet." (administrators: where to look) | 200 |
| token does not include the mailbox | "This mailbox is not included … yet." | 200 |
| token refused, provider error, time-out | "The mail service is temporarily unavailable. Please try again shortly." + Try again | 503 |
| provider rate limit (or the Mail Center's own search limit) | "Too many requests right now…" | 503 |
| message gone | "This message is no longer available…" | 404 |
| send refused | "The message was not sent…" — the form stays, nothing was sent | — |
| send without an answer | "The mail service did not confirm the send… look in Sent" — the same form cannot be sent again | — |

Never a raw exception, a provider message or a stack trace; the rest of the panel (and the dashboard) keeps working: the
unread badge is loaded after the page, and a provider failure in one request stops further provider calls in that request.

## Secrets

- The Mail API token and the webhook secret live only in the server's `.env` (`HOSTINGER_MAIL_API_TOKEN`,
  `HOSTINGER_MAIL_WEBHOOK_SECRET`); never in the database, Git, a page, a JSON answer, a script, a log or the audit trail.
  Kits and pages report only whether they are set.
- No mailbox password exists anywhere in the ERP: the Mail API token is the only credential, and only the server holds it.
- The test suite cannot inherit a developer's token: `phpunit.xml` blanks both variables, and the Mail Center tests use
  `Tests\Support\FakeMailProvider` (the SDK's own HTTP client is invisible to `Http::preventStrayRequests()`).
- This repository is public: no credential, and no statement of which mailbox the SMTP transport logs in as, belongs in it.

## Production state found on 2026-10-24 (before this release)

From `deploy/qa/mail-center-qa.php` (read-only `audit`), an MX recipient check from outside the hosting account (`rcpt`;
port 25 is closed from the account) and delivery-status reports (`dsn-send` / `dsn-read` / `dsn-cleanup`; the twelve test
messages and reports were deleted afterwards):

- No `HOSTINGER_MAIL_API_TOKEN` and no webhook secret on the server; the SDK and the earlier, unused integration code were
  present; no `mail.*` permission existed.
- info@, support@, security@, no-reply@, admin@ and **partnerships@** accept mail; programs@, fundraising@, projects@ and
  a random address do not (no catch-all).
- **partnerships@ is an alias, not a mailbox** ("alias expanded"): its mail is delivered into the internal mailbox, as is
  the mail of info@, support@, security@ and no-reply@. The internal mailbox is the only real mailbox. A partnerships Mail
  Center therefore needs partnerships@ to become a real mailbox first (hPanel → Emails: remove the alias, create the
  mailbox), then the token above. Serving partnerships@ out of the internal mailbox was rejected: it would expose that
  private mailbox and could not send as partnerships@.

## Production acceptance (2026-10-24)

Live since **19:00:06Z** (release `5679348`; its four migrations were applied at 16:02Z) and **20:14:03Z** (`86b8262`: the
sentences that say what the privileged two-step rule covers now name institutional mail — production showed a
partnerships-only role recommended two-step verification "because it can manage users, permissions, security settings or
payments"). Production still has no Mail API token, so the provider's side was accepted locally (below); production proves
everything else in real Chrome with disposable QA accounts and QA registry rows the provider cannot reach (made by
`deploy/qa/mail-center-qa.php qa-setup`), never with the owner's account:

- `institutional/scripts/mail-center-qa.mjs --phase production`: **36/36** on `5679348` (tag mc2887df) and **37/37** on
  `86b8262` (tag mc1d69c5; check M1b added). The partnerships account signs in with its password; its dashboard shows its
  own panels and no organisation figures; its menu is Overview / Mail / Account; the Mail Center lists only its
  partnerships row and says "not connected to the mail service yet" (200, not an error page); another mailbox, a guessed id
  and forged POSTs answer 404; a POST naming another sender or mailbox is handled for the authorised mailbox only; users,
  roles, permissions, settings, membership, leadership, recruitment, the registry and the audit log answer 403; the unread
  badge counts only partnerships; compose shows the partnerships sender and has no sender field; a send while not
  connected says "not connected … not sent"; no credential or provider detail in any page; every Mail Center answer is
  no-store / noindex / no-referrer / nosniff; Bangla renders. The mail administrator cannot change access without two-step
  verification; after enrolling through the real screens, revoking partnerships takes effect on the limited account's very
  next request (404, "no mailbox has been assigned") and re-granting works at once; the user page shows the access given
  through the role; the audit log lists the access changes and the refused send, without content; the registry says no
  token is configured and shows no credential; a role that requires two-step verification sends its staff to enrol
  first; `mail.manage_all` sees every enabled mailbox and nothing beyond mail; no console error. Screenshots: desktop and
  phone, light and dark, English and Bangla.
- Kit, per tag: `snapshot` → run → `qa-cleanup <tag>` (dry run, then `go`: 0 users, roles and mailboxes left) → `diff`
  (identical) → `forget` (state folder removed). Then the kit and its hash file were deleted and the docroot listed.
- Locally, against the real Hostinger Mail API (the developer token reaches one internal mailbox, standing in for
  partnerships@; only messages whose subject carries the QA tag were opened or changed, and all were deleted afterwards):
  **52/52** — send with attachments, delivery, one copy despite a double click, read, preview vs download headers, reply /
  reply all / forward with the provider's threading flags, flag / unread / Trash / restore, hostile HTML inert in the
  sandboxed frame, remote images held back, CR/LF refused, access administration with two-step enrolment, step-up,
  revoke and re-grant, the role's two-step requirement, `mail.manage_all`.

## Tests

`tests/Feature/Mail/` (with `Tests\Support\FakeMailProvider`, which records every provider call with the address it was
made for): access and isolation (`MailCenterAccessTest`), reading, hostile HTML, remote images, attachments, search
(`MailCenterReadingTest`), compose/reply/forward, header injection, forged sender, limits, duplicates, unknown outcomes,
message state (`MailCenterSendingTest`), registry, access grid, step-up, MFA policy, audit of changes
(`MailCenterAdministrationTest`), secrets, headers, routes, audit content, dashboard, both languages
(`MailCenterPrivacyTest`), and the permission migration (`MailPermissionsMigrationTest`). Plus `HostingerMailWebhookTest`
and `MfaPolicyTest`.

Production acceptance: `deploy/qa/mail-center-qa.php` (read-only audit, MX and delivery checks; QA accounts and grants for
the browser run, removed afterwards) and `institutional/scripts/mail-center-qa.mjs` (real Chrome).
