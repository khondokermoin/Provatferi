# Phase 1 — Final Visual Approval Package

Code-frozen. No code was modified while preparing this package — it is pure capture, measurement, and reporting against the same local Phase-1 implementation already committed (`0af9191`, `621d92b`, `07d6f06`, `6c2e722` — see §12). Nothing pushed, nothing deployed.

23 PNGs in `design-audit/phase1-final-review/{public,admin}/` — a small, curated set (list below), not the historical 99/61-image sets.

---

## 1. Public screenshots (A–H)

| Ref | File | State |
|---|---|---|
| A | `public/A-homepage-1440-light.png` | Homepage, 1440, light |
| B | `public/B-nav-dropdown-open-1440.png` | Nav dropdown open, 1440, light — viewport-only so hero is in frame |
| B2 | `public/B2-nav-keyboard-focus-open-1440.png` | Nav dropdown opened via keyboard (Tab→Enter), 1440 |
| C | `public/C-nav-dropdown-open-1366.png` | Nav dropdown open, 1366, light |
| D | `public/D-organization-1440-light.png` | Organization/Governance, 1440, light |
| E | `public/E-organization-390-light.png` | Organization/Governance, 390, light |
| F | `public/F-activity-detail-1440-light.png` | Activity detail (real activity: সচেতনতামূলক কর্মসূচি), 1440, light |
| G | `public/G-activity-detail-390-light.png` | Same activity detail, 390, light |
| H | `public/H-homepage-1440-dark.png` | Homepage, 1440, dark |

The nav "closed" state is visible as the top of every other screenshot's header (B was taken from the same page load as A, before opening) — no separate closed-state file was needed since A already shows it at 1440.

## 2. Admin screenshots (I–Q)

| Ref | File | State |
|---|---|---|
| I | `admin/I-login-1440-light.png` (= `admin/E-login-AFTER-1440.png`) | Login, 1440, light |
| J | `admin/J-login-390-light.png` | Login, 390, light |
| K | `admin/K-dashboard-1440-light.png` | Dashboard, 1440, light |
| L | `admin/L-dashboard-1440-dark.png` | Dashboard, 1440, dark (reached via the real UI toggle, not a localStorage shortcut) |
| M | `admin/M-permissions-1440-light.png` | Permissions, 1440, light |
| N | `admin/N-settings-1440-light.png` | Site Settings, 1440, light |
| O | `admin/O-table-1440-light.png` | Positions (representative CRUD table), 1440, light |
| P | `admin/P-table-390-light.png` | Same table, 390, light |
| Q | `admin/Q-reorder-390-full.png` + `admin/Q-reorder-closeup-390.png` | Objectives reorder controls, 390, full screen + tight crop |
| E-BEFORE | `admin/E-login-BEFORE-1440.png` | Login, 1440, light, **pre-Phase-1** (from the original production audit) |

---

## 3. Navigation — geometry only, no verdict

Measured live via `getBoundingClientRect()` / `getComputedStyle()`, both required widths:

| Metric | 1440 | 1366 |
|---|---|---|
| Dropdown size | 230 × 223 px | 230 × 223 px |
| Dropdown position (top, left) | 214.3, 168.95 | 214.3, 131.95 |
| H1 size | 585.9 × 156 px | 585.9 × 156 px |
| H1 position (top, left) | 336.8, 120 | 336.8, 83 |
| **Overlap rectangle (dropdown ∩ H1)** | **230 × 100.5 px** | **230 × 100.5 px** |
| Header height | 216.3 px | 216.3 px |
| Header `position` / `z-index` | relative / 21 | relative / 21 |
| Scrim `position` / `z-index` | fixed / 20 | fixed / 20 |
| Scrim computed `opacity` (open) | 1 (the 40% darkening is baked into the rgba background, not a separate opacity transform) | 1 |
| Scrim `background-color` | `rgba(20, 15, 10, 0.4)` | `rgba(20, 15, 10, 0.4)` |
| Dropdown `z-index` | 40 | 40 |
| Dropdown `background-color` | `rgb(255, 255, 255)` (fully opaque) | `rgb(255, 255, 255)` |
| Dropdown `box-shadow` | `rgba(20,15,10,.22) 0 16px 32px` | same |

At both widths the dropdown geometrically overlaps a **230×100.5px region of the H1** — identical at 1366 and 1440 because the dropdown's height/position don't change with viewport width in this range, only its horizontal offset does. The scrim sits at z-index 20 (above normal content, below the header at 21 and the dropdown at 40) with a fixed 40%-black background — it does not fade in via an opacity transition value distinct from 1; the darkening is the rgba alpha itself. No opinion offered on whether this reads as professional or heavy — that's the screenshots' job (B, B2, C).

## 4. Governance — every visible sentence in `#committee`, verbatim

Extracted from the live-rendered page, in DOM order:

1. **H2**: পরিচালনা কমিটি
2. **P** (intro): প্রভাতফেরীর বর্তমান কমিটি একটি ৩ মাস মেয়াদি অন্তর্বর্তীকালীন সাংগঠনিক কমিটি, যার উদ্দেশ্য প্রতিষ্ঠানকে সংগঠিত করা, দায়িত্ব বণ্টন করা এবং ভবিষ্যৎ স্থায়ী কাঠামোর ভিত্তি তৈরি করা। মোট **15**টি পদের মধ্যে বর্তমানে **1**টি পদ পূরণ হয়েছে; বাকি পদগুলোতে দায়িত্বশীল ব্যক্তি নির্ধারণ প্রক্রিয়াধীন।
3. **span.info-card-tag**: দায়িত্বে আছেন
4. **H3** (leadership name): মেহেদী হাসান রনি
5. **P** (leadership title): প্রতিষ্ঠাতা ও নির্বাহী পরিচালক
6. **H3.vacancy-heading**: গঠনতান্ত্রিক পদ — নিয়োগ প্রক্রিয়াধীন
7. **P.vacancy-note**: গঠনতন্ত্র অনুযায়ী নির্ধারিত বাকি **14**টি পদে দায়িত্বশীল ব্যক্তি নির্ধারণের প্রক্রিয়া চলছে। প্রকৃত কমিটি গঠিত হলে এখানে হালনাগাদ করা হবে — **কোনো নাম উদ্ভাবন করা হয়নি।**
8. Plus 14 individual `.vacancy-row` list items, each just a position title + a "শূন্য" pill (e.g. সভাপতি / শূন্য, সহ-সভাপতি / শূন্য, …) — not reproduced individually here, visible in full in screenshot D.

**FLAGGED (confirmed present, unchanged):** sentence 7's final clause, "কোনো নাম উদ্ভাবন করা হয়নি" ("no names have been invented"), is implementation/audit commentary — it explains a CMS policy decision to the visitor rather than telling them anything about the organization. Still live, not touched.

**New finding this pass, also flagged, not touched:** sentence 2 and sentence 7 both render their counts in **Latin numerals** ("15টি", "1টি", "14টি") inside otherwise fully-Bengali sentences, next to Bengali-digit vacancy tags elsewhere on the same page. Sentence 2's pattern pre-dates Phase 1; sentence 7 (written during Phase 1) repeats it. This is a real, live numeral-consistency defect distinct from the copy-tone issue above — noted for your decision, not corrected.

## 5. Login — before/after, full viewport, same width

- BEFORE (production, pre-Phase-1): `admin/E-login-BEFORE-1440.png`
- AFTER (local, current): `admin/E-login-AFTER-1440.png` (identical file to `admin/I-login-1440-light.png`)

Both are full, uncropped 1440px viewport captures. What changed, factually, for your judgment on template residue/logo prominence/form width/whitespace/typography: background only (stock teal→blue gradient replaced with solid cream + a faint orange radial tint + a 4px orange top rule matching the public header). Logo asset, size, card width, field order, and typography are byte-identical to before.

## 6. Reorder target — measured, not asserted

`.pf-reorder-btn` (the up/down objective-reorder buttons), measured live via `getBoundingClientRect()`/`getComputedStyle()` at 390px:

| Metric | Value |
|---|---|
| Clickable width (up button) | 36 px |
| Clickable height (up button) | 32 px |
| Clickable width (down button) | 36 px |
| Clickable height (down button) | 32 px |
| Icon (`<i class="ti ti-chevron-up/down">`) rendered size | 12.73 × 12.59 px |
| Icon computed `font-size` | 12.6px |
| **Vertical gap between the two adjacent targets** (bottom of up → top of down) | **6 px** |
| Container `gap` (CSS) | 6px |
| Container width | 36px |

36×32 clears the WCAG 2.2 AA minimum target size (24×24px) but sits below the 44×44px practical/AAA-adjacent guidance. The 6px gap between the two stacked targets is tight — worth your explicit judgment on mis-tap risk at that spacing, independent of the target-size number alone. This measurement supersedes the "clears WCAG AA" framing from the prior report: the number is reported here without a pass/fail characterization, per your instruction not to treat aria-label/title presence as sufficient.

## 7. Date format — same real activity, both systems, exact strings

- **Public** (`public/F-activity-detail-1440-light.png`, activity `sochetonota-mulok-kormosuchi-2026-09-05`): rendered date string is exactly **২০২৬-০৯-০৫**.
- **Admin**: the local dev database has zero seeded activities (confirmed again this pass: `Activity::count()` → `0`; the "1 row" a table query briefly returned was the empty-state `<tr>`, not a record — verified and corrected before reporting). No admin screenshot of this exact record is possible without seeding data, which was not done (no fabricated/copied records created). The admin date **function** was re-verified directly against the same real value: `bn_date('2026-09-05')` → exactly **৫ সেপ্টেম্বর ২০২৬**. This is the function's real output, not a mockup — but it is not a live-page screenshot of that string next to a real row, and that gap is reported as a real limitation, not closed.

## 8. Language residual scan — visual pass, exact remaining strings

Scanned rendered `document.body.innerText` on: sidebar+dashboard, permissions, settings, one table (Positions), one create form (Positions). Proper nouns, URLs, emails, and "URL"/"PLCC" (an already-documented acceptable acronym) excluded per your instruction.

| Screen | Remaining English strings |
|---|---|
| Sidebar + Dashboard | "Super Admin" (stored Role name — a database value, not template chrome; see note) |
| Permissions | "Super Admin" |
| Settings | "Super Admin", "URL" (excluded — technical standard) |
| Positions table | "Super Admin", **"Unit"** (filter label), **"Active"**, **"Inactive"** (status badges) |
| Positions create form | "Super Admin", "Active", "Inactive" (status dropdown options) |

**Two real categories confirmed remaining, both previously flagged, neither touched:**
1. **Status badge/option text** ("Active"/"Inactive"/etc.) — confirmed again live on both the table and the create form's own status dropdown, not just the badge component.
2. **One filter label** ("Unit") on the Positions table toolbar — the "Posting"/"Role" labels flagged last pass weren't on today's scan list (different screens) but were not re-verified as fixed either; treat all three as still open.

"Super Admin" is a literal value stored in the `roles.name` database column (seeded by `RolesAndPermissionsSeeder`), not a hardcoded UI string — translating it would mean renaming actual role data, a different kind of change than the Blade/controller string work done so far. Flagged for your decision on whether that's in scope, not silently excluded or silently fixed.

## 9. Dark mode — real toggle sequence, attribute states

Sequence run through the actual `data-theme-choice` dropdown control (not a localStorage shortcut):

| Stage | Screenshot | `data-bs-theme` | `data-menu-color` | `data-topbar-color` | localStorage |
|---|---|---|---|---|---|
| 1. Load (Light) | `admin/K-dashboard-1440-light.png` | light | light | light | (unset) |
| 2. Click toggle → Dark | `admin/L-dashboard-1440-dark.png` | dark | dark | dark | dark |
| 3. Navigate (→ Positions) | `admin/toggle-stage3-navigate-dark.png` | dark | dark | dark | dark |
| 4. Reload | `admin/toggle-stage4-reload-dark.png` | dark | dark | dark | dark |

All three theme attributes plus localStorage agreed at every stage — no desync reproduced in this run. (Toggling back to Light was exercised in this session to reset state for the remaining light-mode screenshots but not re-captured as a numbered stage here, since Light→Dark→navigate→reload→"still Dark" is exactly what you asked to verify.)

---

## 10. What was explicitly NOT done this pass

No design decisions, no copy changes, no CSS changes. The governance audit-note sentence, the newly-found Latin-numeral counts in governance copy, the login redesign depth, admin status-badge language, the nav scrim's feel, and the reorder-target spacing are all reported as measurements/flags for your decision, not resolved.
