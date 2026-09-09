# Mailbox roles and outbound mail policy

Five mailboxes exist on `provatferi.org`. Each has one job. Using the wrong one
is not a cosmetic mistake: it trains members to trust the wrong address, leaks
internal operational identity into public view, and makes real security reports
harder to distinguish from noise.

Provider is **Hostinger Email** — confirmed from the live DNS zone
(`MX 5 mx1.hostinger.com`, `MX 10 mx2.hostinger.com`), not assumed.

## The mapping

| Mailbox | Purpose | Public? | Sends automated mail? |
|---|---|---|---|
| `info@` | Main public contact. Contact page, general inquiries, `Organization` schema, footer. Default human reply-to. | **Yes** — this is the organisation's public identity | No |
| `support@` | Member and user support: application help, login and account problems. | Yes, but only in a support context | No |
| `security@` | Security reports, suspicious login notices, abuse reports. | Listed as the official security contact | No |
| `no-reply@` | Automated transactional mail: password resets, system notifications. | No | **Yes — this is the sender** |
| `admin@` | Internal ERP login identity and internal operational notices. | **No. Never.** | No |

### `admin@` is not a public address

It must not appear in the public footer, the Contact page, the `Organization`
JSON-LD, or any public API contact payload. It is a staff login and an internal
recipient. The public contact address is `info@`, stored in the `site.email`
setting and mirrored in `institutional/lib/content.ts`.

### `security@` is a contact, not an alarm system

Keep it published and monitored as the official security contact. Do not wire
automated per-event alerting to it yet — an alert channel nobody has agreed to
triage becomes noise, and noise is how real reports get missed.

## Sender policy

Automated mail is sent **from `no-reply@`**, never from `info@`. Mixing the two
means a member cannot tell a machine from a person, and replies to real
correspondence get lost among bounces.

```env
MAIL_FROM_ADDRESS="no-reply@provatferi.org"
MAIL_FROM_NAME="Provatferi Literary and Cultural Center"
```

A no-reply sender still owes the recipient a way to answer, so every message
carries a `Reply-To` chosen by message class:

| Message class | Reply-To | Config key |
|---|---|---|
| Password reset, login/account, membership application status | `support@` | `mail.reply_to.support` |
| General notifications, public-form acknowledgements | `info@` | `mail.reply_to.general` |
| Security notices | `security@` | `mail.reply_to.security` |
| Internal ERP/staff operational notices | `admin@` (as recipient) | `mail.reply_to.operations` |

In a Mailable:

```php
public function envelope(): Envelope
{
    return new Envelope(
        subject: 'Reset your password',
        replyTo: [config('mail.reply_to.support')],
    );
}
```

## SMTP settings

Verified 2026-09-09 by probing the endpoints directly, not from documentation.
Both advertise `AUTH PLAIN LOGIN`; max message size is ~46 MB.

| | Preferred | Fallback |
|---|---|---|
| `MAIL_HOST` | `smtp.hostinger.com` | `smtp.hostinger.com` |
| `MAIL_PORT` | `465` | `587` |
| `MAIL_SCHEME` | `smtps` (implicit TLS) | `smtp` (STARTTLS) |

Prefer **465/smtps**: TLS is established before the SMTP conversation begins, so
there is no cleartext window in which a downgrade could strip encryption. Port
587 advertises `AUTH` before `STARTTLS`, which is worth avoiding when there is a
choice.

Laravel 12 reads `MAIL_SCHEME`. `MAIL_ENCRYPTION` is legacy and is **not** read
by `config/mail.php` — setting it has no effect.

`MAIL_USERNAME` is the full mailbox address (`no-reply@provatferi.org`), and
each mailbox has its own password, distinct from the hosting account password.

## Credential handling

Mailbox passwords belong in the server's `.env` only. Never in the repository,
never in a committed `.env.example`, never pasted into chat or a report, and
never echoed by a script — a script may consume a secret and report only a
boolean or a length.

## Deliverability

The zone already carries SPF (`v=spf1 include:_spf.mail.hostinger.com ~all`) and
Hostinger DKIM (`hostingermail-a/b/c._domainkey`). DMARC exists but is
`p=none` with **no `rua=` reporting address**, so it neither enforces nor
reports — currently it buys nothing. Adding a reporting address, then moving to
`p=quarantine` once the reports are clean, is the natural next step and is
tracked separately.
