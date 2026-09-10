# Phase 1 Visual Review Package

Status: **code freeze — nothing deployed, nothing pushed, no further UI changes made while preparing this package.** All screenshots were captured against local dev servers (Next.js on production data, Laravel against a local seeded MySQL copy). Production (provatferi.org / admin.provatferi.org) still reflects the pre-Phase-1 state shown in every BEFORE image.

Local commits: `0af9191` (institutional), `621d92b` (admin-erp), `07d6f06` (docs) — 3 commits ahead of `origin/main`, **not pushed**.

---

## 1. Reviewer corrections applied to the register (not to the UI)

- **ADM-009 stays P1.** No individual task-blocking language failure was found on the permissions screen (the module/action grouping already works, in Bengali, without the raw key visible as primary text) — it was never actually P0 in `UI_UX_ISSUES_MASTER.md`; an earlier chat summary of mine mis-stated it as P0, which is corrected here.
- **ADM-002 is explicitly kept OPEN as P1**, not closed. See §5 below — real remaining English surface was found by fresh inspection, not assumed away.
- **Governance copy flagged, not changed** — see §3.

---

## 2. Curated screenshot count

**61 PNGs** in `design-audit/phase1-review/` (31 BEFORE + 30 AFTER-and-evidence), split:
- `design-audit/phase1-review/public/` — 22 public-site images
- `design-audit/phase1-review/admin/` — 39 admin images (includes the 6-step real dark-mode toggle trace)

This is a curated subset, not the full ~99-image audit set or the ~44-image prior QA pass.

---

## 3. Governance copy — FLAGGED FOR REVIEWER DECISION (not changed)

**Confirmed present in the live-rendered public page** (`/organization`, verified via `document.body.innerText` on the running page, not just source inspection):

> "প্রকৃত কমিটি গঠিত হলে এখানে হালনাগাদ করা হবে — **কোনো নাম উদ্ভাবন করা হয়নি**।"
> ("...will be updated here once the real committee is formed — **no names have been invented**.")

The bolded clause is an implementation/audit disclosure ("we did not fabricate data"), not institutional copy a visitor needs. It reads as the site talking about its own CMS policy rather than talking to the visitor. See `public/AFTER-04-organization-1440.png` for the full rendered section (leadership card, vacancy heading, vacancy pill list, and this note all visible together, per your request in §6).

**No change made.** Options for your decision, listed only for reference (not implemented): drop the clause entirely and let "নিয়োগ প্রক্রিয়াধীন" carry the meaning on its own; or move the disclosure to an About/Transparency page where "no fabricated names" already appears as an institutional commitment (see `transparencyCommitment` in `lib/content.ts`) rather than restating it mid-list.

---

## 4. Nav dropdown scrim — FLAGGED FOR REVIEWER DECISION

Captured per your exact spec at 1366 and 1440: closed, open, and keyboard-focused/opened.

| State | 1366 | 1440 |
|---|---|---|
| Closed | `public/AFTER-03-nav-closed-1366.png` | `public/AFTER-03-nav-closed-1440.png` |
| Open (mouse) | `public/AFTER-03-nav-open-1366.png` | `public/AFTER-03-nav-open-1440.png` |
| Keyboard-focused toggle | — | `public/AFTER-03-nav-keyboard-focused-1440.png` |
| Opened via keyboard (Enter) | — | `public/AFTER-03-nav-keyboard-open-1440.png` |
| Focus ring on first link inside | — | `public/AFTER-11a-focus-dropdown-link-1440.png` |

BEFORE (bug state, from the original audit): `public/BEFORE-03-dropdown-1440.png` and `public/BEFORE-03b-dropdown-1366.png` (the latter is the original bug-evidence screenshot at `institutional/design-review/audit-2026-09-08/`).

**No further changes made.** Genuinely open question for you: the scrim dims the entire page below the header to ~40% opacity while the dropdown is open — this is a standard mega-menu pattern, but "standard" and "right for this institutional site" aren't the same judgment. Decide from the screenshots whether it reads as professional navigation or unnecessarily modal/heavy.

---

## 5. ADM-002 — explicitly still OPEN, with new concrete evidence

Re-inspected the current code (not assumed complete from the prior session's report) and found real remaining English surface:

**Status badges render in English everywhere, independent of all translation work done so far.** `resources/views/components/admin/status-badge.blade.php` derives its label as `ucwords(str_replace('_', ' ', $status))` directly from the raw status slug — it does not read the Bengali-labeled `Model::STATUSES` constants at all. So every "Active"/"Draft"/"Published"/"Pending Review"/"Under Review" badge across the entire admin panel is English today, on every table this session touched. Confirmed live: `AFTER-17-table-1440.png`'s Positions table badge text via DOM query returned `["Active"]`.

**4 filter-form labels were missed in the prior sweep**, found by fresh grep, not screenshot:
- `resources/views/admin/committees/index.blade.php:25` — "Unit"
- `resources/views/admin/positions/index.blade.php:25` — "Unit"
- `resources/views/admin/recruitment/applications/index.blade.php:25` — "Posting"
- `resources/views/admin/users/index.blade.php:34` — "Role"

None of these were changed. Evidence: `admin/AFTER-15-permissions-1440.png`, `admin/AFTER-16-settings-1440.png`, `admin/AFTER-17-table-1440.png`, `admin/AFTER-18-table-390.png`, `admin/AFTER-14-sidebar-390.png`, `admin/AFTER-focus-form-control-1440.png` (create form) — sidebar, dashboard, permissions, settings, one table, and one create form, as requested in §9.

**What is genuinely done:** sidebar, breadcrumbs/page titles, dashboard, permissions module/action names, settings labels, Create/Search/Filter buttons, table *column headers*, dropdown row-action *text* (Edit/Delete/View/Activate/Deactivate/Back). What is genuinely not done: status badge values (the largest remaining piece), the 4 filter labels above, and no exhaustive re-check was done beyond these two targeted sweeps — there may be more.

---

## 6. Admin login — before/after, judged on more than background color

| | BEFORE | AFTER |
|---|---|---|
| Light, 1440 | `admin/BEFORE-10-login-1440.png` | `admin/AFTER-10-login-1440.png` |
| Light, 390 | `admin/BEFORE-11-login-390.png` | `admin/AFTER-11-login-390.png` |
| Dark, 1440 | `admin/BEFORE-13-dashboard-dark-1440.png` *(no dark login existed before — see note)* | `admin/AFTER-10b-login-dark-1440.png` |

Note: there was no BEFORE dark-mode login screenshot in the original audit set, because `.auth-bg` had no dark-mode rule at all before this phase — landing on this screen in dark mode previously rendered the light gradient regardless of theme. The AFTER dark screenshot is a genuinely new capability, not a comparison.

What changed and what didn't, for your judgment: background (gradient → solid cream + faint orange radial tint + 4px orange top rule matching the public header), logo (unchanged — same asset, same size), form width/composition (unchanged — same card, same field order), typography (unchanged). This is a background/frame change around an already-correct form, not a full recomposition — flag if you expected more (e.g., logo scale, card width, or added copy) to change.

---

## 7. Admin dark mode — real toggle sequence (not a localStorage shortcut)

Executed your exact 11-step sequence via the actual dropdown UI control (`data-theme-choice` buttons), reading `data-bs-theme`/`data-menu-color`/`data-topbar-color`/localStorage after every step:

| Step | Screenshot | `data-bs-theme` | `data-menu-color` | `data-topbar-color` | localStorage |
|---|---|---|---|---|---|
| 1. Load light | `admin/AFTER-toggle-step1-light.png` | light | light | light | (unset) |
| 2–3. Click Dark | `admin/AFTER-13-dashboard-dark-1440.png` | dark | dark | dark | dark |
| 4–5. Navigate (Positions) | `admin/AFTER-toggle-step5-navigate-dark.png` | dark | dark | dark | dark |
| 6–7. Reload | `admin/AFTER-toggle-step7-reload-dark.png` | dark | dark | dark | dark |
| 8–9. Click Light | `admin/AFTER-toggle-step9-light.png` | light | light | light | light |
| 10–11. Navigate again | `admin/AFTER-toggle-step11-navigate-light.png` | light | light | light | light |

**All three state attributes stayed synchronized at every step, in both directions, across reload and navigation.** No desync was reproduced against the current code — sidebar, header, content, cards, and tables (visible together in each full-page screenshot) render as one consistent theme throughout. This is a real result, not a CSS mask over desynced attributes: the attributes themselves never disagreed. Reported honestly as fully fixed, based on this trace; it does not rule out a timing edge case this specific sequence didn't hit.

---

## 8. Language consistency evidence

See §5 for the full finding. Screens captured: sidebar (`admin/AFTER-14-sidebar-390.png`), dashboard (`admin/AFTER-12-dashboard-light-1440.png`), permissions (`admin/AFTER-15-permissions-1440.png`), settings (`admin/AFTER-16-settings-1440.png`), one table (`admin/AFTER-17-table-1440.png`), one create form (`admin/AFTER-focus-form-control-1440.png`). Proper nouns (Provatferi) and the accepted loanwords already documented in `DESIGN_SYSTEM.md` §8 (ড্যাশবোর্ড, সিস্টেম, অ্যাকাউন্ট, প্রোফাইল, সেটিংস) are not counted as defects.

---

## 9. Date presentation — same real activity, both systems

Public detail page (`public/AFTER-07-activity-detail-1440.png`, activity `sochetonota-mulok-kormosuchi-2026-09-05`): displayed date **২০২৬-০৯-০৫**.

Admin cannot show the same *record* today — the local dev database has zero seeded activities (production's 3 real activities aren't mirrored locally, and none were fabricated to force a screenshot, per your no-fake-content rule). Instead, verified the same underlying value directly through the admin date helper: `bn_date('2026-09-05')` → **৫ সেপ্টেম্বর ২০২৬** (confirmed via `php artisan tinker` against the real function, not a mockup).

**Why public uses the less human-readable format:** public's `২০২৬-০৯-০৫` is Bengali-digit ISO order — compact, scannable in a card/list context, and it was the pre-existing convention already hand-written into `lib/content.ts`'s fallback data before this phase (changing it would mean touching both the live-fetch formatter and every literal fallback string). Admin's `৫ সেপ্টেম্বর ২০২৬` was chosen new, for admin only, on the theory that staff reading a table benefit more from an unambiguous month name than a numeral-only date. No change made — this is exactly the judgment call you asked to make, not me.

---

## 10. Accessibility spot checks

- Keyboard focus on public nav dropdown: `public/AFTER-03-nav-keyboard-focused-1440.png` (toggle) and `public/AFTER-11a-focus-dropdown-link-1440.png` (first item inside, opened via keyboard).
- Keyboard focus on a public form control: **not capturable** — the public site currently has no real `<input>`/`<textarea>`/`<select>` on any page (Membership and Recruitment are both "contact us directly" pages with no live application form yet, consistent with the earlier audit's finding). Captured the one real interactive control instead: `public/AFTER-focus-filter-chip.png` (an Activities category filter button).
- Keyboard focus on an admin form control: `admin/AFTER-focus-form-control-1440.png` (Positions create form, first field).
- Keyboard focus on the sidebar nav: `admin/AFTER-focus-sidebar-nav-1440.png`.
- Reorder-button keyboard focus: `admin/AFTER-focus-reorder-button-1440.png`.
- **Reorder control actual dimensions, measured via `getBoundingClientRect()` on the live page: 36×32px.** This clears the WCAG 2.2 AA minimum (24×24px) but is below the "practical"/AAA-adjacent 44×44px guidance some teams target — reported as measured, not rounded up.
- 200% zoom: `public/AFTER-zoom200-home-1440.png`, `admin/AFTER-zoom200-dashboard-1440.png`. Not evaluated for a formal reflow pass (no horizontal-scroll or clipped-content check performed beyond visual capture) — flagged as not fully verified, matching last session's stated limitation.

---

## 11. 62-file change breakdown

**Public (institutional) — 8 files, commit `0af9191`:**

| Category | Count | Files |
|---|---|---|
| Design system | 1 | `app/globals.css` |
| Pages | 3 | `app/(site)/page.tsx`, `app/(site)/organization/page.tsx`, `app/(site)/activities/[slug]/page.tsx` |
| Components | 2 | `components/Header.tsx`, `components/ActivityFilter.tsx` |
| Data layer | 2 | `lib/content.ts`, `lib/api/activities.ts` |

**Admin (admin-erp) — 55 files, commit `621d92b`:**

| Category | Count | Files (representative) |
|---|---|---|
| CSS/theme | 1 | `public/zircos/css/provatferi-admin.css` |
| Layouts | 2 | `resources/views/layouts/admin.blade.php`, `resources/views/components/admin/sidebar.blade.php` |
| Controllers / string localization | 22 | 20 `app/Http/Controllers/Admin/*.php` + `app/helpers.php` (new) + `composer.json` (autoload registration for helpers.php) |
| Blade views | 28 | every list/show/form view touched by header/action/date translation (full list in the commit) |
| JS / theme-state logic | 0 | `public/js/provatferi-theme.js` was **not** touched — the dark-mode fix is CSS-specificity only |
| Tests | 2 | `tests/Feature/Admin/OrganizationUnitTest.php`, `tests/Feature/Admin/RolePermissionTest.php` |

**Flag: no file was changed for a reason outside the approved UI/UX phase.** The 2 test files were only touched because they asserted the exact English strings this phase translated (updated to assert the new Bengali text, same behavior tested). `composer.json` was only touched to register the new date-formatting helper file. No controller business logic, route, migration, or model changed.

---

## 12. Commit state

| Commit | Scope | Pushed? |
|---|---|---|
| `0af9191` | institutional (public) | No |
| `621d92b` | admin-erp (admin) | No |
| `07d6f06` | docs / design system / issue-register corrections | No |

`git status -sb`: `main...origin/main [ahead 3]`. Working tree is clean except two pre-existing untracked files unrelated to this work (`institutional/AGENTS.md`, `institutional/CLAUDE.md`), never touched this session. **Nothing pushed, nothing deployed, no CDN purge run.**

---

## 13. Things that may need a redesign decision before production (not fixed, flagged only)

1. The governance page's "কোনো নাম উদ্ভাবন করা হয়নি" clause (§3) — audit language leaking into visitor copy.
2. The nav dropdown scrim (§4) — a real design-feel judgment call, not a bug.
3. Admin status badges (§5/§8) — the single largest remaining ADM-002 surface; not started.
4. The public/admin date-format split (§9) — currently a deliberate difference, open to being unified per your closing decision.
5. Admin login recomposition depth (§6) — background changed; logo scale, card width, and typography did not. Confirm this matches what "template feel is gone" should mean before considering it settled.
