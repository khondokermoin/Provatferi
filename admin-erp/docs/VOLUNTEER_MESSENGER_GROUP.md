# Volunteer Messenger group (shown only after a verified application)

The one reference for how a volunteer applicant is invited to the **central Messenger group**. The rule is a single
sentence: **the invite is visible only on the confirmation page, to a browser that has just submitted an application the
system accepted, and nowhere else** — not on the notice, not on the opportunity page, not in the API, the page source, the
JavaScript, the sitemap, a cached copy or a confirmation e-mail.

| Piece | Where |
|---|---|
| Settings, canonicalisation, "is this text carrying the invite" | `app/Support/Recruitment/CentralMessengerGroup.php` |
| The proof (issue / verify) | `app/Support/Recruitment/ApplicationSuccessProof.php` |
| Issued when an application is accepted | `app/Http/Controllers/Api/V1/Public/VolunteerApplicationController.php` (`accepted()`) |
| Verified on every load of the confirmation page | `app/Http/Controllers/Api/V1/Public/VolunteerApplicationSuccessController.php` → `POST /api/v1/public/recruitment/{slug}/application-success` |
| Admin screen | Admin → Recruitment → **Volunteer Messenger group** (`app/Http/Controllers/Admin/RecruitmentMessengerController.php`, `resources/views/admin/recruitment/messenger.blade.php`, permission `settings.update`) |
| Keeps the invite out of public text an admin writes | `app/Rules/NoCentralMessengerInvite.php` (notices, postings) |
| Site: carries the proof, calls the verification, renders the card | `institutional/lib/volunteer-success.ts`, `lib/volunteer-application*.ts`, `app/[locale]/(site)/recruitment/[slug]/apply/success/page.tsx`, `components/MessengerGroupCard.tsx` |
| Build-time leak check | `institutional/scripts/verify-client-bundle.mjs` (runs after every `next build`) |
| Tests | `tests/Feature/Recruitment/*`, `tests/Feature/Admin/RecruitmentMessengerSettingsTest.php`; `institutional/lib/api/__tests__/volunteer-success.test.mts`, `institutional/__tests__/volunteer-messenger-gating.test.mts` |
| Production acceptance kit | `deploy/qa/volunteer-messenger-qa.php`, `institutional/scripts/volunteer-messenger-qa.mjs` |

## The flow

```
Volunteer notice ─► Recruitment details ─► Apply (form) ─► Submit
                                                              │ accepted by Laravel (201)
                                                              ▼
                     Laravel issues a PROOF ─► the site's server puts it in an HttpOnly cookie
                                                              │ redirect / client navigation (a GET)
                                                              ▼
                     /recruitment/{slug}/apply/success  ── the site's server asks Laravel "is this proof good for this posting?"
                                                              │ yes                          │ no / none / expired / not reachable
                                                              ▼                              ▼
                     "Application received" + Messenger card          neutral page: no link, no button, no hint of one
```

Nothing before the last step knows the invite. A typed address, a bookmarked address, a search result, a shared link, or
a previous applicant's cached copy lands on the neutral page.

## The setting

| Admin field | Key (private `settings` rows, group `recruitment`, `is_public = false`) |
|---|---|
| Offer the group after a successful application | `recruitment.central_messenger_enabled` (`central_messenger_enabled` on the form) |
| Messenger group invite link | `recruitment.central_messenger_invite_url` (`central_messenger_invite_url` on the form) |

* **Not hardcoded anywhere.** The invite is data an authorised admin enters; no template, posting, notice, application
  row, test or script holds it (tests use made-up tokens; the repository is public).
* **Private rows**: `is_public = false`, so the public settings API — which reads public rows only — can never return them.
* **Who may change it**: `settings.update` (the page and the save). The link is shown only to someone who may change it.
* **Stored in one canonical form**, `https://m.me/j/<token>/`, whichever form was pasted. These all become the same value:

  ```
  https://m.me/j/<token>/
  https://www.messenger.com/j/<token>/?send_source=gc%3Acopy_invite_link_c
  https://m.me/j/<token>/?send_source=gc:copy_invite_link_c
  ```
* **Only a Messenger group invite is accepted**: `https`, host `m.me` / `messenger.com` / `www.messenger.com`, path exactly
  `/j/<token>` (token `[A-Za-z0-9_-]{6,100}`). A pasted query/fragment is allowed and dropped. Refused: `http`,
  `javascript:`, `data:`, any other host (including look-alikes such as `m.me.evil.example`, `m.me@evil.example`,
  `evil.example/m.me/j/…`), a port, user-info, a backslash, whitespace or control characters (a NUL included), markup,
  a second path segment, a raw HTML or embed snippet, anything over 500 characters. It is one strict pattern over the
  whole string, not a URL parser (a parser and a browser can disagree about odd addresses; a pattern that names every
  character it accepts cannot).
* **Turning it on needs a valid link.** Turning it off hides the group from every confirmation page at once and keeps the
  saved link.
* **Effect**: the invite is read from the settings when the confirmation page is verified, so a change (new link, off)
  applies to the **next load of any confirmation page**, including one that is already open and reloaded.
* **Not stored per application.** The application row never holds the invite; changing the link does not need a data
  migration, and an old application can never point at a retired link.
* **The log records who changed what, never the value** (`user_id`, `enabled`, `invite_changed`).

## Success-page gating (server-verifiable)

The site does not trust a flag, a query string or a client-side boolean. "This browser just submitted an application" is a
**proof** that Laravel issues and Laravel verifies.

**Issued** when an application is accepted (HTTP 201) — and only then. A validation failure, a second application by the
same person for the same posting (refused as "already applied"), an error or a timeout issues nothing. The one repeat that
*does* get a proof is the form's own retry of the **same attempt** (its one-time submission token: a double click, a response
that never arrived): that visitor did submit successfully and receives the original application's result. The proof is
returned to the site's **server**; the site never sends it to the browser in a body, a field, a URL or the page.

**What it is**: an authenticated-encrypted blob (AES-256-GCM) under a key derived from `APP_KEY` for this purpose alone
(a blob made for a session or an MFA secret cannot be passed off as one). Inside: version, purpose, the application id,
the posting id, an expiry and a random nonce. Outside: nothing readable — **no name, phone, e-mail, application number or
id** appears in the cookie, a URL or the page. A single flipped character fails the authentication tag.

**Carried** in the cookie `pf_applied`: `HttpOnly; Secure; SameSite=Lax`, host-only (no `Domain`), `Max-Age` 1800 s, and
`Path` limited to the confirmation page of that posting in each language (`/recruitment/{slug}/apply/success` and
`/en/recruitment/{slug}/apply/success`) — the browser sends it to nothing else on the site. JavaScript cannot read it.
Both submission paths set it: the form's JavaScript post (`/api/recruitment/{slug}/apply`, a route handler, one `Set-Cookie`
line per language path) and the no-JavaScript path (the Server Action, before its redirect). The Server Action sets the
cookie for the one address it redirects to — the unprefixed (Bangla) confirmation, as it always did — because Next's cookie
store keeps one cookie per **name**: a second `set` of `pf_applied` with the English path would replace the first (found in a
real browser run: the confirmation showed the neutral page). So a no-JavaScript applicant always lands on the Bangla
confirmation, as before this change.

**Verified** on every load of the confirmation page. The page is a Server Component rendered per request
(`force-dynamic`): it reads the cookie and POSTs `{ "proof": … }` — in the **body**, never the URL — to
`/api/v1/public/recruitment/{slug}/application-success`. Laravel answers `{"data":{"verified":bool,"messenger":{"url"}|null}}`
after checking, in this order:

1. the proof decrypts and authenticates (not forged, not altered), is the right version and purpose;
2. it has not expired (30 minutes);
3. it was issued for **this** posting (a proof for posting A shows nothing on posting B's page; a renamed posting's old
   slug resolves to the same posting);
4. the application still exists and is still live — an admin who marks it `not_selected`, `withdrawn` or `archived`
   inside the window revokes it.

The answer is always `200`, never says **why** a proof was refused (it is no oracle), carries nothing about the
application, and is `Cache-Control: no-store, private`. The site renders the link only if the answer is verified **and**
the address matches the canonical pattern a second time.

**Expiry and replay** — a deliberate policy, not an accident: the proof is valid for **30 minutes** and may be **replayed
within that window** (a refresh, the back button, a reopened tab, a trip to the Messenger app and back all work). After
30 minutes, or in another browser or a private window, or after clearing cookies, the page shows the neutral message.
It is a short-lived credential in an HttpOnly, path-scoped cookie — not a bearer link and not a URL.

**What the page shows**

| Visitor | Page |
|---|---|
| Just submitted, group on, valid invite | "Application received" + the Messenger card (Join button, Copy button) |
| Just submitted, group off or no valid invite | "Application received", no card |
| Typed the address / no cookie / forged / tampered / expired / another posting's / application revoked | Neutral page: "this confirmation is not available", with a way back; no link, no button, no copy control |
| Laravel could not be asked | Neutral page with "try again" — it never guesses, never shows a link |

The page takes no `searchParams`, id or token. Its metadata is the same for everyone and is `noindex, nofollow`; it is not
in the sitemap. Next sends `Cache-Control: private, no-cache, no-store, max-age=0, must-revalidate`, so the invite is in
the response of a verified visitor and in no shared or CDN copy.

## The confirmation page

* **Verified**: a status badge (text; the tick is a glyph, no emoji), a heading, a short lead, and the **Messenger card**:
  title, one sentence of explanation, a **Join** link (opens in a new tab, `rel="noopener noreferrer"`, an inline SVG
  icon) and a **Copy link** button. Bengali and English are complete dictionaries (`lib/i18n`), switched by the page's
  locale; light and dark themes use the site's tokens.
* **Copy**: the asynchronous Clipboard API where allowed; otherwise a selected off-screen field and `execCommand`;
  otherwise the address appears in a read-only field, already selected, so a long press or Ctrl+C finishes it. The result
  ("Link copied" / "Couldn't copy — select it below") is announced in one polite live region and nothing reloads. The
  button exists only once the page has hydrated (a button that cannot work is not shown); the Join link works without
  JavaScript.
* **No identifying data** is shown — no name, phone, e-mail or application number.

## Where the invite used to be (audit of 2026-10-18, before this change)

Captured from production before any change (`provatferi-messenger-gating-2026-10-18/before/`).

| Surface | Before | Now |
|---|---|---|
| `GET /api/v1/job-postings[/{slug}]` | `notice_action.url` = the **WhatsApp** group invite | key kept for older site builds, always `null` |
| `GET /api/v1/public/notices[/{slug}]` | `action.url` = the WhatsApp invite | `null` for a recruitment-linked notice (its route is the website form), and for any button that carries the central token |
| Recruitment details page (HTML + RSC payload) | "join the community group" button + URL | no group control |
| Confirmation page | the WhatsApp button, reachable by typing the address | gated as above |
| Notice page | button **and** a "View or copy the link" box with the raw URL | no group control, no raw URL |
| Application receipt e-mail | a "join the group" line with the URL | no group link |
| Settings API / sitemap / client bundle | not present | not present, and now **guarded** (see below) |

The central **Messenger** invite had never been public (it was not yet configured anywhere).

## Messenger "Require approval to join" must stay ON

The website can gate **who sees** the link; it cannot stop an applicant who has it from forwarding it. The second wall is
Messenger itself:

* In the group's settings keep **Require approval to join** switched **on**. Anyone who obtains the link can then only
  *request* to join.
* The group admins approve **only people who have a volunteer application** — compare the requester's name with the
  applications in Admin → Recruitment → Applications (name, phone, e-mail), approve matches, decline the rest.
* If the link ever spreads, reset it (below) and decline the strays. The members already approved stay in the group.

## Operations

| Task | How |
|---|---|
| Set or change the link | Messenger: open the group → **Invite** → **Copy link**. Admin → Recruitment → **Volunteer Messenger group** → paste → tick *Offer the group…* → **Save**. Any of the usual pasted forms is saved as `https://m.me/j/<token>/`. |
| Check it | The screen shows the current state and the last change (who and when) and an **Open the saved link** link (new tab). |
| Switch off | Untick *Offer the group…* → Save. Every confirmation page stops showing it on its next load; the link stays saved. |
| Reset a leaked or abused link | In Messenger: group settings → **Invite link** → **Reset link** (the old one stops working). Paste the new link in the admin screen and Save. No deploy and no data change is needed. |
| Keep the link out of public text | The notice and posting forms refuse text that contains the saved invite's token (`NoCentralMessengerInvite`). |
| Applicants from before this release | They have no proof, so the page does not offer them the group; send them the link yourself if you want them in. Their old receipt e-mails carried the WhatsApp link, not this one. |

## Security notes

| Concern | How it is handled |
|---|---|
| Forged or altered proof | AES-256-GCM authentication; any change fails verification. Tested with flipped characters, truncation, another key, another purpose. |
| Replay | Allowed on purpose for 30 minutes (refresh/back/reopen); refused after, in another browser, after clearing cookies. |
| Expiry | Server-side, checked on every load; the cookie's own `Max-Age` is only a convenience. |
| IDOR / wrong posting | The page takes no id or token; the ids are inside the encrypted proof and must match the posting in the address and a live application of it. |
| Shared rate-limit bucket | Laravel's numeric `throttle:N,M` is keyed by domain + IP, **not by route**, and every visitor reaches Laravel through the public site's one server address, so every throttled route shares one counter with the application form's 5 a minute. The verification endpoint therefore has a bucket of its own (`throttle:600,1,application-success:`); on the shared one, a few confirmation refreshes turned the next applicant away with a 429. Pinned by `test_confirmation_page_loads_never_use_up_the_application_forms_allowance`. |
| CSRF | Nothing state-changing is exposed by the verification (it only reads). The upload route refuses cross-site and non-multipart posts (`lib/upload-route.ts`); the Server Action keeps Next's origin check; the admin form carries the web group's CSRF token. |
| Open redirect | No redirect takes an address from a request; the confirmation address is built from the route's own slug. |
| XSS | The invite is rendered as an `href` only after the strict pattern is applied twice (Laravel, then the site); text comes from dictionaries; no `dangerouslySetInnerHTML`. |
| Malicious URL pasted by an admin | Refused by the pattern (see "The setting"). |
| Cache / CDN | Page: `private, no-cache, no-store`; verification API: `no-store, private`; the JSON route answers `no-store`. Production QA checks `cf-cache-status` is not `HIT`. |
| Indexing | `noindex, nofollow`, not in the sitemap, same title for everyone. |
| Bundle / source leakage | `verify-client-bundle.mjs` fails the **build** if `.next/static` contains a group invite address or the proof's names (`pf_applied`, `success_proof`); a source test fails if any invite address is written into the site's files; a test pins the card to one importer. |
| Logs | Only the path of a failed API call is logged (never the body/proof); the setting log carries no value. |
| Privacy | No phone, e-mail, name, application number or id in the cookie, a URL, the page or an analytics call (there is none). |

## Deployment notes

* **ERP first, then the site.** The ERP release is safe in front of the old site build: the old build ignores the new
  response keys and finds `notice_action` present and `null`, so it shows no group button; the old gate required the key,
  which is why it is kept. The site release then adds the proof handling and the card.
* A site deployed *before* the ERP would find no proof in Laravel's answer and show the neutral page to real applicants —
  hence the order.
* No migration: the two settings are rows created by the admin screen's first save.

## Known limits

* A person who has the cookie can open the page for 30 minutes in that browser; a person who has the **link** (copied
  from the page, or forwarded by an applicant) can request to join — that is what Messenger's approval is for.
* A browser with cookies disabled never receives the proof and sees the neutral page after applying.
* The application form still accepts 5 submissions a minute for the whole site (the pre-existing limit, shared by every visitor);
  the acceptance kit keeps to 3 a minute so that it never turns a real applicant away.
* The page cannot know that an applicant actually joined; approval in Messenger is manual.

## Production acceptance (2026-10-18)

Release `f922fde9b645-2026-10-1809242` (ERP first, then the site build). Real Chrome against provatferi.org and
admin.provatferi.org with `institutional/scripts/volunteer-messenger-qa.mjs`:

* **Main run, 79/79** (desktop and phone, light and dark, Bangla and English). With the group configured and ON — first a
  made-up link, then the owner's real one — the notice, opportunity and apply pages (HTML and data payload), the public APIs
  and the 11 script/style files the pages load carry no invite. A refused application and a refused duplicate stay on the form
  with no proof cookie; a valid one lands on the confirmation with Join and Copy; Join and Copy give exactly the canonical
  link (clipboard read back; the manual-copy fallback checked); refresh, a second tab and the other language work inside the
  window; a fresh browser and every tampered, truncated, extended or invented proof show the neutral page; switching the group
  off hides the card and a changed link shows on the next load; fifteen hostile links are refused with nothing stored, a link
  pasted in Messenger's own long form is saved canonical, and a save without the CSRF token is refused (419).
* **Expiry, 5/5**: run 31.5 minutes after the proof was issued, with the application row still present, so it is the expiry that
  is tested and not a revocation: the neutral page, no invite in the source, and the verification API answers
  `verified:false, messenger:null`.
* Headers: the confirmation page is `private, no-cache, no-store`, the verification API `no-store, private` with its own
  `x-ratelimit-limit: 600`, `cf-cache-status: DYNAMIC` on both; the page is `noindex, nofollow` and not in the sitemap.
* The owner's real invite, pasted in the `www.messenger.com … ?send_source=…` form, was saved through the real admin screen and
  checked by digest only (the first 8 hex characters of the sha256 of its token). It is left saved and switched ON.
* Server side (`deploy/qa/volunteer-messenger-qa.php`): snapshot before the run; after it, **no real application, posting, notice,
  setting or file had changed** (only the three QA applications were left, then purged); production writes no Laravel log file, so
  there was nothing to scan.
* Regression: `nav-qa.mjs` 13/13 and `request-storm-qa.mjs` settled on the home page, the opportunity page, the neutral
  confirmation and the notice.

Three things only a real browser run found, each fixed and pinned by a test: the verification route first shared the application
form's 5-a-minute rate-limit bucket (the numeric `throttle` is keyed by domain + IP, not by route); Next's cookie store keeps one
cookie per name, so the no-JavaScript Server Action could not set both language paths; and `.button span` (the 19px arrow glyph)
made the Copy label larger than the Join label.

Cleanup after the run: the kit's purge removed exactly the three QA applications and their three photos (the next real
application number is `VOL-2026-0082`, as before the run); an independent diff against the pre-run snapshot was identical
apart from the intended difference (the Messenger settings now hold the owner's link, switched on); the kit file, the
snapshot and every session cron were removed, and the only cron left is the permanent dues job.
