# Public contact, social links and map location (admin-managed)

The organisation's public contact details (e-mail, phone, address in Bangla and English, Facebook / LinkedIn /
YouTube, a map location) are edited in **one** place — **Settings** in this admin panel — and every public page reads
them from there. Written 2026-10-14, when the screen was found unable to save at all.

## Canonical source

| Layer | Owner |
|---|---|
| Source of truth | the `settings` table (`site.*` keys), edited on **Admin → Settings** (`/admin/settings`) |
| Field list, validation, normalisation | `App\Support\SiteSettings` — the only mapping from a form field to a setting key |
| Link, map and phone rules | `App\Support\PublicLinks` |
| E-mail role rules | `App\Support\InstitutionMail::publicContactProblem()` (see `EMAIL_ARCHITECTURE.md`) |
| What the public API returns | `App\Support\PublicSettingsPayload` → `GET /api/v1/settings` |
| What the site shows | `institutional/lib/contact-resolve.ts` (pure) + `lib/contact.ts` (fetch) — header, footer, Contact page, map action, structured data, error pages |

Nothing on the public site hard-codes a value the ERP owns. The original values in `institutional/lib/content.ts`
(`org.*`) remain only as an **outage fallback**: they are used when the ERP cannot be reached, or when it is an older
build that does not return `social`. They never override what a current ERP returned — a phone number, address or
social link the admin has removed stays removed.

## Setting keys

All keys are public rows (`is_public = true`, `group_name = 'site'`).

| Key | Form field | Meaning |
|---|---|---|
| `site.email` | `site_email` | public / general contact e-mail (role: `info@`) — required |
| `site.support_email` | `site_support_email` | e-mail for applicants' questions (role: `support@`) — required |
| `site.phone` | `site_phone` | optional; 6–15 digits, `+`, spaces, `( ) . -` |
| `site.address` / `site.address_en` | `site_address` / `site_address_en` | postal address, Bangla / English (plain text, line breaks kept) |
| `site.{facebook,linkedin,youtube}_url` | `site_{platform}_url` | the page address |
| `site.{facebook,linkedin,youtube}_enabled` | `site_{platform}_enabled` | `'true'` / `'false'` — a link is shown only when this is on **and** the address is valid |
| `site.map_url` | `site_map_url` | a Google Maps location / share link (see below) |
| `site.map_label` / `site.map_label_en` | `site_map_label` / `site_map_label_en` | optional place name, Bangla / English |
| `site.name_bn`, `site.name_en`, `site.short_name`, `site.acronym`, `site.tagline`, `site.seo_title`, `site.seo_description`, `site.website_url`, `site.literature_url` | `site_…` | identity, search-engine text and related sites (unchanged meaning) |

**Form field names use underscores, not the dotted key.** PHP rewrites every `.` in an incoming form-field name to `_`
before Laravel sees the request (`site.phone` arrives as `site_phone`). The screen used dotted names and a validator
keyed on dotted names, so every real browser save failed with four phantom "required" errors and nothing was ever
saved — the production rows still carried their seed timestamps and `updated_by = NULL` (found 2026-10-14). Laravel's
test client skips PHP's parser, so the old tests passed against a form no browser could submit. Therefore:
`SiteSettings::fields()` is the one mapping; `tests/Feature/AdminFormFieldNamesTest.php` fails on a dotted field name in
any admin view; `tests/Concerns/SubmitsSettingsLikeABrowser.php` posts the *rendered* form through `parse_str`.

## E-mail roles

`info@provatferi.org` is the public contact, `support@provatferi.org` the support address. Saving a public or support
address that is the internal mailbox (`admin@`), an automated sender (`no-reply@`, `noreply@`, `donotreply@`), or the
security mailbox (`security@`) is **refused with a message naming the address to use instead** — never saved silently.
The API applies the same rule again, so a bad row written any other way (a migration, a direct write) is returned as
`null` rather than published. `admin@` never appears in a public response.

## Social links

Facebook, LinkedIn and YouTube. Each has an address and an on/off switch; a link renders only when the switch is on and
the address passes the rules below. Order is fixed: Facebook, LinkedIn, YouTube.

* `https://` only. No `javascript:`, `data:`, `http:` or scheme-less input; no user-info (`https://user@…`), no port other
  than 443, no spaces, control characters, quotes, `<`, `>` or backslashes; at most 500 characters.
* The host must be the platform's own: Facebook — `facebook.com`, `fb.com`, `fb.me`; LinkedIn — `linkedin.com`;
  YouTube — `youtube.com`, `youtu.be` (the registered domain and its sub-domains only; no punycode `xn--` look-alikes).
* A switched-on platform needs an address. Switching a link off keeps its address in the form.
* Stored as the normalised URL, never as typed. The site draws professional inline SVG icons (no emoji, no icon font)
  and opens links with `target="_blank" rel="noopener noreferrer"` and an accessible name that says it opens a new tab.

## Map location

* **A link, not an embed.** The field takes a Google Maps location or share link; **no HTML, no iframe, no embed code is
  stored or rendered, anywhere**. A pasted `<iframe …>` or a `…/maps/embed…` / `…/maps/api/…` address is refused with a
  message that says to paste the share link instead.
* Accepted: `google.com`, `www.google.com`, `google.com.bd`, `www.google.com.bd` with a path under `/maps`;
  `maps.google.com` (not the bare host); `maps.app.goo.gl/<code>`; `goo.gl/maps/<code>`. `https://` only, at most 1000
  characters, same character rules as above.
* Optional place name in Bangla and English (plain text, 120 characters).
* The public site shows a **"View on Map" / "মানচিত্রে দেখুন"** action on the Contact page (with the address) and in the footer,
  opening in a new tab with `rel="noopener noreferrer"`. No third-party map script is loaded.

## Bangla and English

* The Bangla and English pages read the same settings. `address` / `address_en` and `map_label` / `map_label_en` are
  separate values; e-mail, phone and links are language-neutral.
* No English is invented. When `address_en` is empty, the English page shows the Bangla address with the project's
  translation note ("translation not yet available") — the same policy as every other content field.
* Labels (`View on Map`, `Social media`, …) come from the site's i18n dictionary (`lib/i18n/{bn,en}.ts`).

## API contract — `GET /api/v1/settings`

Additive and backward compatible. Unchanged keys: `organization`, `contact.{email,phone,address,facebook_url}`, `seo`,
`links`. Added: `contact.support_email`, `contact.address_en`, `social[]` (`{platform, url}`, only enabled and valid
links), `location` (`{map_url, label, label_en}` or `null`). `contact.facebook_url` is kept as an alias of the Facebook
entry in `social`. `social` is present (possibly empty) in every current response — the site reads that as "this ERP is
current, trust it", and its absence as "an older ERP". Only `is_public` rows are ever read; SMTP settings, the internal
mailbox and every private key are never in the response. `tests/Feature/Api/PublicSettingsContractTest.php` pins the
shape, the omissions and the hostile-row behaviour.

## Cache and revalidation

1. The site fetches the settings with the Next cache tag **`site-settings`** (plus a 300 s safety-net time-to-live).
2. Any save or delete of a public `settings` row — the admin form, a migration, a direct model write — queues that tag
   (`App\Observers\SettingObserver`, registered on `App\Models\Setting`). Several rows in one request cost **one** call.
3. The Settings update route carries `revalidate.public`, so the signed Laravel → Next call
   (`App\Services\PublicSiteRevalidator`, HMAC v2 with nonce and replay guard — see `PUBLIC_SITE_CACHE.md`) goes out
   **before** the admin's browser is told "saved". The site expires the tag with `revalidateTag(tag, { expire: 0 })`.
4. The HTML is not cached by the CDN (`cf-cache-status: DYNAMIC`, `Cache-Control: private, no-store`).

Measured on a production build of the site against a real local ERP: the save response in ≈ 0.7 s, and the changed value
visible on a plain GET of the public page ≈ 25–100 ms after that. Caching is not disabled anywhere.

`App\Models\Setting::booted()` clears the per-key cache from block closures that return nothing. Do not turn them back
into arrow functions: `Cache::forget()` returns `false` when a key was not cached (array / file stores), and Eloquent
**stops calling the remaining listeners of an event when one returns `false`** — which silently skipped this observer.
(Production's database cache store always returns `true`, so production was never affected; the tests were.)

## Permissions and security

* View the screen: `settings.view`. Save it: `settings.update` (route middleware `permission:`). Of the seeded roles only
  `super_admin` holds the `settings` module; Regional and Membership admins do not and receive 403.
* CSRF: the route is in the `web` group (token required). Mass assignment: a request can write **only** the keys listed in
  `SiteSettings::fields()`; any other field it carries (`is_public`, `key`, another setting) is ignored. Only changed rows
  are written (with `updated_by`), and each save is logged (`Site settings changed.`, user id and keys, never values).
* Output: values are escaped on every surface; the site's JSON-LD goes through `safeJsonLd`. The site validates links
  again (`lib/public-links.ts`) — a second copy of the rules, deliberately, so a bad row can never become a public link.
* Admin screen: inline validation under each field, success / failure alerts, no double submission, an unsaved-changes
  warning (`public/js/provatferi-form-guard.js`), labelled controls (the shared bilingual field is wired with
  `aria-labelledby`), mobile layout, light and dark themes, a real on/off switch for the three social links
  (`SWITCH-001` in `resources/css/provatferi-admin.css`).
* Blade trap on this screen: text passed to an `<x-admin.*>` tag is **bound** (`:label="__('…')"`), never interpolated
  (`label="{{ __('…') }}"`). Blade escapes an interpolated attribute and the component escapes it again, so an English
  string with an apostrophe showed as `Website&#039;s main address` (seen on production in the English admin, 2026-10-14;
  `test_english_labels_with_an_apostrophe_are_escaped_once_not_twice`). About fifty other admin views still use the
  interpolated form and have the same flaw wherever an English string contains `'` or `&`.

## Deployment and verification

* One data migration, `2026_10_14_090000_add_public_contact_settings`, adds the new keys with `insertOrIgnore` (existing
  values are never touched; Facebook keeps working: its switch is seeded on when an address exists). Review `migrate-check`'s
  `--pretend` output before `migrate-apply`, as for every migration.
* Production acceptance: `deploy/qa/contact-settings-qa.php` (`snapshot` / `diff` / `restore` / `cleanup`: takes the real
  rows before the test and puts them back exactly, including `updated_by` and `updated_at`) and the real-Chrome script
  `institutional/scripts/contact-settings-qa.mjs` (desktop and mobile; every link checked for safety attributes and an
  accessible name; propagation timed; baseline restored in a `finally`).
* Tests: `PublicLinksTest`, `SiteSettingsTest`, `PublicContactSettingsTest`, `PublicSettingsContractTest`,
  `PublicContactSettingsMigrationTest`, `AdminFormFieldNamesTest`, `EmailIdentityTest` (ERP); `public-links`,
  `json-ld`, `contact`, `revalidate-v2`, `mail-identity` (site).
