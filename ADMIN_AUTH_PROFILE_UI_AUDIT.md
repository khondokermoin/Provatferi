# Admin Auth / Account UI-UX, Brand & Design-System Audit

**Audit only. No CSS, Blade, component, asset, colour or deployment change was made.**

| | |
|---|---|
| Audited | `admin.provatferi.org` — `/login`, `/forgot-password`, `/reset-password/*`, `/profile`, `/register`, `/verify-email`, `/confirm-password` |
| Brand reference | `https://provatferi.org/` (live, measured) |
| Deployed commit under audit | `016165a17482e6163eac138c1915d850ed7bbb3f` (release `r8`, switched 2026-09-11T01:45:03Z) |
| Audit date | 2026-09-12 |
| Method | Live headless Chrome measurement of computed styles at 9 viewports × 2 themes, WCAG contrast computation from measured RGB, vendor-CSS token tracing, codebase residue scan |
| Screenshots | `design-audit/admin-auth-audit/` |
| Raw measurement data | `design-audit/admin-auth-audit/_raw-login-detail.json`, `_raw-profile.json` |

---

## 1. Executive assessment

The admin auth surfaces are **structurally finished but tonally un-branded**. Layout, markup, language, dark-mode wiring, focus management and responsive behaviour are all in good shape — there is no horizontal overflow at any of the nine viewports tested, no console errors, no failed asset requests, keyboard focus is visible and correctly ordered, and the logo is now sized to exactly the same 190px the public site's header uses.

What is wrong is one specific, tightly-scoped thing with wide blast radius: **the brand layer overrode the accent tokens and left every neutral, semantic and typographic token at its Zircos default.** `provatferi-admin.css` overrides 41 `--ct-*` custom properties — every one of them a *colour-accent*, *button*, *menu* or *topbar* variable. It never overrides `--ct-body-color`, `--ct-heading-color`, `--ct-secondary-color`, `--ct-border-color`, `--ct-danger`, `--ct-light`, or `--ct-font-family-secondary`. The consequence is that the orange is Provatferi's and everything *around* the orange is still a Bootstrap admin template: cool blue-grey text where the brand specifies warm neutrals, cool grey borders where the brand specifies warm parchment tones, a soft pink-red where the brand specifies a deep institutional red, and **Montserrat as the heading typeface on every single heading in the panel** — a font with no Bengali coverage, so every Bengali heading falls back to an unspecified system font.

That token gap is also why the pages read as *washed out* rather than *calm*. Measured: the input border is **1.22:1** against the card it sits on, and the card border is the same value against the page. Form fields are effectively borderless until focused. Simultaneously the muted text (subheading, helper text, the "পাসওয়ার্ড ভুলে গেছেন?" link, the footer) sits at **2.34–2.46:1**, and every error/required indicator at **2.81:1**. Twelve of twenty measured colour pairs fail their WCAG minimum. The impression of "unfinished" is not vague — it is a measurable absence of contrast in exactly the elements that give a form its structure.

One finding explains the specific defects reported in the brief that measurement could *not* reproduce. `provatferi-admin.css` is served with `Cache-Control: public, max-age=604800` (7 days) and is referenced **without any fingerprint or version query** (`/zircos/css/provatferi-admin.css`). The file on the server is correct and current (14,023 bytes, contains the warm-cream `.auth-bg`, the 190px auth logo rule, the brand checkbox fill and the autofill override). Any browser that loaded the panel before 2026-09-11 01:55 UTC still holds the previous 7,819-byte copy and will keep rendering **the sky-blue Zircos gradient, the ~83px logo and the blue checkbox for up to seven days** — precisely the three symptoms described. A CDN purge was performed; it cannot reach browser caches. Until that asset is fingerprinted, every future design change will silently fail for returning administrators.

`/profile` is no longer Laravel Breeze. Measurement confirms zero Breeze views or Tailwind components render anywhere in the reachable app; the page now runs inside the Zircos admin shell with Bootstrap controls, 664 Bengali characters of chrome and a dark mode that tracks `data-bs-theme` with the rest of the panel. The English strings quoted in the brief (`Profile Information`, `SAVE`, `Update Password`…) are not present in the deployed build. What *is* wrong on `/profile` is proportion: the three password fields render **713px wide while the name and e-mail fields directly above them render 345px** — the same control type at twice the width on one page — and the destructive account-deletion section is styled identically to the benign ones.

The Breeze *toolchain*, however, is still installed and still deployed: Tailwind (54 KB CSS) and Alpine.js (107 KB JS) are built into `public/build` and shipped to production, referenced by nothing reachable except an orphaned stock `welcome.blade.php`. And `GET /register` returns **HTTP 200 on production** for an internal ERP whose only intended account is a single seeded Super Admin.

**Bottom line:** these pages do not fail because they were designed badly. They fail because roughly seven CSS custom properties and one asset-versioning rule were never brought under the design system, and that gap is doing the visual damage across every surface at once.

---

## 2. Auth professional-quality score: **6 / 10**

Correct structure, correct language, correct logo scale, correct dark background, visible brand focus rings, clean responsive behaviour — but the neutral/semantic/typographic token layer is entirely Zircos, four separate AA contrast failures are present, the primary CTA is a different button system from the public site's, the checkbox control is below the WCAG 2.2 target-size minimum, and framework error messages surface in English.

## 3. Profile professional-quality score: **5 / 10**

A genuine improvement — it is now part of the product rather than bolted on — held back by a 2× form-measure inconsistency inside a single page, an undifferentiated danger zone, a page-title/section-title hierarchy that is almost flat (17px vs 15px), a layout that is *widest at the narrowest desktop* (976px at 1024px viewport), and every inherited SYSTEM-level token defect.

---

## 4. Login findings

### 4.1 Background (brief §2)

| Question | Measured answer |
|---|---|
| Current computed background | `#fbf9f4` + `radial-gradient(1200px 600px at 85% -10%, rgba(255,95,31,0.06), transparent 60%)`, with `border-top: 4px solid #ff5f1f` |
| Belongs to approved palette? | **Yes.** `#fbf9f4` is character-for-character the public site's `--bg` token (verified live on `provatferi.org`). The 4px orange top rule is the same idiom as the public header's `.brand-topline`. |
| Is a sky-blue background in use? | **Not in the deployed CSS.** Zircos ships `.auth-bg{background:linear-gradient(90deg,#eaf3f4 0,#b3c5df 100%)}` in `app.min.css`; `provatferi-admin.css` overrides it and loads last. |
| Then why is sky-blue visible? | **Stale browser cache — see SYSTEM-006.** The stylesheet is served `max-age=604800` with no fingerprint. Pre-2026-09-11 visitors hold the older 7,819-byte file, which has no `.auth-bg` rule at all, so Zircos's pastel teal→blue gradient wins by default. |
| Is an intended brand background asset unused? | **Yes, partially.** `COLOR_AND_LOGO_GUIDELINES.md` §3a defines an official ivory chrysanthemum texture system (AVIF/WebP + `--texture-wash` overlay). It is used on the public site and referenced nowhere in the admin CSS. The admin auth background is therefore an approved *colour* but not the approved *treatment*. |
| Too much empty area? | See AUTH-001 — the issue is the card's unconstrained width, not the background. |

**Root cause: the background is correct in source and correct on the server. The defect is asset cache-busting (SYSTEM-006), plus an unused approved texture treatment (AUTH-009).**

### 4.2 Logo scale (brief §3)

| Metric | Measured |
|---|---|
| Rendered size | **190.0 × 87.0 px** (desktop ≥576px); **160px** wide below 576px |
| Source asset | `provatferi-logo-light.png`, 1876 × 859 px, ~72 KB |
| Aspect ratio | 2.184 — preserved exactly (190/87 = 2.184). No distortion. |
| Rendered at | 10.1% of natural width — downscaled, so sharp, not blurry |
| Logo : card width | **40.9%** (190px in a 464px card) |
| Clear space below logo | 46.5px to the `<h1>` |
| Public-site header logo | **190px** — identical |

**Verdict: the logo currently functions as a real identity anchor, not a decorative mark.** At 190px it is the same width as the wordmark in the public site's own header and occupies ~41% of the card. I am not reporting a size defect, because the measurement does not support one. **Recommended target range (already met): 180–210px desktop, 150–170px below 576px** — matching `COLOR_AND_LOGO_GUIDELINES.md` §4's "~210px desktop header / ~157–180px mobile".

The "very small" appearance is the stale-cache symptom: the previous CSS gave no auth-specific rule, so the logo fell back to an inline `height:38px` ≈ **83px wide** — under half its current size. Ancillary defects that *do* remain are logged as AUTH-005 (fragile base-rule cascade, missing `width`/`height` attributes, oversized payload).

### 4.3 Auth card proportions (brief §4)

| Viewport | Card W | % viewport | Card H | Top gap | Bottom gap | Logo W | Overflow |
|---|---|---|---|---|---|---|---|
| 320 | 272.0 | 85.0% | 562.6 | 121.7 | 159.7 | 160 | 0 |
| 360 | 312.0 | 86.7% | 562.6 | 121.7 | 159.7 | 160 | 0 |
| 390 | 342.0 | 87.7% | 562.6 | 121.7 | 159.7 | 160 | 0 |
| 430 | 382.0 | 88.8% | 562.6 | 121.7 | 159.7 | 160 | 0 |
| 768 | 420.0 | 54.7% | 576.3 | 142.8 | 180.8 | 190 | 0 |
| 1024 | 406.7 | 39.7% | 576.3 | 142.8 | 180.8 | 190 | 0 |
| 1366 | 439.3 | 32.2% | 576.3 | 142.8 | 180.8 | 190 | 0 |
| 1440 | 464.0 | 32.2% | 555.3 | 153.3 | 191.3 | 190 | 0 |
| 1920 | 624.0 | 32.5% | 555.3 | 153.3 | 191.3 | 190 | 0 |

Card padding is a constant 36px at every viewport, including 320px (leaving 200px of content width — tight but functional).

Two real defects: the card has **no `max-width`**, so it is *narrower at 1024 (406.7px) than at 768 (420px)* and then balloons to 624px at 1920 where the inputs and CTA stretch to ~550px (AUTH-001); and the card is **38px above true vertical centre** at 1440 because the centred flex group includes the copyright line below the card (AUTH-002).

### 4.4 Input field states (brief §5)

Measured on the live login page. `#email` carries `autofocus`, so its "default" reading is the focus state; `#password` was measured for the true resting state.

| State | Border | Background | Box-shadow / outline | Other |
|---|---|---|---|---|
| Default (`#password`) | `#e7e9eb` 1px solid | `#ffffff` | none | h 37.5px, radius 4.8px, text `#4c4c5c`, font 13px, padding 8/12.32px |
| Hover | `#e7e9eb` (unchanged) | `#ffffff` | none | no hover affordance at all |
| Focus | **`#ff5f1f`** | `#ffffff` | `outline: 3px solid #ff5f1f`, offset 2px | brand-correct, clearly visible |
| Filled | `#e7e9eb` | `#ffffff` | none | text `#4c4c5c` |
| Autofill | not observable headlessly | — | — | rule present, see §22 |
| Invalid | **`#f5707a`** | `#ffffff` + inline warning-circle SVG (`stroke='%23f5707a'`) | none | `.invalid-feedback` 12px `#f5707a` |
| Disabled | not present on these screens | — | — | not exercisable |

**Leaks identified:** the default/filled border `#e7e9eb` is `--ct-border-color` straight from `app.min.css` (Zircos), a *cool* grey against the brand's warm `--border #e7e0d3`; the text colour `#4c4c5c` is `--ct-body-color` (Zircos cool slate) against the brand's warm `--body #33261c`; the invalid colour `#f5707a` is `--ct-danger` (Zircos soft pink) against the brand's `--danger #b3261e`. **No blue focus treatment is leaking** — focus is correctly brand orange and no browser-default ring survives. Placeholders are not used anywhere (labels are always real) — correct per the project's own form conventions.

The most serious input finding is contrast, not colour identity: **`#e7e9eb` on `#ffffff` = 1.22:1**, far below the 3:1 WCAG 1.4.11 minimum for identifying a UI component's boundary. That is why the fields look like they are "not fully themed" — at rest they have almost no visible edge.

### 4.5 Checkbox / tick mark (brief §6)

| State | Fill | Border | Tick | Size |
|---|---|---|---|---|
| Unchecked | `#ffffff` | `#e7e9eb` 1px, radius 3.25px | none | **16.25 × 16.25 px** |
| Checked | **`#ff5f1f`** | `#ff5f1f` | white SVG check (`stroke='%23fff'`), via `--ct-form-check-bg-image` | 16.25 × 16.25 px |
| Focus | as above | as above | `outline: 3px solid #ff5f1f` | — |
| Hover | no change | no change | — | — |

**The checkbox is currently brand orange, not blue.** Measured `background-color: rgb(255, 95, 31)` in both light and dark themes.

**Source of the blue the brief describes:** `app.min.css` hardcodes `.form-check-input:checked{background-color:#188ae2;border-color:#188ae2}` — a literal Zircos blue with **no `--ct-*` token behind it**, which is why the earlier accent-token work never reached it. `provatferi-admin.css` now overrides that rule explicitly. A browser holding the stale stylesheet (SYSTEM-006) still renders `#188ae2`. Root cause: **Zircos hardcoded hex, now overridden in source; visible only from cache.**

Remaining real defect: the control is **16.25px**, below the WCAG 2.2 AA 24×24 target-size minimum, and inconsistent with this project's own ADM-016 decision to size reorder controls 40×40 for exactly this reason (AUTH-004). The label extends the hit area, which mitigates but does not satisfy the criterion for the control itself.

### 4.6 Buttons (brief §7)

| Button | Class | Background | Text | Height | Radius | Padding | Source |
|---|---|---|---|---|---|---|---|
| লগ ইন (login CTA) | `btn btn-primary` | `#ff5f1f` | `#201b17` | 37.5px | 4.8px | 8 / 17.6px | brand layer |
| রিসেট লিঙ্ক পাঠান | `btn btn-primary` | `#ff5f1f` | `#201b17` | 37.5px | 4.8px | — | brand layer |
| লগইনে ফিরুন | `btn btn-light` | **`#eef2f7`** | `#313a46` | 37.5px | 4.8px | — | **Zircos `--ct-light`** |
| সংরক্ষণ করুন (profile) | `btn btn-primary` | `#ff5f1f` | `#201b17` | 37.5px | 4.8px | — | brand layer |
| অ্যাকাউন্ট মুছে ফেলুন | `btn btn-outline-danger` | transparent | **`#f5707a`** | 37.5px | 4.8px | — | **Zircos `--ct-danger`** |
| **Public site primary** | `.button-primary` | `#ff5f1f` | `#201b17` | **50.5px** | **8px** | **14 / 24px** | public tokens |

Hover on the CTA resolves to `#ff783f` with `#201b17` text — brand-correct. Ink-on-orange measures **5.61:1**, passing AA; this is a deliberate, documented decision and it holds.

The button *system* is genuinely shared with the admin design system — auth screens are not separately styled. The inconsistency is between **admin and public**: the same primary action is 37.5px/4.8px/13px in the panel and 50.5px/8px/15px on the public site (AUTH-003). And two variants (`btn-light`, `btn-outline-danger`) still resolve entirely to Zircos values.

### 4.7 Typography (brief §8)

| Element | Size | Weight | Line-height | Colour | Family |
|---|---|---|---|---|---|
| `h1` "প্রশাসনিক লগইন" | 18px | 600 | 19.8px | `#4c4c5c` | **`Montserrat, sans-serif`** |
| Subheading | 14px | 400 | 21px | `#9ba6b7` | Noto Sans Bengali |
| Field label | 13px | 600 | 19.5px | `#4c4c5c` | Noto Sans Bengali |
| Forgot-password link | 13px | — | — | `#9ba6b7`, no underline | Noto Sans Bengali |
| Checkbox label | 13px | — | — | `#4c4c5c` | Noto Sans Bengali |
| Button text | 13px | 600 | — | `#201b17` | Noto Sans Bengali |
| Footer | 12px | — | — | `#9ba6b7` | Noto Sans Bengali |
| Required `*` | — | — | — | `#f5707a` | — |
| **Public site `h1`** | 60px | 700 | — | `#1c2430` | **Noto Sans Bengali** |

Two defects. **The heading font is Montserrat** — `app.min.css` sets `h1,h2,h3,h4,h5,h6{font-family:var(--ct-font-family-secondary)}` and the brand layer only ever sets `body{font-family:"Noto Sans Bengali"…}`. Montserrat carries no Bengali glyphs, so "প্রশাসনিক লগইন" renders in whatever Bengali face the operating system happens to substitute — unspecified, unversioned, and different across machines (SYSTEM-002). This affects every heading in the entire panel, not just auth.

And the hierarchy is **flat**: 18px heading → 14px subheading → 13px labels. The heading is 4px larger than a field label and *smaller than the public site's body copy*. Nothing in the type scale signals "this is the page's primary statement" (AUTH-007).

### 4.8 Empty space / composition (brief §9)

At 1440 the card occupies **32.2% of viewport width**; at 1920, 32.5% but a full 624px. Top gap 153.3px, bottom gap 191.3px — a 38px downward bias. Horizontal centring is exact.

This reads as **weak composition rather than intentional breathing room**, for a specific reason: the whitespace is not supporting a hierarchy, because the elements it surrounds have almost no hierarchy of their own (flat type scale, 1.22:1 borders, no shadow, 4.8px radius). Generous space around a high-contrast, well-ranked composition reads as confident; the same space around a low-contrast, flat one reads as unfinished. The fix indicated by the measurements is not *less* space — it is constraining the card (`max-width`) and restoring contrast and type hierarchy inside it.

---

## 5. Forgot-password findings

| Metric | 1440 | 390 |
|---|---|---|
| Card | 464.0 × 472.3 | 342.0 × 479.6 |
| `h1` | 18px | 18px |
| Overflow | 0 | 0 |
| Primary button | `#ff5f1f` / `#201b17`, 37.5px | same |
| Secondary button | **`#eef2f7`** / `#313a46`, 37.5px | same |

Structurally sound and consistent with `/login` (shared `x-layouts.auth`). Inherits SYSTEM-001→005 and AUTH-001/003/007. The only page-specific note is the secondary "লগইনে ফিরুন" button resolving to Zircos `--ct-light` cool blue-grey (SYSTEM-012) directly beneath a brand-orange primary — the two buttons visibly belong to different palettes.

---

## 6. Reset-password findings

Audited at `/reset-password/auditplaceholdertoken?email=audit@example.com` (renders the form; GET performs no token validation).

| Metric | Value |
|---|---|
| Card | 464.0 × 565.8 px |
| Bottom gap | 186.1px |
| Fields | 3 (`email`, `password`, `password_confirmation`) |
| Help text | present — "অন্তত ৮ অক্ষরের শক্তিশালী পাসওয়ার্ড দিন।" at 12px `#9ba6b7` (**2.46:1 — fails AA**) |
| E-mail prefill | works (`audit@example.com` from query) |
| Overflow | 0 |

The tallest auth card (565.8px) with three stacked full-width fields and no visual grouping between "who you are" (e-mail) and "what you're setting" (the two password fields) — AUTH-008. The password-requirement helper text is the single most important instruction on the page and the least legible thing on it.

---

## 7. Profile findings

### 7.1 Framework residue (brief §11) — section-by-section origin

| Section | Rendered by | Style origin | Breeze residue? |
|---|---|---|---|
| Page shell / sidebar / topbar | `layouts.admin` | Zircos + brand layer | No |
| Page title + breadcrumb | `x-admin.page-title`, `x-admin.breadcrumb` | custom admin components | No |
| প্রোফাইল তথ্য card | `profile/partials/update-profile-information-form.blade.php` → `x-admin.card` | custom admin component + Bootstrap `.form-control` | No |
| পাসওয়ার্ড পরিবর্তন card | `profile/partials/update-password-form.blade.php` → `x-admin.card` | same | No |
| অ্যাকাউন্ট মুছে ফেলুন card + modal | `profile/partials/delete-user-form.blade.php` → `x-admin.card`, `x-admin.modal` | same, Bootstrap modal | No |
| Save / danger buttons | `.btn-primary`, `.btn-outline-danger` | brand layer / **Zircos `--ct-danger`** | No (token gap) |
| Success feedback | `x-admin.flash-message` (`session('success')`) | custom admin component | No |
| Validation messages | Laravel framework strings | **Laravel `en` defaults** | **Yes** |

**No Laravel Breeze view or Tailwind component renders on `/profile`, or anywhere else reachable in the app.** Verified: `x-app-layout`, `layouts.app`, `layouts.navigation`, `layouts.guest`, `x-text-input`, `x-primary-button`, `x-danger-button`, `x-secondary-button`, `x-input-label`, `x-input-error`, `x-modal`, `x-application-logo` and `x-dropdown*` have zero references in `resources/views/`. Alpine.js is not used by any rendered view.

The residue that *does* remain is infrastructural, not visual — see §8.

### 7.2 Language consistency (brief §12)

Full-page Latin-script scan of rendered `/profile` returned exactly two runs: **`Provatferi`** and **`Test Updated Name`**. Bengali character count: **664**.

| String | Classification |
|---|---|
| `Provatferi` (breadcrumb root) | **Hardcoded English chrome** — `components/admin/breadcrumb.blade.php:6`. Borderline: it is the institution's name, but the brand wordmark used everywhere else in the panel is `প্রভাতফেরী`. PROFILE-005, P3. |
| `Test Updated Name` | **Not a defect** — local test-database value in the audit environment's `users.name`. Production shows the real administrator's name. |
| `The password is incorrect.` (on failed password change) | **Laravel framework default** (`current_password` rule), no `lang/` override. PROFILE-008 / SYSTEM-007. |
| `Error:` (login, screen-reader-only) | **Hardcoded English** in `auth/login.blade.php:13`. SYSTEM-011, P3. |
| `Please correct the highlighted fields.` (screen-reader-only) | **Hardcoded English** in `components/admin/flash-message.blade.php:29`. SYSTEM-011, P3. |

**Every English string quoted in the audit brief — `Profile`, `Profile Information`, `Update your account's profile information and email address.`, `Name`, `Email`, `SAVE`, `Update Password`, `Current Password`, `New Password`, `Confirm Password` — is absent from the deployed build.** All are Bengali as of release `r8`. Those strings describe the pre-`r8` Breeze version of the page.

### 7.3 Form design consistency (brief §13)

Every control on `/profile` uses the same Bootstrap/Zircos classes as the rest of the ERP — height 37.5px, radius 4.8px, border `#e7e9eb`, background `#ffffff`, focus `#ff5f1f` + 3px outline, labels 13px/600 `#4c4c5c`, help text 12px `#9ba6b7`, card radius 4.8px, no shadow, border `#e7e9eb`. **`/profile` is not on a separate visual system.** It matches the panel exactly — including inheriting every SYSTEM-level token defect.

### 7.4 Layout / width (brief §14)

| Viewport | Card W | % viewport | First input W | Overflow |
|---|---|---|---|---|
| 320 | 272.0 | 85.0% | 222.0 | 0 |
| 360 | 312.0 | 86.7% | 262.0 | 0 |
| 390 | 342.0 | 87.7% | 292.0 | 0 |
| 430 | 382.0 | 88.8% | 332.0 | 0 |
| 768 | 720.0 | 93.8% | 323.0 | 0 |
| **1024** | **976.0** | **95.3%** | **451.0** | 0 |
| 1366 | 714.0 | 52.3% | 320.0 | 0 |
| 1440 | 763.3 | 53.0% | 344.7 | 0 |
| 1920 | 1083.3 | 56.4% | 504.7 | 0 |

Outer `.page-container` = 1181px at 1440. Card gap = 24px, consistent.

Two defects. **Within one page, the same control type renders at two widths**: name/e-mail at **344.7px** (two-column row) and the three password fields at **713.3px** (full-width) — PROFILE-001, the most visible problem on the page. And because the card is `col-xl-8`, which only engages at ≥1200px, **the layout is widest at the narrowest desktop**: 976px (95.3%) at 1024, dropping to 714px at 1366 — PROFILE-002. At 1920 the fields reach 504.7px, well past a comfortable single-line form measure.

Account forms should use a constrained measure rather than tracking the admin content width. Reported as a finding only; no implementation here.

### 7.5 Information hierarchy (brief §15)

Section order is correct: প্রোফাইল তথ্য → পাসওয়ার্ড পরিবর্তন → অ্যাকাউন্ট মুছে ফেলুন (identity → security → destructive).

But the three are **visually indistinguishable**. Measured on the deletion card: `border-color: rgb(231,233,235)`, `border-width: 1px`, `background: rgb(255,255,255)` — byte-identical to the profile-information card. No danger-zone border, tint, heading colour or separator. The only differentiator is the `btn-outline-danger` label, whose colour (`#f5707a`, 2.81:1) is the *least* legible text in the section (PROFILE-003). A distracted administrator gets no pre-attentive signal that this block is destructive.

Page-title hierarchy is near-flat: `h1` 17px vs card titles 15px (PROFILE-004). The danger card also states its warning twice — once as card subtitle, once as body copy (PROFILE-007).

---

## 8. Breeze residue

Zero Breeze **views/components render**. The residue is pipeline and routing:

| ID | Item | Evidence |
|---|---|---|
| SYSTEM-008 | Tailwind CSS bundle `app-DjS_Zhxp.css` (54,324 B) + Alpine bundle `app-BM_zSWCW.js` (106,749 B) built into `public/build/assets/` and deployed to production | `public/build/manifest.json` maps `resources/css/app.css` and `resources/js/app.js`; no reachable Blade references `@vite` |
| SYSTEM-009 | Orphaned stock `resources/views/welcome.blade.php` (the only `@vite` consumer); `resources/css/app.css` (`@tailwind` directives); `resources/js/app.js` (`Alpine.start()`); `resources/js/bootstrap.js`; `tailwind.config.js`; `@tailwindcss/forms`, `@tailwindcss/vite`, `alpinejs`, `tailwindcss`, `autoprefixer`, `postcss` in `devDependencies` | `grep -rn "welcome" routes/` → no route; view unreachable |
| SYSTEM-010 | `GET /register` returns **HTTP 200 on production**. `routes/auth.php` registers it inside the `guest` group; `RegisteredUserController::store` creates a `User` with **no role assignment** | Verified by request; **no account was created** |
| SYSTEM-007 | No `lang/` directory; `APP_LOCALE=en`; `config/app.php` `'locale' => env('APP_LOCALE','en')` | All framework-generated strings render in English |
| AUTH-006 / PROFILE-008 | Visible consequence of SYSTEM-007: `These credentials do not match our records.`, `The password is incorrect.` | Observed on local instance (identical code) to avoid production rate-limit noise |

---

## 9. Zircos / Bootstrap / browser-default residue

Every leaked value traced to its defining token in `public/zircos/css/app.min.css`:

| Observed value | Zircos token | Consumed by | Brand value it should follow | Brand-layer override? |
|---|---|---|---|---|
| `#4c4c5c` | `--ct-body-color` (light) | `h1`, labels, input text, checkbox label | `--body #33261c` (warm) | **No** |
| `#9ba6b7` | `--ct-secondary-color` (light) | `.text-muted`, `.form-text`, footer, forgot-pw link | `--muted #6b6053` (warm) | **No** |
| `#e7e9eb` | `--ct-border-color` (light) | card / input / checkbox borders | `--border #e7e0d3` (warm) | **No** |
| `#f5707a` | `--ct-danger` | `.pf-required`, `.invalid-feedback`, `.btn-outline-danger`, invalid-field SVG | `--danger #b3261e` | **No** |
| `#eef2f7` | `--ct-light` | `.btn-light`, sidebar toggle | no brand equivalent defined | **No** |
| `Montserrat` | `--ct-font-family-secondary` | **all `h1`–`h6`** | `Noto Sans Bengali` | **No** |
| `#aab8c5` | `--ct-body-color` **and** `--ct-heading-color` (dark) | all dark text incl. headings | `--body #ddd6c9` / `--heading #f4f1ea` | **No** |
| `#8391a2` | `--ct-secondary-color` (dark) | dark muted / help text / links | `--muted #a89e8d` | **No** |
| `#37394d` | `--ct-border-color` (dark) | dark card / input borders | `--border #383d45` | **No** |
| `#6c757d` | Bootstrap stock `$gray-600` | sidebar logout, topbar links | — | **No** |
| `#188ae2` | **hardcoded hex, no token** — `.form-check-input:checked` | checked checkbox / radio | `#ff5f1f` | **Yes** (explicit rule) |
| `#eaf3f4→#b3c5df` | **hardcoded gradient, no token** — `.auth-bg` | auth page background | `#fbf9f4` | **Yes** (explicit rule) |

Note the near-miss that explains the whole pattern: the earlier purple-removal work overrode **`--ct-secondary`** (the *theme colour*) but not **`--ct-secondary-color`** (the *muted text colour*). Adjacent names, different jobs — and the one that drives every piece of secondary text on these pages was never brought in.

**No browser-default focus ring leaks.** `provatferi-admin.css` defeats Zircos's `a,button{outline:0!important}` with `:focus-visible` longhands; measured `outline: 3px solid #ff5f1f`, offset 2px, on every focusable.

---

## 10. Brand inconsistency (public ↔ admin, brief §10)

Live-measured on both properties:

| Dimension | Public `provatferi.org` | Admin auth/profile | Verdict |
|---|---|---|---|
| Page background | `#fbf9f4` | `#fbf9f4` | **Match** |
| Brand accent | `#ff5f1f` | `#ff5f1f` | **Match** |
| Ink on accent | `#201b17` | `#201b17` | **Match** |
| Logo usage | official PNG, light/dark pair, 190px header | official PNG, light/dark pair, 190px | **Match** |
| Logo theme swap | `display:none` on inactive | `display:none` on inactive (verified: light `none` / dark `inline`) | **Match** |
| Heading font | Noto Sans Bengali | **Montserrat** | **Mismatch** |
| Heading colour | `#1c2430` | `#4c4c5c` (cool) | **Mismatch** |
| Body text | `#33261c` (warm) | `#4c4c5c` (cool) | **Mismatch** |
| Muted text | `#6b6053` (warm) | `#9ba6b7` (cool, 2.46:1) | **Mismatch** |
| Border | `#e7e0d3` (warm) | `#e7e9eb` (cool, 1.22:1) | **Mismatch** |
| Danger | `#b3261e` | `#f5707a` | **Mismatch** |
| Primary button | 50.5px h, 8px radius, 15px/600, 14/24 padding | 37.5px h, 4.8px radius, 13px/600, 8/17.6 padding | **Mismatch** |
| Card radius | 0px | 4.8px | Allowed to differ (`DESIGN_SYSTEM.md` §10) |
| Background texture | official chrysanthemum AVIF/WebP + `--texture-wash` | none | **Approved treatment unused** |
| Dark-mode default | light-only, `color-scheme: light`, no OS detection | follows `prefers-color-scheme` | Documented, intentional difference |

The two properties share an accent and a logo. They do **not** share a neutral palette, a heading typeface, a semantic red, or a button scale. That is the precise, measurable reason the panel does not read as the same institution: the orange is right and everything supporting it is from a different design language.

---

## 11. Input-state audit summary

| Check | Result |
|---|---|
| Default border brand-correct | **Fail** — Zircos `#e7e9eb` |
| Default border visible (3:1) | **Fail** — 1.22:1 |
| Hover affordance | **Absent** — no hover change on any input |
| Focus visible | **Pass** — 3px `#ff5f1f` outline + orange border |
| Focus brand-correct | **Pass** |
| Filled state | **Pass** (no unexpected change) |
| Autofill themed | **Pass by inspection** — rule present in live CSS (see §22) |
| Invalid state distinct | **Pass** (border + icon + message) |
| Invalid colour brand-correct | **Fail** — Zircos `#f5707a` |
| Invalid text legible | **Fail** — 2.81:1 at 12px |
| Disabled state | **Not exercisable** on these screens |
| Placeholder-as-label misuse | **None** — real labels throughout |

---

## 12. Logo audit summary

Measured 190.0 × 87.0px desktop / 160px < 576px, aspect 2.184 preserved, 40.9% of card width, 46.5px clear space below, sourced from the official 1876 × 859 asset, correct light/dark pair with `display:none` swap. **Matches the public header exactly and matches the brand guideline's stated range.** Not a current defect. Residual issues: base rule `.pf-logo img{height:30px}` still present and overridden (fragile cascade), no `width`/`height` attributes on the `<img>` (layout-shift risk), ~72 KB asset delivered for a 190px render (AUTH-005, P3).

## 13. Background audit summary

Deployed and server-verified as approved brand colour `#fbf9f4` + faint orange radial + 4px orange top rule, with a correct dark variant `#1b1e22`. Zircos's pastel teal→blue gradient is overridden in source. The reported sky-blue is a **stale-cache artefact** (SYSTEM-006), not a code defect. The approved chrysanthemum texture treatment is defined in the brand guidelines but unused in the admin (AUTH-009, P3).

---

## 14. Light / dark mode

### Light

| Surface | Value | Note |
|---|---|---|
| `.auth-bg` | `#fbf9f4` + orange radial + 4px orange top border | brand-correct |
| `body` | `#f5f5f5` | stray non-token value, covered by `.auth-bg` (`min-vh-100`) — not visible, but untokenised |
| Card | `#ffffff`, border `#e7e9eb`, radius 4.8px, **no shadow** | card barely separates from page (1.22:1) |
| Inputs | `#ffffff` / `#e7e9eb` | 1.22:1 |
| Text | `#4c4c5c` (8.41:1 — passes) | cool, off-brand |
| Muted | `#9ba6b7` (2.46:1 — **fails**) | cool, off-brand |
| Links | `#9ba6b7`, no underline (2.46:1 — **fails**) | colour-only link affordance below AA |
| Checkbox | `#ff5f1f` fill, white tick (3.04:1 — passes) | brand-correct |
| Logo | light asset shown, dark hidden | correct |

### Dark (via `prefers-color-scheme: dark`; pre-paint script sets `data-bs-theme="dark"` correctly)

| Surface | Value | Note |
|---|---|---|
| `.auth-bg` | `#1b1e22` + orange radial @0.1 | brand-correct, matches public dark `--bg` |
| Card | `#262a30`, border **`#37394d`** | border has indigo cast and is 1.48:1 vs page — **fails 3:1** |
| Inputs | `#24282e`, border `#37394d` | same |
| Text / heading | both **`#aab8c5`** | 8.26:1 passes, but heading == body colour → **no hierarchy in dark**; cool vs brand warm |
| Muted / help / links | `#8391a2` | **4.49:1 — marginally fails** 4.5:1 |
| Button | `#ff5f1f` / `#201b17` | brand-correct |
| `btn-outline-danger` | `#f5707a` | 5.14:1 — **passes in dark**, fails in light |
| Logo | dark asset shown, light hidden | correct |
| Profile sidebar / topbar | `#1e2228` / `#24282e` | brand-correct (ADM-011 fix holding) |
| Profile cards | `#262a30` | matches admin surfaces |

Dark mode is **wired correctly** — no half-themed panel, correct logo swap, correct background, sidebar/topbar consistent with the dashboard. Its defects are the same untokenised neutrals as light mode, plus the specific dark-only problems of an indigo-cast invisible border and a collapsed heading/body hierarchy.

---

## 15. Responsive

Nine viewports × both themes. **Zero horizontal overflow at every viewport on every audited page.** Card padding is a constant 36px, degrading gracefully to 200px content width at 320px. Mobile stacking on `/profile` is clean (sidebar goes off-canvas, cards stack full-width, buttons remain full-size).

Where it fits but feels wrong:

| Viewport | Page | Issue |
|---|---|---|
| 1024 | `/profile` | Card 976px = **95.3% of viewport**; inputs 451px — near-edge-to-edge, widest rendering of any desktop size (PROFILE-002) |
| 1920 | `/profile` | Card 1083px, inputs 504.7px — form measure far past comfortable reading width |
| 1920 | `/login` | Card 624px, inputs/CTA ~550px — single-line credential fields stretched (AUTH-001) |
| 1024 | `/login` | Card 406.7px — *narrower* than at 768px (420px); non-monotonic progression (AUTH-001) |
| 320–430 | `/login` | Card 85–88.8% of viewport with 36px padding — functional but the widest relative footprint; no defect logged |

---

## 16. Accessibility

Not a WCAG certification — a targeted check of the criteria named in the brief.

| Check | Result |
|---|---|
| Keyboard tab order | **Pass** — e-mail → password → remember → forgot-password → submit; logical and matches visual order |
| Visible focus | **Pass** — `outline: 3px solid #ff5f1f`, offset 2px, on all 5 focusables; survives Zircos's `outline:0!important` |
| Focus indicator contrast | **Pass** — 3.04:1 (≥3:1) |
| Label association | **Pass** — every input has a real `<label for>`; no placeholder-as-label |
| Password field labelling | **Pass** — explicit labels, `autocomplete="current-password"` / `new-password` |
| Required-field indication | **Partial** — `*` plus visually-hidden "(আবশ্যক)" is good practice, but the `*` is `#f5707a` at **2.81:1** |
| Error messages | **Partial** — programmatically associated via `aria-describedby` + `aria-invalid`, icon + text (not colour-only), but text is 12px at **2.81:1**, and the message content is **English** |
| Link contrast | **Fail** — forgot-password link 2.46:1, and colour-only (no underline) |
| Muted / help text contrast | **Fail** — 2.46:1 light, 4.49:1 dark |
| UI-component boundary contrast (1.4.11) | **Fail** — input/checkbox/card borders 1.22:1 light, 1.48:1 dark |
| Checkbox target size (2.5.8, 24×24) | **Fail** — 16.25 × 16.25px control (label extends hit area) |
| Button target size | **Partial** — 37.5px height, below the 44px commonly targeted; topbar user control measures 44px |
| 200% zoom | **Pass (with caveat)** — no horizontal overflow, document height 1800px; tested via CSS `zoom`, not true browser zoom |
| Autofill readability | **Not observable** — see §22 |
| Screen-reader language parity | **Fail** — visually-hidden text is English (`Error:`, `Please correct the highlighted fields.`) while visible text is Bengali |

**12 of 20 measured colour pairs fail their WCAG minimum.** Full computation in §9 / §14; raw values in `_raw-login-detail.json`.

---

## 17. Autofill (brief §22)

`provatferi-admin.css` (confirmed present in the **live** stylesheet via `document.styleSheets` enumeration) contains:

```
input:-webkit-autofill, input:-webkit-autofill:hover, input:-webkit-autofill:focus {
  -webkit-text-fill-color: var(--ct-body-color);
  caret-color: var(--ct-body-color);
  box-shadow: 0 0 0 1000px var(--ct-secondary-bg) inset;
  transition: background-color 9999s ease-in-out;
}
```

This is the correct technique (large inset shadow + `-webkit-text-fill-color`, since autofill ignores `background-color`) and it is token-driven, so it tracks both themes. `app.min.css` contributes only two `.form-floating`-scoped autofill rules, which do not apply here.

**Limitation: real Chrome autofill could not be triggered.** It requires a browser profile with saved credentials, which headless automation does not provide, so no screenshot of an autofilled field exists and the rendering was not visually confirmed. Two residual risks worth noting: the rule inherits `--ct-secondary-bg`/`--ct-body-color`, both **un-branded Zircos tokens**, so a correctly-themed autofill state will still be the *wrong* neutral; and any browser holding the stale stylesheet (SYSTEM-006) has **no** autofill override and will show Chrome's pale-yellow fill.

---

## 18. Trust / professionalism assessment (brief §24)

From an institutional administrator's perspective, the panel currently reads as **a competent custom product with an unfinished surface** rather than a template — but the specific signals that undercut it are identifiable:

**Reads as custom/production:** official logo at correct scale, fully Bengali chrome, a real sidebar information architecture, branded orange CTA with deliberate dark-ink contrast, correct dark mode with no half-themed states, honest form labelling, working breadcrumbs, no console errors, no broken assets.

**Reads as template/prototype:**
1. **Form fields with no visible edge** (1.22:1). The single strongest "unfinished" signal — inputs look like they have not been styled yet.
2. **Washed-out secondary text** (2.46:1). Subheadings, helper text and links look greyed-out/disabled rather than deliberately de-emphasised.
3. **Cool grey against warm orange.** The neutral palette fights the accent; the page feels like two designs overlaid.
4. **English error messages inside Bengali UI.** The most jarring single moment — it exposes the framework underneath at exactly the point of user failure.
5. **A destructive action styled identically to a benign one.**
6. **Two different button scales** between the public site and the panel for the same action.
7. **Heading font that is not the brand font**, resolved by unspecified system fallback for Bengali.
8. **A publicly reachable `/register`** on an internal ERP — for an administrator who notices, this is the least "production system" signal of all.

---

## 19. Issue severity matrix

| Severity | Count | IDs |
|---|---|---|
| **P0** | 0 | — |
| **P1** | 8 | SYSTEM-001, SYSTEM-002, SYSTEM-003, SYSTEM-004, SYSTEM-005, SYSTEM-006, SYSTEM-010, PROFILE-001 |
| **P2** | 10 | SYSTEM-007, SYSTEM-008, SYSTEM-009, AUTH-001, AUTH-003, AUTH-004, AUTH-006, PROFILE-002, PROFILE-003, PROFILE-008 |
| **P3** | 14 | SYSTEM-011, SYSTEM-012, SYSTEM-013, SYSTEM-014, SYSTEM-015, AUTH-002, AUTH-005, AUTH-007, AUTH-008, AUTH-009, PROFILE-004, PROFILE-005, PROFILE-006, PROFILE-007 |
| **Total** | **32** | |

By category: A UI inconsistency 4 · B missing design token 6 · C wrong brand asset usage 1 · D framework residue 6 · E spacing/composition 6 · F language/localisation 4 · G component inconsistency 2 · H responsive 1 · I accessibility 1 · J browser/vendor default leak 1

---

## 20. Recommended remediation order

Ordered by blast radius per unit of change, **not** by severity alone. No implementation is included or implied.

1. **SYSTEM-006 — fingerprint `provatferi-admin.css`.** Until this is done, every other fix on this list is invisible to returning administrators for up to 7 days, and this audit's findings cannot be validated against what any given person sees. Highest leverage item in the report.
2. **SYSTEM-001 — override the seven missing neutral/semantic/typographic tokens** (`--ct-body-color`, `--ct-heading-color`, `--ct-secondary-color`, `--ct-border-color`, `--ct-danger`, `--ct-light`, `--ct-font-family-secondary`). One localised change in the brand layer; closes or materially improves SYSTEM-002/003/004/005/012/014/015 and every contrast failure in §16 at once, across the whole panel rather than just these pages.
3. **SYSTEM-010 — decide and document `/register`'s intended reachability.** Security-facing and cheap; needs an access-control owner's decision, not a UI change.
4. **PROFILE-001 + PROFILE-002 — settle one form measure** for account forms and pick a container that does not peak at 1024px.
5. **PROFILE-003 — give the danger zone a distinct treatment** (border/tint/heading), independent of the button colour.
6. **SYSTEM-007 + AUTH-006 + PROFILE-008 — introduce `lang/bn` and set the locale**, so framework-generated validation/auth messages stop surfacing in English. Larger than it looks; sequence it deliberately rather than patching Blades.
7. **AUTH-001 + AUTH-003 — constrain the auth card's `max-width`** and reconcile the primary-button scale with the public site.
8. **AUTH-004 — raise the checkbox control to the 24×24 minimum**, consistent with the ADM-016 precedent.
9. **SYSTEM-008 + SYSTEM-009 — remove the dead Tailwind/Alpine/Vite pipeline** and the orphaned `welcome.blade.php`, stopping ~161 KB of unused assets from shipping.
10. **AUTH-007 + PROFILE-004 — widen the type scale** so page titles, section titles and labels are distinguishable.
11. **Remaining P3s** — SYSTEM-011/013, AUTH-002/005/008/009, PROFILE-005/006/007 — as a single consolidated polish pass rather than individually.

---

## 21. Master table

| ID | URL | Viewport | Theme | Sev | Category | Exact element | Observed problem | Root cause | User impact | Current source | Recommended direction | Screenshot |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| SYSTEM-001 | all audited | all | both | P1 | B missing design token | `:root` / `[data-bs-theme]` token set | 7 neutral/semantic/typographic tokens never overridden; brand layer overrides 41 `--ct-*` vars, all accent/button/menu/topbar | `provatferi-admin.css` scoped to accent tokens only | Whole panel's neutrals, semantics and heading font remain a Bootstrap template | `public/zircos/css/provatferi-admin.css` vs `app.min.css` | Extend brand layer to the 7 tokens | — |
| SYSTEM-002 | all audited | all | both | P1 | J vendor default leak | every `h1`–`h6` | `font-family: Montserrat, sans-serif`; no Bengali glyphs → OS fallback | `app.min.css`: `h1..h6{font-family:var(--ct-font-family-secondary)}`; brand layer sets only `body` | Every Bengali heading renders in an unspecified, machine-dependent face | `--ct-font-family-secondary` | Point token at Noto Sans Bengali stack | `AUTH-login-1440-light.png` |
| SYSTEM-003 | all audited | all | light+dark | P1 | I accessibility / B | `.text-muted`, `.form-text`, footer, forgot-pw link | `#9ba6b7` → **2.34–2.46:1** light; `#8391a2` → **4.49:1** dark | `--ct-secondary-color` not overridden (`--ct-secondary` was — different token) | Subheadings, helper text and links illegible / look disabled | `--ct-secondary-color` | Map to brand `--muted` at AA-passing value | `AUTH-login-1920-light.png` |
| SYSTEM-004 | all audited | all | light+dark | P1 | I accessibility / B | input, checkbox, card borders | `#e7e9eb` on `#fff` = **1.22:1**; dark `#37394d` = **1.48:1** (both fail 1.4.11 3:1) | `--ct-border-color` not overridden | Form fields have no visible edge at rest — the main "unfinished" signal | `--ct-border-color` | Map to brand `--border`, verify ≥3:1 | `AUTH-login-1920-light.png` |
| SYSTEM-005 | all audited | all | light | P1 | I accessibility / B | `.pf-required`, `.invalid-feedback`, `.btn-outline-danger`, invalid-field SVG | `#f5707a` on `#fff` = **2.81:1** | `--ct-danger` not overridden | Error and destructive text is the least legible text on the page | `--ct-danger` | Map to brand `--danger #b3261e` | `AUTH-login-invalid-state-1440-LOCAL.png` |
| SYSTEM-006 | all audited | all | both | P1 | D framework residue / deployment | `<link href="/zircos/css/provatferi-admin.css">` | `Cache-Control: public, max-age=604800`, **no fingerprint/version** | Hand-authored asset outside any build/versioning pipeline | Returning admins see the **previous** design (sky-blue bg, ~83px logo, blue checkbox) for up to 7 days; every future fix silently delayed | layout `<head>` + server cache headers | Fingerprint or version-query the asset | — |
| SYSTEM-007 | all audited | all | both | P2 | F localisation | all framework-generated strings | No `lang/` dir; `APP_LOCALE=en` | Bengali implemented only as hardcoded Blade strings | Validation/auth messages appear in English inside Bengali UI | `config/app.php`, `.env` | Add `lang/bn`, set locale | `AUTH-login-invalid-state-1440-LOCAL.png` |
| SYSTEM-008 | n/a (payload) | all | both | P2 | D framework residue | `public/build/assets/app-*.{css,js}` | 54 KB Tailwind CSS + 107 KB Alpine JS deployed, referenced by nothing reachable | Breeze toolchain retained after views were migrated | ~161 KB dead payload in production | `public/build/manifest.json` | Remove pipeline or stop shipping | — |
| SYSTEM-009 | n/a (code) | — | — | P2 | D framework residue | `welcome.blade.php`, `resources/css/app.css`, `resources/js/app.js`, `bootstrap.js`, `tailwind.config.js`, 6 devDeps | Orphaned stock Laravel view (only `@vite` consumer) + full Tailwind/Alpine toolchain with no consumer | Not cleaned up with the view migration | Misleads maintainers; keeps dead build alive | `resources/`, `package.json` | Delete with the pipeline | — |
| SYSTEM-010 | `/register` | all | both | P1 | E UX/trust (security-facing) | `GET /register` | Returns **HTTP 200 on production**; controller creates a user with **no role** | Breeze scaffolding route never closed | Anyone can create an account and reach the authenticated shell on an internal ERP | `routes/auth.php`, `RegisteredUserController` | Access-control owner to decide reachability | `LIVE-register-1440.png` (prior session) |
| SYSTEM-011 | `/login`, all admin | all | both | P3 | F localisation / I | `.visually-hidden` text | `Error:` and `Please correct the highlighted fields.` in English while visible text is Bengali | Hardcoded English in Blades | Screen-reader users get a different language than sighted users | `auth/login.blade.php:13`, `components/admin/flash-message.blade.php:29` | Translate SR-only strings | — |
| SYSTEM-012 | `/forgot-password`, `/profile` | all | both | P3 | G component inconsistency | `.btn-light` | `#eef2f7` cool blue-grey beside brand-orange primary | `--ct-light` not overridden | Secondary buttons visibly from another palette | `--ct-light` | Define a brand neutral button | `AUTH-forgot-1440-light.png` |
| SYSTEM-013 | `/profile` | all | both | P3 | J vendor default leak | sidebar logout, topbar links | Bootstrap stock `#6c757d` | No token override | Minor off-brand grey in chrome | Bootstrap `$gray-600` | Route through brand muted token | `PROFILE-1440-light-viewport.png` |
| SYSTEM-014 | all audited | all | dark | P3 | K dark-mode inconsistency | dark card/input borders | `#37394d` has indigo cast; **1.48:1** vs page | `--ct-border-color` (dark) not overridden | Dark borders both off-brand and near-invisible | `--ct-border-color` | Map to brand dark `--border #383d45` | `AUTH-login-1440-dark.png` |
| SYSTEM-015 | all audited | all | dark | P3 | K dark-mode inconsistency | dark headings vs body | `--ct-heading-color` == `--ct-body-color` == `#aab8c5` | Neither overridden | No heading/body hierarchy in dark; cool vs brand warm | `--ct-heading-color` | Distinct warm heading token | `PROFILE-1440-dark-full.png` |
| AUTH-001 | `/login`, `/forgot-password`, `/reset-password` | 768–1920 | both | P2 | E composition | `.card` in `.col-xl-4` | No `max-width`: 420px@768 → **406.7px@1024** → 439.3@1366 → 464@1440 → **624@1920**; inputs/CTA ~550px at 1920 | Percentage grid column, no cap | Non-monotonic sizing; stretched single-line fields on large screens | `components/layouts/auth.blade.php` grid classes | Cap card width (~420–460px) | `AUTH-login-1920-light.png` |
| AUTH-002 | `/login` | ≥768 | both | P3 | E composition | `.auth-bg` flex centring | Card 153.3px from top vs 191.3px from bottom — 38px above true centre | Centred group includes the footer line | Slight optical imbalance | `components/layouts/auth.blade.php` | Centre the card, not card+footer | `AUTH-login-1440-light.png` |
| AUTH-003 | all auth + `/profile` | all | both | P2 | G component inconsistency | `.btn-primary` | 37.5px h / 4.8px radius / 13px text vs public **50.5px / 8px / 15px** for the same action | Two independently-derived button scales | Same CTA feels like two different products | brand layer vs public tokens | Reconcile the two scales | `AUTH-login-1440-light.png` |
| AUTH-004 | `/login` | all | both | P2 | I accessibility | `#remember_me` | Control is **16.25 × 16.25px** — below WCAG 2.2 AA 24×24 | Bootstrap default `1.25em` | Small target; inconsistent with project's own ADM-016 40×40 precedent | `.form-check-input` | Raise to ≥24×24 | `AUTH-login-checkbox-checked-1440.png` |
| AUTH-005 | all auth | all | both | P3 | A UI inconsistency | `.pf-logo img` | Base `height:30px` still present and overridden; no `width`/`height` attrs; 1876×859 / ~72 KB asset rendered at 190px | Layered rules added incrementally | Fragile cascade, layout-shift risk, oversized payload | `provatferi-admin.css`, `components/layouts/auth.blade.php` | Consolidate rule, add dimensions, ship right-sized asset | `AUTH-login-1440-light.png` |
| AUTH-006 | `/login` | all | both | P2 | F localisation | `.alert-danger`, `.invalid-feedback` | `These credentials do not match our records.` in English | SYSTEM-007 | Framework exposed at the point of user failure | Laravel `en` defaults | With SYSTEM-007 | `AUTH-login-invalid-state-1440-LOCAL.png` |
| AUTH-007 | all auth | all | both | P3 | E hierarchy | `h1` / subheading / label | 18px / 14px / 13px — heading only 4px above a field label, smaller than public body copy | No admin type scale defined | Page's primary statement doesn't read as primary | `components/layouts/auth.blade.php` | Widen the scale | `AUTH-login-1440-light.png` |
| AUTH-008 | `/reset-password/*` | ≥768 | both | P3 | E composition | reset form | Tallest card (565.8px), 186.1px bottom gap, 3 stacked fields with no grouping between identity and new-credential fields | Flat field list | Longest auth form is the least structured | `auth/reset-password.blade.php` | Group the two password fields | `AUTH-reset-1440-light.png` |
| AUTH-009 | all auth | all | both | P3 | C wrong brand asset usage | `.auth-bg` | Approved chrysanthemum texture system defined in brand guidelines but unused in admin | Texture never ported from public site | Admin background is an approved colour but not the approved treatment | `COLOR_AND_LOGO_GUIDELINES.md` §3a | Decide whether to port it | `AUTH-login-1440-light.png` |
| PROFILE-001 | `/profile` | ≥1200 | both | P1 | G component inconsistency | password vs name/e-mail inputs | Password fields **713.3px**; name/e-mail **344.7px** — same control type, 2× width, one page | Two-column row above, full-width rows below | Most visible defect on the page; form reads unresolved | `profile/partials/*.blade.php` grid | One form measure for account fields | `PROFILE-1440-light-viewport.png` |
| PROFILE-002 | `/profile` | 1024 / 1920 | both | P2 | H responsive | `.col-xl-8` | Card **976px = 95.3%** at 1024 (widest of any desktop), 714px at 1366, 1083px at 1920; inputs 451→320→504.7px | `col-xl-8` engages only ≥1200px | Layout is widest at the narrowest desktop | `profile/edit.blade.php` | Container that doesn't peak at 1024 | `PROFILE-1440-light-full.png` |
| PROFILE-003 | `/profile` | all | both | P2 | E UX/trust | account-deletion card | `border-color`, `border-width`, `background` **byte-identical** to benign cards; only the button differs — and its label is 2.81:1 | No danger-zone treatment | No pre-attentive warning before a destructive, irreversible action | `profile/partials/delete-user-form.blade.php` | Distinct border/tint/heading | `PROFILE-320-light.png` |
| PROFILE-004 | `/profile` | all | both | P3 | E hierarchy | page `h1` vs `.card-title` | 17px vs 15px — 2px apart | No admin type scale | Page title barely reads as a title | `x-admin.page-title`, `x-admin.card` | Widen the step | `PROFILE-1440-light-viewport.png` |
| PROFILE-005 | all admin | all | both | P3 | F localisation | breadcrumb root | Hardcoded English `Provatferi` while the wordmark elsewhere is `প্রভাতফেরী` | Hardcoded in component | Minor language inconsistency on every admin page | `components/admin/breadcrumb.blade.php:6` | Decide: Bengali or proper noun | `PROFILE-1440-light-viewport.png` |
| PROFILE-006 | `/profile` | ≥1200 | both | P3 | E composition | content area right of card | ~29% of content width empty with no secondary column; card content left-packed | Single-column `col-xl-8` | Page looks unbalanced/unfinished on wide screens | `profile/edit.blade.php` | Either narrow the measure or use the space | `PROFILE-1440-light-viewport.png` |
| PROFILE-007 | `/profile` | all | both | P3 | A UI inconsistency | danger card subtitle + body | Warning stated twice in near-identical terms | Copy duplicated across slot and body | Redundant, dilutes the warning | `profile/partials/delete-user-form.blade.php` | Keep one | `PROFILE-320-light.png` |
| PROFILE-008 | `/profile` | all | both | P2 | F localisation | password-change validation | `The password is incorrect.` in English | SYSTEM-007 | Framework exposed at point of failure | Laravel `en` defaults | With SYSTEM-007 | — |

---

## 22. Limitations

1. **Live `/profile` could not be authenticated.** The only credential on file (`admin-erp/.super-admin-credentials.txt`) does not authenticate against production, and per the standing rule I did not reset the Super Admin password to gain access. `/profile` was therefore audited on a local instance, justified as follows: `git diff --stat 016165a HEAD -- resources/views public/zircos/css app/Http/Controllers` returns **empty**, so the views, brand CSS and controllers rendering locally are byte-identical to the deployed release. This is a faithful stand-in, **not literally production**, and the distinction is preserved throughout the report.
2. **Real Chrome autofill was not triggerable.** It needs a browser profile with saved credentials. Autofill is assessed by CSS-rule inspection of the live stylesheet only; no autofilled screenshot exists, and the brief's requested "password autofilled" capture could not be produced.
3. **200% zoom was emulated via CSS `zoom`**, not true browser zoom, so reflow behaviour is indicative rather than definitive.
4. **Invalid-state testing was done on the local instance** to avoid generating failed-login attempts, rate-limit state and log noise on production.
5. **`Test Updated Name` appears in profile screenshots** — local test-database data from an earlier session, not a production defect.
6. **Contrast ratios are computed** from measured `getComputedStyle` RGB values against their measured backing surface. This is not a WCAG certification and no conformance claim is made; text-size thresholds (large-text 3:1 allowances) were not applied where the measured sizes were 12–14px, i.e. below the large-text threshold.
7. **No account was created on `/register`.** Reachability was established from `GET` returning 200 and the controller source; the end-to-end registration outcome was not exercised on production.
8. **`/reset-password` was audited with a placeholder token.** The form renders (GET does not validate the token), but a real token lifecycle was not exercised.
9. **Hover states** were captured via real pointer movement, but `:active`/pressed and `:disabled` states are not present on these screens and could not be measured in situ.
10. **Screenshots are headless Chrome** on Windows; sub-pixel text rendering may differ slightly from the reviewer's machine.
11. **Sidebar visibility at ≤430px** was reported inconclusively by the DOM probe (off-canvas elements can report non-zero width); resolved visually from `PROFILE-320-light.png` instead, which shows it correctly hidden.
