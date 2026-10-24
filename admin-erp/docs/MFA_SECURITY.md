# Two-step verification (MFA) for the admin panel

The one reference for how staff accounts of `admin.provatferi.org` use two-step verification: an authenticator app
(time-based one-time passwords, RFC 6238) on top of the password. It covers **staff accounts only**. The public Member
Portal has its own, separate authentication and is not affected.

The code lives here:

| Piece | Where |
|---|---|
| TOTP engine (parameters, replay protection, QR code) | `app/Services/Mfa/TotpService.php` |
| Recovery codes (generation, keyed hashing, matching) | `app/Services/Mfa/RecoveryCodes.php` |
| Every state change (enrol, verify, recovery, regenerate, disable, reset) | `app/Services/Mfa/MfaManager.php` |
| Who must use it | `app/Support/Mfa/MfaPolicy.php` |
| Session state (pending challenge, verified, recent confirmation) | `app/Support/Mfa/MfaSession.php` |
| Rate limits | `app/Support/Mfa/MfaThrottle.php` |
| The gate on every web request | `app/Http/Middleware/EnsureMfa.php` (+ `EnsureActiveAccount.php`) |
| The gate on staff API tokens | `app/Http/Middleware/EnsureApiMfa.php` (`mfa.api`) |
| Step-up for sensitive actions | `app/Http/Middleware/RequireRecentMfa.php` (`mfa.recent`) |
| The challenge pages | `app/Http/Controllers/Auth/MfaChallengeController.php`, `resources/views/auth/mfa-challenge.blade.php` |
| Admin → Account security | `app/Http/Controllers/Admin/AccountSecurityController.php`, `resources/views/admin/account/*` |
| A role's requirement | `app/Http/Controllers/Admin/RoleMfaController.php` (the card on the role screen) |
| Audit trail | `app/Services/SecurityEventLogger.php`, table `security_events` |
| Security e-mails | `app/Notifications/MfaSecurityNotification.php`, `app/Jobs/SendMfaSecurityNotification.php` |
| Break-glass reset and status | `php artisan mfa:reset`, `php artisan mfa:status` |
| Tests | `tests/Feature/Mfa/*` (100 tests) |

## Current authentication audit (2026-10-17)

What staff authentication looked like before this work, read from the code and, for the cookie flags, from production.

| Area | Before | Now |
|---|---|---|
| Sign-in | Breeze `LoginRequest`: `Auth::attempt` signed the person in as soon as the password matched. Passwords are bcrypt (Laravel's hasher). | The password is only *checked* (`validate`); an account with two-step verification is NOT signed in until a code is entered. |
| Inactive accounts | `status` was never checked: a deactivated account could still sign in, and its open sessions kept working. | Sign-in refuses an inactive account with the same message as a wrong password. `EnsureActiveAccount` signs an already-open session out at its next request. |
| Rate limits | Sign-in only: 5 failures a minute per e-mail + address. Password confirmation: none. | Also per account across all addresses, per address across accounts (larger allowance), and limits on every two-step form and on password confirmation. See "Rate limiting". |
| Password reset | 60-minute token, one request a minute. The other sessions of the account stayed open. | Every session of the account ends, the remember-me token is rotated and every API token is revoked. Two-step verification is untouched. |
| Password change | Other sessions stayed open. | Every other session ends (this one stays). |
| Password confirmation | Laravel's default: valid for 3 hours. | Unchanged for other pages; the two-step pages require it within **15 minutes**. Now rate-limited. |
| Sessions | Database driver, 120 minutes. Production cookie: `Secure; HttpOnly; SameSite=Lax` (seen on 2026-10-17). The id was renewed at sign-in; sign-out invalidated the session and the CSRF token. | Unchanged, plus: the id is renewed again when the second step completes. |
| Remember me | Laravel's recaller cookie signed the account in on its own. | It still restores the account, but only up to the challenge: a remember-me cookie never replaces the code. |
| Staff API tokens | `POST /api/v1/auth/login` issued a Sanctum token (they never expire) from e-mail and password alone, with no rate limit and no status check. That would have bypassed any two-step verification. | Same rules as the web: rate limits, inactive refused, a current code required for an account that uses two-step verification, no token for an account that must enrol. Tokens older than the activation are refused. |
| Access control | Custom roles and permissions; `super_admin` passes every check (`Gate::before`); routes use `permission:{module}.{action}`. | Unchanged. Two-step requirements are attached to roles and capabilities (see "Who must use it"). |
| Audit | No security audit trail. | `security_events`, written for every two-step change and for session endings (see "Audit trail"). |
| Trusted proxies | Cloudflare's ranges only (`App\Support\CloudflareProxies`), so `request()->ip()` is the visitor, never a forged header. | Unchanged; the rate limits and the audit trail depend on it. |
| CSRF | Laravel's web-group token check on every form, no exceptions. | Unchanged; every two-step form is covered (tested). |

## How it works

```
password ok ──► account has two-step? ── no ──► signed in (as before)
                     │ yes
                     ▼
            challenge open (10 min, session only, NOT signed in)
                     │ code or recovery code
                     ▼
            signed in, new session id, "verified" for this session
                     │
                     ├─ a page needs a fresh confirmation (mfa.recent) → code again if the last one is > 15 min old
                     └─ "remember me" cookie in a new browser → back to the challenge, never straight in
```

### The TOTP library

- **pragmarx/google2fa 9.1.0** does the arithmetic: HMAC-SHA1, 6 digits, 30-second steps, constant-time comparison
  (`hash_equals`), secrets from `random_int`. It is the TOTP engine Laravel's own Fortify uses, widely reviewed, and has no
  network access. Nothing cryptographic is written in this project.
- **bacon/bacon-qr-code 3.1.1** draws the QR code as inline SVG on the server, so no third-party QR service ever sees a
  secret.
- They bring `paragonie/constant_time_encoding` 3.1.3 (base32) and `dasprid/enum` 1.0.7.
- Fortify itself was not used: it replaces the whole sign-in stack (routes, controllers, views) that Breeze already
  provides here, which is a much larger change than the second step needs.

Parameters (`TotpService`): issuer **Provatferi ERP**, account label = the e-mail address, a 160-bit secret
(32 base32 characters), SHA-1, 6 digits, 30 seconds. These are what every authenticator app supports: Google
Authenticator, Microsoft Authenticator, 1Password, Bitwarden, Authy and others.

**Clock window.** One step either side (`WINDOW = 1`). A code is accepted while the server is in its own 30-second step
or the step just before or after it, which covers a phone clock that is a little off plus typing time. Anything older or
newer is refused. The server clock is NTP-synchronised (Hostinger). Tests move the clock with `travel()`, because the step
is read from the application clock (`now()`).

**Replay.** The step of the last accepted code is stored (`mfa_last_used_step`). A code of that step or an earlier one is
refused even inside the window, so a code that was seen (over a shoulder, by a phishing page or by a proxy log) cannot be
used again. The check locks the account's row, so two requests with the same code cannot both pass.

### Database (additive migrations, MariaDB)

`2026_10_17_100000_add_mfa_columns_to_users_table`, all columns nullable, so every existing account simply has two-step
verification off:

| Column | Holds |
|---|---|
| `mfa_secret` | The active secret, **encrypted** (Eloquent `encrypted` cast: AES-256-CBC with `APP_KEY`, authenticated) |
| `mfa_pending_secret`, `mfa_pending_created_at` | An enrolment in progress (encrypted; expires after 30 minutes) |
| `mfa_recovery_codes` | JSON list of **keyed hashes** of the unused recovery codes |
| `mfa_confirmed_at` | When two-step verification was turned on (null = off) |
| `mfa_last_used_step` | The TOTP step of the last accepted code (replay protection) |
| `mfa_enrollment_required_at` | Set by a break-glass reset: the account must enrol again |

`2026_10_17_100100_add_mfa_required_to_roles_table`: `roles.mfa_required` (boolean, default false).

`2026_10_17_100200_create_security_events_table`: the audit trail (see below).

None of the `mfa_*` columns is fillable or serialised (`$hidden`). Only `MfaManager` writes them.

## Enrolment

Admin → **Account security** (sidebar, Account group; also in the profile menu at the top right).

1. **Set up two-step verification.** The password must have been confirmed within the last 15 minutes (the page asks).
2. The server creates a **new** secret, stores it encrypted as *pending*, and shows it once as a QR code and as a manual
   key (groups of four), with the issuer and the account name. The page is sent with `Cache-Control: no-store` and
   `X-Robots-Tag: noindex`.
3. The person scans it and types the 6-digit code their app shows.
4. Only a correct code from the **new** secret turns it on. Then, in that same response and never again, ten recovery
   codes are shown with *Copy*, *Download as text file* and *Print*.
5. The secret is never shown again. The Security page afterwards shows only "on", the date and how many recovery codes are
   left.

An enrolment that is not finished within 30 minutes expires, and the next visit starts a new secret. *Show a new QR code*
starts again at any time (for example when the first one was scanned into the wrong app).

**Moving to a new phone** (*Move to a new phone or app*) is the same flow, plus a current code from the old app within the
last 15 minutes. The old app keeps working until the new one is confirmed, so a half-finished move can never lock the
account out. Confirming it issues new recovery codes. The old app and the old codes stop working.

## Sign-in (the challenge)

After a correct password, an account with two-step verification is **not** signed in. The session records whose
challenge this is (`mfa.pending`), whether "remember me" was ticked, and when. Every admin page, form and JSON endpoint
answers the visitor with a redirect to the challenge (or 401 for JSON) during this time.

- The page asks for the 6-digit code (`inputmode="numeric"`, `autocomplete="one-time-code"`, so phones and password
  managers offer it). It is Bengali or English with the rest of the sign-in pages, and works in light and dark themes and
  without JavaScript.
- **Use a recovery code instead** switches to the recovery form. **Back to sign-in** abandons the challenge.
- A correct code signs the person in. At that moment the session id is renewed again, "remember me" is honoured, and the
  session is marked as verified for this account.
- A wrong code gets one message whatever the reason (wrong, old, reused, malformed). A wrong recovery code gets one
  message whether it never existed or was used before.
- The challenge expires after **10 minutes**. The password must then be entered again.

**Remember me** never skips the second step. A remember-me cookie restores the account in a new browser, `EnsureMfa`
signs that browser out again at once (its session id renewed, other devices untouched), and the challenge opens for the
same account. The same happens to a session that was signed in before two-step verification was turned on.

## Recovery codes

- Ten codes, `XXXXX-XXXXX`, from a 32-letter alphabet without look-alikes (no 0/O, 1/I): 50 random bits each, from
  `random_int`.
- Stored only as **HMAC-SHA256** under a key derived from `APP_KEY` (`hash_hmac('sha256', 'provatferi-mfa-recovery-codes-v1',
  APP_KEY)`), so the table alone cannot even be checked against guesses. Compared with `hash_equals`, all of them every time.
- Each works **once**: its hash is removed when used. The Security page and the e-mail show how many are left.
- Typing is forgiving: case, spaces and hyphens do not matter.
- **New recovery codes** (Account security → Recovery codes) needs a password confirmed within 15 minutes AND a code
  within 15 minutes. Every old code stops working at that moment.
- Recovery codes are **never e-mailed**, logged or shown again.

## Who must use it

`MfaPolicy` decides, and nothing in it looks at a user id. An account **must** use two-step verification when any of these
holds:

1. **A role requires it.** Admin → System → Roles → the role → *Two-step verification* card. Turning it on raises
   protection and needs the password confirmed within 15 minutes (or, for an administrator who uses two-step verification,
   a code within 15 minutes). Turning it off lowers protection and needs an administrator who uses two-step verification,
   with a code from the last 15 minutes. Only a Super Admin can change the **Super Admin** role's requirement. The
   role form itself cannot change it.
2. **The privileged-account rule is on** (Account security → *Privileged accounts*, Super Admin only, with a recent code),
   and the account is privileged: it holds `super_admin`, or any of `users.create`, `users.update`, `users.delete`,
   `settings.update`, `payments.create`, `payments.update`, `payments.delete`, `payments.approve`, `mail.access`,
   `mail.manage_all`, `mail.manage_access`, or any mailbox grant (directly or through a role — docs/MAIL_CENTER.md). A
   future financial, user-management or mailbox role is covered the moment it gets such a permission or grant. The setting
   is `security.mfa_privileged_enforced` (never public). (Until 2026-10-24 the list named `mail.manage`, which no role had.)
3. **A break-glass reset** happened (`mfa_enrollment_required_at`).

**After deployment nothing is required of anyone.** No role has the flag and the privileged rule is off, so no deployment
can force the only Super Admin into enrolment (see "Owner roll-out").

An account that must use it but has not enrolled can still sign in with its password, and then reaches **only** the
Security page, the set-up pages, password confirmation, the language switch and sign-out. Everything else redirects to the
Security page (403 for JSON). Gaining such a role takes effect at the account's next request and is recorded
(`mfa.newly_required`). Losing a role never deletes a secret or recovery codes.

## Step-up (recent confirmation)

`->middleware('mfa.recent')` lets a request through only when **this session** entered a code (sign-in or the
*Confirm it's you* page) within the last 15 minutes. The time is stored server-side (`mfa.confirmed_at`). No field, header
or cookie the browser sends can stand in for it (tested). Without it, the person is sent to *Confirm it's you* and back:
to the same page after a GET, to the page the form was on after a POST (the form is never re-submitted blindly). JSON gets
423.

- `mfa.recent`: the account must use two-step verification at all (otherwise it is sent to set it up; JSON gets 403).
- `mfa.recent:lenient`: an account without it passes (then whatever else the route requires applies).

It is used today for the privileged rule and for lifting a role requirement. It is designed for the sensitive actions to
come (role and permission changes, payment credentials, API secrets): put the middleware on the route.

## Turning it off

Needs the current password **and** a current code (or a recovery code), and only where `MfaPolicy::canDisable` allows it:
an account that must use it (any rule above) cannot turn it off, and can only move it to a new phone. A Super Admin may
turn their own off only while no rule requires it (the staged roll-out). Once the owner switches the privileged rule or
the Super Admin role's flag on, no Super Admin can.

## API tokens

`POST /api/v1/auth/login` (staff) follows the web sign-in's rules:

- the same rate limits (`LoginRequest` + `MfaThrottle`), and an inactive account gets the same answer as a wrong password;
- an account with two-step verification must also send `code`, a current 6-digit code: without it the answer is
  `401 {"mfa_required": true}`, a wrong code `422`, too many `429`; codes share the account's limit with the web challenge;
- an account that must enrol gets `403` and no token until it has enrolled in the admin panel.

Turning two-step verification on revokes every older token of the account. `mfa.api` (on `/auth/me` and `/admin/*`)
refuses and deletes any token older than the activation, and refuses an account that must enrol. Recovery codes are not
accepted by the API. A password reset and a break-glass reset revoke every token too.

## Sessions and password reset

- **Sign-in:** the session id is renewed after the password and again after the code.
- **Sign-out:** the session is invalidated (destroyed on the server) and the CSRF token renewed. The "verified" mark goes
  with it.
- **Password reset** (forgot password): sets the new password, rotates the remember-me token, ends **every** session of
  the account and revokes its API tokens (`SessionTerminator`), and records `auth.sessions_ended_after_password_reset`. It
  does **not** touch two-step verification or the recovery codes: the next sign-in, with the new password, still asks for a
  code. A reset link is a password tool, never a way around the second step. Recovery codes stay valid through a password
  reset, because they are the person's fallback for a lost phone, not for a lost password.
- **Password change** (Profile): ends every other session and rotates the remember-me token; the current session stays.
- **Deactivating an account** signs it out at its next request (`EnsureActiveAccount`).
- No long-lived "trust this device" cookie exists. "Remember me" restores the account but never skips the code.

## Rate limiting

Only failures count, and every pause ends on its own (a cool-down, never a ban). Nothing is keyed on the IP address alone:
many people in Bangladesh share one address behind their ISP's NAT. The account and the session carry the tight limits,
and the address has a far larger allowance (one address trying many accounts). A request is held back when any of its keys
is exhausted.

| Form | Per account | Per session | Per address |
|---|---|---|---|
| Sign-in (password) | 20 / 15 min, plus 5 / min for this account from this address | — | 60 / 10 min |
| Challenge code (web + API) | 5 / 5 min and 20 / hour | 5 / 5 min | 40 / 10 min |
| Recovery code at sign-in | 5 / 15 min | 5 / 15 min | 40 / 15 min |
| Enrolment code | 6 / 10 min | — | 40 / 10 min |
| Turning it off, new recovery codes (every regeneration counts) | 5 / 15 min | — | 40 / 15 min |
| Step-up code | 5 / 5 min | — | 40 / 10 min |
| Password confirmation | 10 / 15 min | — | 60 / 15 min |

With the password required first and 5 wrong codes per 5 minutes (20 per hour), guessing a 6-digit code is not
practical. The moment an account's allowance runs out is audited once (`mfa.challenge_locked`), not every mistake.

## Audit trail

Table `security_events`: `user_id` (whom it is about), `actor_id` and `actor_type` (`user`, `system` or `console`),
`event`, `ip_address`, `user_agent` (255 characters), `context` (JSON facts), `created_at`. The Security page shows the
person's latest six.

| Event | When |
|---|---|
| `mfa.enrollment_started` | A new secret was created (set-up or a new QR code) |
| `mfa.enabled` / `mfa.authenticator_replaced` | Turned on / moved to a new app (with `api_tokens_revoked`) |
| `mfa.disabled` | Turned off |
| `mfa.recovery_code_used` | A recovery code was used (with `remaining`) |
| `mfa.recovery_codes_regenerated` | New recovery codes |
| `mfa.challenge_locked` | An account's allowance for codes ran out (`scope`: challenge, recovery, step-up, api) |
| `mfa.reset_by_administrator` | Break-glass reset (actor `console`, with the reason, sessions ended, tokens revoked) |
| `mfa.newly_required` | An account became required by a role, a permission change or the privileged rule (actor = the admin) |
| `mfa.role_requirement_changed` | A role's flag changed (role, on/off, accounts now required) |
| `mfa.privileged_enforcement_changed` | The privileged rule changed |
| `auth.sessions_ended_after_password_reset` | Password reset ended the account's sessions and tokens |
| `auth.inactive_account_signed_out` | A deactivated account's session was ended |

`SecurityEventLogger` refuses outright (an exception, so the mistake is found in tests) any context key named `secret`,
`code`, `codes`, `recovery_code`, `recovery_codes`, `password`, `token`, `session`, `session_id`, `otp`, `totp` or `uri`.
If the table itself fails, the sign-in still works and the failure is logged without request data.

## Security e-mails

`MfaSecurityNotification`, a security-class message (see `docs/EMAIL_ARCHITECTURE.md`): From
`no-reply@provatferi.org`, Reply-To `security@provatferi.org`, in the person's admin language. Sent for: turned on, moved
to a new app, turned off, new recovery codes, a recovery code used (with how many are left), and a break-glass reset. Each
says what happened, when (Dhaka time), from which address and browser (a short "Chrome / Windows", never the whole
user-agent), and to reply at once if it was not them, and that Provatferi never asks for a code or a password.

They never contain a secret, a QR code, a code, a recovery code, a password, or a link that signs anyone in (tested). They
are sent after the response (there is no queue worker on this host); a failure to send is logged with the event and the
exception class only.

## Recovery when everything is lost (break-glass)

There is **no** reset page, no reset API and no master code: the reset needs the server. Use it only when the person has
lost **both** the authenticator **and** every recovery code. Someone with a recovery code signs in with it and moves to a
new phone themselves.

1. **Verify the person** out of band: a phone call to a number already on record, or in person. Never on the strength of an
   e-mail alone.
2. Optionally check the account first (prints status only, never a secret):
   `php artisan mfa:status person@example.org`
3. Run on the server, from the live app (on this host: a one-shot cron job in hPanel with the absolute path of
   `laravel-admin/artisan`, see `deploy/README.md`, deleted right after it ran; or SSH):

   ```
   php artisan mfa:reset person@example.org --reason="lost phone and codes; identity confirmed by phone call with <who>" --force
   ```

   Without `--force` it only reports. The reason (at least 10 characters) is kept in the audit trail.
4. The command destroys the secret and every recovery code, ends every session, rotates the remember-me token, revokes
   every API token, marks the account as having to enrol again, records `mfa.reset_by_administrator` (actor `console`)
   and e-mails the account.
5. The person signs in with their password, lands on the Security page, and must set up two-step verification again
   before anything else.

**If the only Super Admin is locked out** the same command is the way back. It needs control of the hosting account,
which is the owner's. Keep the recovery codes safe so this is never needed.

## Owner roll-out (staged)

Deployment changes nothing for anyone: two-step verification is available to everyone and required of no one.

1. **The owner enrols** (Account security → *Set up two-step verification*), saves the ten recovery codes away from the
   phone (password manager, printed copy), and confirms *I have saved these codes*.
2. **Check it:** sign out, sign in, enter the app's code. Optionally sign in once with one recovery code (it is then used
   up: generate a new set afterwards and save that one).
3. **Then make it mandatory**, when ready: Account security → *Privileged accounts* → *Require for privileged accounts*
   (the card says how many privileged accounts have not set it up yet; each is asked to at its next page). Or, more
   narrowly, the Super Admin role's *Two-step verification* card.
4. From then on, no Super Admin can turn it off; a new phone is *Move to a new phone or app*.

## Troubleshooting

| Problem | What to do |
|---|---|
| "The code is not correct" every time | The phone's date and time must be set automatically (network time). The code must be the one showing now. A code that was just used is refused: wait for the next one (at most 30 seconds). |
| "Too many attempts" | Wait: 5 minutes for codes, 15 minutes for recovery codes, turning it off and new codes. Nothing is permanent. |
| New phone, old phone still works | Account security → *Move to a new phone or app*. |
| Phone lost, recovery codes available | Sign in with a recovery code; the Security page opens: move to a new phone and create new recovery codes. |
| Phone and every recovery code lost | Break-glass reset (above). |
| The challenge says the sign-in expired | More than 10 minutes passed after the password: enter it again. |
| A staff API client gets `401 mfa_required` | Send `code` (the current 6-digit code) with the e-mail and password. |
| A staff API client gets `401` on a token that used to work | The token was older than two-step verification's activation, or a password reset or break-glass reset revoked it: sign in again. |

**Never rotate `APP_KEY` casually.** The secrets are encrypted with it and the recovery-code hashes are keyed by it. A new
key without `APP_PREVIOUS_KEYS` makes every secret unreadable, and every recovery code stops matching either way. If it
must be rotated, keep the old key in `APP_PREVIOUS_KEYS`, and expect every person to generate new recovery codes. A person
whose secret cannot be read can be reset with `mfa:reset`.

## What must never be logged, stored or shown

- The TOTP secret, active or pending, in any form (QR code, `otpauth://` URI, manual key): only the set-up page shows it,
  once, with `no-store`. It is never logged, e-mailed, returned by any API, kept in the session, or printed by a command or a
  QA report.
- A code or a recovery code (plain or hashed) in logs, the audit trail, e-mails, URLs or the session.
- Passwords, session ids, the session cookie, remember-me tokens and API tokens, in logs and in the audit trail.
- The `mfa_*` columns in any JSON (`$hidden` on `User`).
- Sensitive parameters are marked `#[\SensitiveParameter]`, so a stack trace does not print them.

## Tests

`tests/Feature/Mfa/` covers the order's list A–T and more:

| File | Covers |
|---|---|
| `MfaSignInTest` | A no-MFA account; C valid code (new session id); D invalid; E old, future and reused codes; F nothing reachable before the code (pages, forms, JSON, API, forged flags); K remember-me; L sign-out; expiry; deactivated accounts |
| `MfaEnrolmentTest` | Password confirmation; QR + key; only a correct code turns it on; codes shown once; secret never again; expiry; moving to a new phone; B role requirement → enrolment pages only; gaining and losing a role |
| `MfaRecoveryCodesTest` | G once; H reuse = same answer as never valid; I regeneration retires all old codes, needs password + code; keyed hashes only |
| `MfaPolicyTest` | M disabling needs password + code; N required accounts cannot disable; role switch rules; Super Admin role; privileged rule |
| `MfaStepUpTest` | `mfa.recent`: fresh code, cannot be forged, password ≠ code, recovery code, paused, strict/lenient, JSON 423 |
| `MfaRateLimitTest` | Q every limit, per account across browsers and addresses, temporary |
| `MfaApiTokenTest` | Token login rules, revocation, outdated tokens, shared limits, no MFA material in responses |
| `MfaSecretSafetyTest` | R logs and audit trail; S e-mails; T JSON, admin pages, public endpoints, client bundles; encryption at rest |
| `MfaResetAndAuthorizationTest` | O no in-app reset route, no IDOR, no mass assignment; break-glass command; P CSRF on every form; J password reset |
| `MfaSchemaTest` | Additive, nullable columns; existing accounts unaffected; audit table shape |

## Acceptance on production

Live since 2026-10-17 20:12 UTC (release `b6e0ecb3087f-2026-10-1719341`), accepted the same evening with 68 of 68 checks in
real Chrome. Two halves, rerunnable:

- `deploy/qa/mfa-qa.php` (server, CLI only, uploaded under a one-off name, one mode per cron): `audit` (configuration,
  schema, two-step state in counts, server clock against Cloudflare's), `inventory`, `snapshot` / `diff` / `purge <tag> go`
  / `forget`, and `reset-qa <email>` (the break-glass command, refused for anything but a `qa-mfa-…` account). It prints
  counts, ids and flags only, never a secret or a code. It recognises QA data by its markers: accounts `qa-mfa-…`, roles
  `QA-MFA-…`, the audit rows about or by them, sessions whose browser string carries `provatferi-mfa-qa/`.
- `institutional/scripts/mfa-qa.mjs` (browser): the owner's login only creates the QA roles and accounts and switches the
  QA role's requirement on; it never enrols the owner and never signs the owner out (a sign-out would rotate the owner's
  remember-me token). Codes are computed in memory from the key on the set-up page. Screenshots mask the QR code, the key,
  the recovery codes and every typed field. With `--wait-reset N` it ends by waiting for the `reset-qa` e-mail.
