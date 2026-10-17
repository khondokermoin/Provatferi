# Leadership / Key Persons

The permanent, core people of Provatferi Literary and Cultural Center (PLCC) — the founder, the executive director,
directors, advisors — are managed in **one** place, **Admin → Institution → Leadership / Key Persons**, and shown on the
public site at `/leadership` (Bangla) and `/en/leadership` (English), on each person's own page, and in a small section
of the homepage. Built 2026-10-15/17.

Nothing in the system is pre-filled. **No name, biography, qualification or designation is invented or hard-coded**: a
profile exists only because an administrator typed it. (The first real profiles are expected to be Mehedi Hasan Roney and
Khandaker Moin Hossain; their designations are the owner's to enter — the code contains neither.)

## Leadership is not the Committee

| | Committee (`/committee`, Organization menu) | Leadership / Key Persons (`/leadership`, Institution menu) |
|---|---|---|
| Who | the members of a **term** | permanent core people, whatever the term |
| Time | a committee has a term, members come and go with it | no term, no end date; a person stays until archived |
| Tables | `committees`, `committee_members`, `committee_positions`, `organizational_*` | `leadership_profiles`, `leadership_profile_slugs` — nothing else |
| Links to other domains | positions, units, the member registry | **none**. The only foreign key of a profile points at `users` (who created / edited it); a retired address points back at its profile |
| Permissions | `organization.*` | `leadership.*` |
| Cache tag | its own | `leadership` |
| API | `/api/v1/public/committees` | `/api/v1/public/leadership` |
| Site styling | `.leadership-card` (committee / organization pages) | `.leader-*` (a separate block in `globals.css`) |

The separation is checked, not assumed: `LeadershipMigrationTest` asserts that the only table `leadership_profiles`
points to is `users`, and a person who is both a committee member and a key person is two unrelated records. The static
"governance positions" content of the committee page was not touched.

## Data model

`leadership_profiles` (migration `2026_10_15_100000_create_leadership_profiles_tables`):

| Column | Meaning |
|---|---|
| `slug` (unique, ≤ 80) | the public address, `/leadership/{slug}`; lower-case letters, digits and hyphens |
| `name_bn` (required), `name_en` | the person's name in each language |
| `designation_bn` (required), `designation_en` | the role, as the administrator writes it (e.g. "Founder"; the system suggests nothing) |
| `short_bio_bn/en` (≤ 600) | shown on cards and used as the page description in search results |
| `full_bio_bn/en` (≤ 10 000), `quote_bn/en` (≤ 500) | the profile page |
| `expertise_bn/en` (≤ 160), `associated_since_year` (1900 … this year) | optional facts |
| `photo_path`, `photo_sm_path`, `photo_width`, `photo_height` | the two derived JPEGs (see *Photo*) |
| `public_email`, `public_phone`, `facebook_url`, `linkedin_url`, `youtube_url`, `website_url` | **optional, public by definition**: what is entered is shown on the profile page, nothing else is |
| `status` | `active` (public) · `inactive` (default; hidden, editable) · `archived` (hidden, kept) |
| `featured` | shown first on the Leadership page and eligible for the homepage section |
| `display_order` | the one canonical order (lower first), ties broken by `id` |
| `created_by`, `updated_by`, timestamps | audit; **never exposed publicly** |

`leadership_profile_slugs` keeps every address a profile has given up (see *Addresses*).

There is deliberately **no** field for a private phone, a national ID, a home address, a date of birth, a user account
or anything else that is not meant to be public. A profile is not linked to a user account.

## Who can do what

Five permissions in the module `leadership` (route middleware `permission:leadership.*`, and checked again in the
controller wherever a request could carry a field the role must not set). **Only `super_admin` holds them after the
migration**; no other role gains access until someone grants it on **Users & Permissions → Roles**.

| Permission | Allows |
|---|---|
| `leadership.view` | the list, the filters, the preview of a profile |
| `leadership.create` | adding a profile — it is **always saved inactive, not featured, last in the order**, whatever the request says |
| `leadership.update` | editing content, photo and the web address (never status, featured or order) |
| `leadership.publish` | activate / deactivate, feature / unfeature, reorder, and setting those fields while creating or editing |
| `leadership.archive` | archive and restore |

So an editor role (view + create + update) can prepare a profile but cannot make it public; someone with `publish`
decides what the world sees. A form the screen never offered (a crafted `status=active`, `featured=1`,
`display_order=1`) is ignored, not rejected. The sidebar entry appears only with `leadership.view`.

Production never re-runs the seeders, so the five permission rows arrive by the data migration
`2026_10_15_100100_add_leadership_permissions` (idempotent; attaches them to `super_admin` only).

## Publishing rules

* **Public means `status = active`.** An inactive, archived or unknown profile answers 404 on its page and in the API —
  the same answer for all three, so nothing reveals that a hidden profile exists.
* **There is no permanent delete** anywhere in the admin. *Deactivate* hides a profile and keeps editing possible;
  *Archive* hides it, unfeatures it and takes it out of the order (restorable). *Restore* brings it back **inactive**, at
  the end of the order — never straight to public.
* **Order** is `display_order`, then `id`. The arrows in the list move one profile one place; first the whole order is
  renumbered 10, 20, 30 … (so tied or hand-typed numbers cannot make a swap a no-op), then two neighbours trade places, in
  one locked transaction. The arrows exist only on the unfiltered list.
* **Featured never reorders.** Featured profiles are shown first on `/leadership` (a group of their own), each group in
  display order; featuring a profile changes nobody's `display_order`.
* **The homepage** shows at most four profiles that are both active and featured, in display order, with a "View
  Leadership" link. With none, the section is not rendered at all (never an empty box); with one or two the cards are wide
  and show the short biography, with three or four they are portrait cards.
* The list hides archived profiles unless the **Archived** filter is chosen; search covers names, designations and
  addresses.

## Photo

Rides on the project's two-tier upload architecture (`PhotoUploadService`), so it is validated like every other photo:

1. **Checked on a private disk first**: at most 5 MB; extensions `jpg`, `jpeg`, `png`, `webp` only (no SVG, no GIF,
   nothing executable); the real file header is sniffed (the client's name and MIME type are never trusted); the image is
   fully decoded. Over 36 million pixels is refused (it could exhaust memory); a shorter side under 300 px is refused.
2. **Only a derivative becomes public**: the picture is stood upright by its EXIF orientation and re-encoded as two
   **progressive JPEGs** — a page size (longest side ≤ 1200 px) and a card size (≤ 480 px), never enlarged, on a white
   ground (a transparent PNG does not turn black). The re-encode drops every EXIF/GPS/camera block, and a file that only
   claims to be an image cannot be published. The original is deleted.
3. **Stored where deploys cannot touch it**: the `public` disk, i.e. the live vhost docroot's `storage/leadership/<uuid>.jpg`
   and `<uuid>-sm.jpg` (`config/filesystems.php`), which the atomic release switch never renames — the same place every
   other public upload lives. `deploy/qa/leadership-qa.php where` proves a photo's real path and that it is **outside**
   the release tree.
4. **Replacing or removing** a photo deletes the old files, after the new row has committed (a failure never leaves a
   profile without its photo). Every upload gets a fresh random file name, so a page can never show a stale picture.
5. **The CDN remembers the old address.** Photos are served through Cloudflare with `Cache-Control: public,
   max-age=604800`: the address of a photo that was just replaced or removed can keep answering from the edge cache for up to
   a week although its files are gone from the server (seen on production 2026-10-17: the address answered `200`,
   `cf-cache-status: HIT`; the same address with a query string answered `404`, `BYPASS`). Only someone who already holds the
   old address sees it. If an old photo must disappear at once, purge the cache (hPanel → Cache, or Cloudflare).

On the site the `<img>` carries the width and height (no layout shift), a `srcset` (card size, page size for dense
screens), and `alt` = the person's name from the data ("Photo of …" / "…-এর ছবি"). A profile without a photo shows its
initials in the same frame, never a broken image or a stock silhouette.

## Bangla and English

* **Bangla is the base language**: `name_bn` and `designation_bn` are required, everything else is optional. English is
  entered separately in the second tab of every field (a ⚠ on the tab says it is empty); nothing is translated or
  guessed.
* The site follows the project's one fallback rule (`lib/i18n/pick.ts`): on `/en/…` a field with English text shows it; a
  field without shows the Bangla text and the page carries the standard note *"The English translation for this section is
  not yet available."* A field with no text in either language is simply not shown.
* An **English page exists** for a profile when both `name_en` and `designation_en` were entered. Otherwise `/en/leadership/{slug}`
  is a fallback render: its `canonical` points at the Bangla page, it is **not** advertised in `hreflang`, and the
  sitemap omits it.
* Every admin screen is available in both admin languages; the preview shows the Bangla and the English page as a visitor
  will see them, with the same fallback rule.

## Pages and search metadata

| URL | Content |
|---|---|
| `/leadership`, `/en/leadership` | the featured group ("Executive leadership"), then the others ("Key persons"); empty state and `noindex` when there is nothing to show |
| `/leadership/{slug}`, `/en/leadership/{slug}` | photo, name, designation, breadcrumb, expertise and year, quote, full biography (paragraphs, `•` bullets, plain http(s) links), the contact card |
| a retired address | answered with a **permanent redirect** to the current one (Bangla and English) |
| `/sitemap.xml` | the index and every active profile; an English alternate only where an English page exists |

Per profile page: `<title>` = *name — designation*; description = the short biography cut at a word boundary (or, with no
biography, the designation and the organisation — only stored facts); canonical and `hreflang` (`bn`, `en` where it
exists, `x-default` = Bangla); Open Graph title, description, URL, image (the photo, at its real size, with alt text),
`og:locale`, and `og:alternateLocale` only when the other language page exists. Nothing is claimed that the profile does
not say.

The **contact card** shows only what was entered: e-mail (`mailto:`), phone (`tel:`), website and the three social links.
Social and website links are `https://` only, open in a new tab with `rel="noopener noreferrer"`, use the platform's own
SVG icon (no emoji), and have an accessible name.

## Public API

Read-only, no authentication, JSON. `GET /api/v1/public/leadership[?featured=1][&limit=N]` (limit capped at 50) and
`GET /api/v1/public/leadership/{slug}` (the slug may be a retired one: the response carries the **current** slug, so the
site can redirect).

* List item — exactly: `slug, name_bn, name_en, designation_bn, designation_en, short_bio_bn, short_bio_en, expertise_bn,
  expertise_en, featured, photo {url, sm_url, width, height} | null, social [{platform, url}], website_url`.
* Detail — the same plus: `full_bio_bn, full_bio_en, quote_bn, quote_en, associated_since_year, public_email, public_phone`.
* Built by `PublicLeadershipPayload`, an explicit allow-list (a new column is **not** public until someone adds it there).
  It re-validates every link, e-mail and phone it emits, so a bad row written any other way (a migration, a direct write)
  is returned as `null` rather than published.
* Never present: `id`, `status`, `display_order`, `created_by`, `updated_by`, timestamps, photo paths on disk, any user
  data, any secret. The public e-mail can never be the internal (`admin@`), automated (`no-reply@`) or security mailbox.

## Cache and revalidation

1. Every site fetch of this API carries the Next cache tag **`leadership`** (plus a 300 s safety-net time-to-live).
2. Creating, updating (including the photo), activating, deactivating, featuring, reordering, archiving or restoring a
   profile queues that tag: `App\Observers\LeadershipProfileObserver` on `saved` and `deleted`.
3. The mutating routes carry `revalidate.public`, so the signed Laravel → Next call (`PublicSiteRevalidator`, HMAC v2
   with nonce and replay guard — `PUBLIC_SITE_CACHE.md`) goes out **before** the admin's browser is told "saved"; the site
   expires the tag with `revalidateTag(tag, { expire: 0 })`. `leadership` is in the site's tag allow-list
   (`lib/revalidate-request.ts`); a tag outside the list is refused.
4. The HTML is not cached by the CDN. Caching is not disabled anywhere.

Measured on **production**, real Chrome, 2026-10-17 (`scripts/leadership-qa.mjs`, 35 measurements): the admin's save
response takes 1.0–3.2 s (3.2 s for a 4.7 MB photograph); the changed page is then visible to a plain GET **0.15–1.65 s
after the response** — activate 0.2–0.6 s, edit Bangla or English 0.2–0.5 s, photo 0.5–1.3 s, feature 0.5–0.8 s, deactivate
0.3–0.9 s, address change 0.2 s, archive 0.3–0.5 s, reorder ≤ 1.7 s, sitemap 0.6 s. On a local production build the same
changes are visible within 20–150 ms (reorder < 800 ms).

## Addresses (slugs)

* The slug is made from the English name (else the Bangla name), or typed by the administrator; it must match
  `^[a-z0-9]+(-[a-z0-9]+)*$` and is made unique with a number (`name`, `name-2`, `name-3` …). If a *typed* address had to
  be changed that way, a warning says which address was used instead.
* **It changes only when an administrator types a different one — never because the name changed**, so an address that has
  been shared does not move.
* Changing it keeps the old one in `leadership_profile_slugs`: the old address redirects permanently, nobody else can take
  it, and the profile can take its own earlier address back. Archiving keeps the slug too.

## Admin screens

List (photo, name, designation, address, status, featured, order arrows, last updated by whom; search, status and
featured filters), create / edit (sections **Identity**, **Profile**, **Public contact and links**, **Photo**,
**Publishing**), preview, and the row menu (preview, view on the site, edit, activate / deactivate, feature / unfeature,
archive / restore). Inline validation under each field, one success message, a warning when an address was adjusted, no
double submission, an unsaved-changes warning (`public/js/provatferi-form-guard.js`), every control labelled, light and
dark themes, a phone layout. The page head no longer overflows on a phone when its action button wraps
(`.page-title-head.pf-has-actions`).

Field names are underscore names (`name_bn`, not `name.bn`): PHP rewrites a dot in a form-field name, which once made the
Settings form unable to save (`PUBLIC_CONTACT_SETTINGS.md`); `AdminFormFieldNamesTest` scans these views too. Text given
to an `<x-admin.*>` tag is **bound** (`:label="__('…')"`), never interpolated.

## Security notes

| Concern | What holds |
|---|---|
| Access control / IDOR | route middleware per permission; the models are bound by id but every action needs a permission, and there is no per-user object (a profile is not "owned"); a no-access role gets 403 on every Leadership URL and write |
| Unauthorized publication | create-without-publish is saved inactive/unfeatured; status, featured and order are read only for `publish` holders; forged fields are ignored |
| Hidden / inactive exposure | one `publiclyVisible()` scope for every public read; 404 identical to "unknown"; the API payload is an allow-list |
| Stored XSS / unsafe rich text | **there is no rich text**: text is plain, markup (`<` + letter) is refused with a message, control and bidi-override characters are removed, output is escaped by React; the site's `TextBlocks` only makes paragraphs, `•` bullets and plain http(s) links |
| Malicious URLs | `PublicLinks`: https only, the platform's own domain, no user-info, no markup; websites also refuse IP addresses and internal host names; the site validates them again (`lib/public-links.ts`) |
| Mail-header injection | the public e-mail refuses `%`, whitespace, `,`, `;`, quotes and angle brackets (rules, API and site) |
| Upload attacks | see *Photo*: allow-list, header sniffing, full decode, derivative only, no original kept, size and pixel limits |
| Mass assignment | explicit `$fillable`, validated input only (`LeadershipProfileInput::normalize`) |
| CSRF | the admin routes are in the `web` group (token required) |
| Slug manipulation | regex, uniqueness (current and retired addresses), history redirect |

## Creating a profile (the procedure)

1. **Admin → Institution → Leadership / Key Persons → New profile.**
2. **Identity**: the name and the designation in Bangla (required) and, when it is true and you have it, in English. Write
   only what is true — the system fills in nothing. Leave the web address empty to have it made from the name.
3. **Profile**: short biography (cards and search results), full biography, an optional quote, expertise, the year the
   person became part of the institution. Blank line = new paragraph; lines starting with `•` or `-` become a list.
4. **Public contact and links**: optional; whatever you enter is **public**.
5. **Photo**: JPG, PNG or WEBP, up to 5 MB, shorter side at least 300 px.
6. Save. The profile is **inactive**: the website has not heard of it. Use **Preview** to see both languages.
7. Someone with the *publish* permission activates it (row menu → Activate), optionally features it, and moves it into
   place with the arrows.

## Deployment and verification

* Two migrations, both additive (create two tables; insert five permission rows). Review `migrate-check`'s `--pretend`
  output before `migrate-apply`, as for every migration. Deploy the **ERP first**, then the site: the site treats a missing
  API as "no leadership yet" (empty page, `noindex`), never an error.
* **Production acceptance**: `institutional/scripts/leadership-qa.mjs` (real Chrome: the admin screens, the public site,
  desktop and phone, light and dark, both admin languages, timings; a limited role and two throw-away users prove
  per-permission access) and the server-side kit `deploy/qa/leadership-qa.php` (`snapshot` → run the browser kit →
  `purge … go` → `diff` → `forget`; `where` proves where photos live). Fixtures (synthetic, no real person):
  `deploy/qa/leadership-fixtures.php`.
* **QA data is recognisable and removable**: every disposable profile's name starts with `QA-LEAD-<tag>`; the kit deletes
  only rows, users (`qa-lead-*@example.test`), roles and photo files that carry the marker, and the real profiles are never
  matched. The admin screens cannot delete (by design); the kit does, and tells the public site to refresh.
* Tests: `LeadershipProfileTest`, `LeadershipPhotoTest`, `PublicLeadershipApiTest`, `LeadershipMigrationTest`,
  `LeadershipProfileInputTest`, `PublicLinksTest` (ERP); `leadership`, `public-links`, `revalidate-v2` (site).

## Known limits

* Plain text only: no bold, no links with their own text, no images inside a biography. Intentional.
* One photo per person; no gallery.
* A profile has one designation per language; two roles are written in one line.
* The homepage section is fixed at four profiles; the Leadership page has no pagination (it is not meant for hundreds).
* Only `https://` links; a profile on a site without HTTPS cannot be linked.
* Users cannot be deleted from the admin screens (existing behaviour); the QA kit removes its own.
* A replaced or removed photo's old address can still answer from the CDN for up to a week (see *Photo*, item 5).
