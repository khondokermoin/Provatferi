# Provatferi Design System — Public + Admin

This documents the shared token system behind provatferi.org (Next.js/Tailwind) and admin.provatferi.org (Laravel/Zircos). It does not introduce a new visual language — it writes down what each system already had, fixes the one real cross-system defect (the unexplained purple/indigo accent), and gives both systems one documented date-formatting policy. Nothing here changes the logo.

Source of truth for color/logo usage: `admin-erp/COLOR_AND_LOGO_GUIDELINES.md`. This file documents the *implementation* of that brand in CSS custom properties on both systems.

## 1. Color

Both systems now share one real neutral and one real accent — no more inventing a second, unrelated hue.

| Token | Public (`institutional/app/globals.css`) | Admin (`admin-erp/public/zircos/css/provatferi-admin.css`) | Value (light) |
|---|---|---|---|
| Accent / primary | `--brand-orange` | `--ct-primary` | `#ff5f1f` |
| Accent hover | `--brand-orange-hover` | `--ct-btn-hover-bg` | `#ff783f` |
| Accent text (on light bg) | `--accent-text` | `--ct-primary-text-emphasis` | `#ac350a` |
| Neutral / secondary text | `--muted` | `--ct-secondary` | `#6b6053` |
| Neutral background | `--soft` | `--ct-secondary-bg-subtle` | `#f5efe1` / `#efe9dc` |
| Neutral border | `--border-strong` | `--ct-secondary-border-subtle` | `#d6cbb6` / `#d9d0bd` |
| Page background | `--bg` | `--ct-body-bg` (light = Bootstrap default, dark override below) | `#fbf9f4` |
| Surface / card | `--surface` / `--card` | `--ct-card-bg` | `#ffffff` |
| Success | `--success` / `--trust-green` | Bootstrap `--ct-success` (unchanged) | `#1f7a4d` |
| Warning | `--warning` | Bootstrap `--ct-warning` (unchanged) | `#9a6b12` |
| Danger | `--danger` | Bootstrap `--ct-danger` (unchanged) | `#b3261e` |

**The purple/indigo fix (ADM-005 / ADM-015 / CROSS-002):** Zircos's compiled `app.min.css` ships Bootstrap's `secondary` slot as an unrelated violet (`#6b5fb5`), never overridden. `provatferi-admin.css` now defines `--ct-secondary*` explicitly, pointed at the exact same warm neutral the public site already uses for `--muted`/`--soft`/`--border-strong` — not a new color, the *existing* one, applied to the one system that was missing it. This fixes every `bg-secondary-subtle` badge, `btn-secondary`, and `text-secondary-emphasis` use at once.

**Semantic vs. brand color — kept separate, per the explicit instruction not to replace every semantic color with orange:** success/warning/danger remain Bootstrap's own tokens, untouched. Only the *accent* (primary actions, active nav, focus rings) and the *neutral* (secondary/muted UI) are brand-governed. A red "Delete" button must never become orange.

## 2. Typography

Both systems load the same face for Bengali text: **Noto Sans Bengali** (Google Fonts, weights 400–700). Admin's `body` rule explicitly falls back through `"Open Sans", system-ui, -apple-system, "Segoe UI", sans-serif` for any Latin-only chrome that survives the language pass (route params, code values). Public uses `next/font`'s optimized Noto Sans Bengali as `--font-sans` site-wide, no separate Latin stack declared (acceptable today since public is Bengali-first with no numeric-table-heavy screens; admin's tables are where a Latin fallback actually matters, and it already has one).

No numeric type scale was tokenized on either system before this pass, and a full re-scale is out of Phase 1 scope (see Deferred). What *is* fixed: neither system should introduce a third typeface. Loanwords transliterated into Bengali (ড্যাশবোর্ড, সিস্টেম, অ্যাকাউন্ট, প্রোফাইল, সেটিংস) are treated as Bengali vocabulary, not English chrome — see the language policy below.

## 3. Spacing, radius, borders

Public already tokenizes spacing per-component rather than on a strict 4/8px scale (e.g. `.info-card { padding: 24px }`, `.button { padding: 14px 24px }`) — consistent by convention, not by enforced scale. Admin inherits Bootstrap's own spacing utilities (`.mb-3`, `.gap-2`, etc.), which is a real 0.25rem-based scale already. Border radius: public uses `10px` (cards) / `8px` (buttons) / `999px` (pills); admin uses Bootstrap's `--ct-border-radius` (`0.25rem`≈`4px` default, cards at `0.5rem`). These are close enough to read as "the same family of roundedness" without being pixel-identical — intentionally not unified further in Phase 1 (see §7, what should differ).

## 4. Buttons

- **Primary action** — brand orange fill, dark ink text (not white — `#ff5f1f` only clears 3.04:1 contrast with white, below AA; dark ink clears 5.61:1). Public: `.button-primary`. Admin: `.btn-primary` (overridden in `provatferi-admin.css` to force ink-on-orange in every state, including hover/active/disabled).
- **Secondary / neutral action** (e.g. Filter, Cancel) — light/neutral fill, never the brand accent, never purple. Public: `.button-outline`. Admin: `.btn-light.border` — now the *only* class used for this role across every list page (the one stray `.btn-secondary` on Organizational Units was the ADM-005 bug and is fixed).
- **Destructive action** — Bootstrap `.btn-danger` (admin) — untouched, must keep reading as danger, never re-skinned orange.

## 5. Forms

Input height, label placement, and required-field marking (`.pf-required`, a red asterisk) were already consistent across admin's well-built forms (Activities, About/Mission/Vision) — this pass didn't need to change input chrome, only labels/headings (see language policy). Public has no authenticated forms in scope for Phase 1.

## 6. Dark mode

Both systems use the same mechanism shape: a `data-theme`/`data-bs-theme` attribute on the root element, a pre-paint inline script (no flash-of-wrong-theme), and CSS custom-property overrides — never literal colors duplicated per component.

**Admin's dark-mode fix (ADM-011):** Zircos's own compiled CSS keys the sidebar/topbar background off separate `data-menu-color`/`data-topbar-color` attributes via a combined selector (`:root[data-menu-color=dark][data-bs-theme=dark]`) that is *more specific* than a plain `[data-bs-theme="dark"]` override. That let Zircos's own near-black values silently win whenever the two attributes were out of sync — the half-light/half-dark bug. `provatferi-admin.css` now marks its `--ct-menu-*`/`--ct-topbar-*` dark values `!important`, so the sidebar and topbar always follow `data-bs-theme="dark"` regardless of the other two attributes. Public's dark mode had no equivalent defect (single attribute, single source of truth).

Admin's login screen (`auth.blade.php`) previously had no dark-mode background at all for `.auth-bg` — added, matching the same charcoal (`#1b1e22`) both systems already use as their dark page background.

## 7. Date formatting policy (CROSS-004 / ADM-013)

**Decision:** Public and admin are allowed to differ in *detail* (admin needs timestamps for audit trails; public only ever shows a date), but both must be Bengali-localized and human-readable — neither may show raw Latin-digit, English-month dates like "05 Sep 2026" in a Bengali-first interface.

- **Public** (unchanged): compact `YYYY-MM-DD` with Bengali digits, e.g. `২০২৬-০৯-০৫` — `lib/api/activities.ts`'s `toDisplayDate()`.
- **Admin** (new): human-readable Bengali date, e.g. `৫ সেপ্টেম্বর ২০২৬`, with time where needed: `৫ সেপ্টেম্বর ২০২৬, ১৪:৩০` — `app/helpers.php`'s `bn_date()` / `bn_datetime()` / `bn_month_year()`. Chosen over matching public's compact style because admin's tables and detail screens are read by trained staff who need month/day clarity at a glance, not a sortable-looking numeric string.

Both are pure display transforms — **no stored date, no `<input type="date">` value, and no database column changed**. The `Y-m-d` values bound to native date pickers are untouched.

## 8. Admin language policy (§8 of the Phase 1 brief)

**Policy:** administrative navigation, page titles, breadcrumbs, and primary actions are Bengali-first. Raw technical identifiers (`module.action` permission slugs, route names) are never shown as the primary label — only as a tooltip/title attribute for the rare case someone needs the literal key. Common Bengali-software loanwords (ড্যাশবোর্ড, সিস্টেম, অ্যাকাউন্ট, প্রোফাইল, সেটিংস) are treated as Bengali, not English chrome.

**Implemented this phase:**
- Sidebar navigation — every group and item label (`resources/views/components/admin/sidebar.blade.php`).
- Breadcrumbs and page titles — all 20 admin controllers (`app/Http/Controllers/Admin/*.php`), covering every list/create/edit/show screen.
- Primary "Create X" buttons on every module's list page.
- "Search" field labels and "Filter" buttons on every list page's toolbar.
- The Permissions screen (module + action labels; raw `module.action` slug moved to a tooltip).
- The Site Settings screen (card titles and the one jargon field label, "ক্যানোনিক্যাল ওয়েবসাইট URL" → "ওয়েবসাইটের মূল ঠিকানা (URL)").

**Deferred (see the Phase 1 report's item P for the honest scope of what's left):** table column headers (Name/Type/Status/Actions/etc.) and row-level action links (View/Edit/Delete inside dropdown menus) across roughly 15 list tables, plus secondary filter-field labels (e.g. "Unit type", "All statuses"). This is real, mechanical, well-scoped follow-up work — not a design decision that needs re-litigating, just more of the same pattern applied file by file.

## 9. What must stay consistent across both systems

- The accent color — one orange, one meaning (primary action), everywhere.
- The logo and its light/dark asset-swap approach (unchanged by this phase).
- The date policy above — a deliberate difference in detail, not an accident.
- Focus states — both systems now render a visible focus ring on every focusable control (admin's `:focus-visible` override predates this phase; public's was already present).

## 10. What is allowed to differ

- **Density** — admin's tables/forms are denser than public's marketing layout; this is correct for an operational tool used repeatedly by trained staff.
- **Motion/decoration** — public's hero art, timelines, and callout cards have no reason to exist in admin.
- **Radius/spacing exact values** — both read as "the same rounded, breathing-room family" without being pixel-identical; unifying further is not a Phase 1 goal.
