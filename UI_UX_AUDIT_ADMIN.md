# UI/UX Audit — admin.provatferi.org (Laravel ERP / Admin Portal)

Audit only. No production changes were made. Findings are based on live rendered screenshots against https://admin.provatferi.org, captured via real browser automation with a genuinely logged-in session (a temporary Super Admin password was generated for this audit, used only in-memory by the automation script, and both the credential file and the automation scripts were deleted from the server immediately after use — never displayed in any output). Full issue table: `UI_UX_ISSUES_MASTER.md` (ADM-001 through ADM-017). Screenshots: `design-audit/admin/`.

## 1. Executive assessment

The honest answer to "does this look like a purpose-built institutional ERP or a template with a logo on it" is: **it depends entirely on which screen you're looking at**, and that inconsistency is itself the core finding. The login page (ADM-001) is an unmistakable stock admin-template gradient background — the single clearest "template feel" signal found in this entire audit, across both systems. The dashboard and most list pages are a chaotic, unresolved mix of English chrome and Bengali content (ADM-002). The Permissions screen (ADM-009) is a raw developer debug view presented as a normal admin page. And yet the Activities edit form and the About/Mission content-editing screens are genuinely well-built — thoughtful Bengali microcopy, live preview panels, and explicit anti-fabrication guardrails ("বাস্তব ও অনুমোদিত তথ্য না থাকলে খালি রাখুন") that directly reflect the organization's own values.

This is not a system that is uniformly bad. It's a system where the underlying Laravel/CRUD engineering is competent (forms validate, tables list correctly, the real 3 activities and real org data all render correctly) but the **presentation layer received wildly uneven design attention** — some screens custom-built with care, others left as template/scaffold defaults. For a Super Admin who is also non-technical staff, that unevenness is itself confusing: which parts of this system were "finished," and which are still developer scaffolding they're not meant to fully trust?

## 2. Overall professional-quality score: **4/10**

The login screen and the pervasive language inconsistency (ADM-001, ADM-002) are severe enough on their own to place this below the "usable but rough" band, despite real quality in several individual forms.

## 3. Top 10 highest-impact issues

1. **ADM-001 (P1)** — Login page uses an unmodified stock admin-template gradient background.
2. **ADM-002 (P1, systemic)** — Sidebar, page titles, breadcrumbs, and most table/form chrome are English; content and some labels are Bengali, with no consistent policy.
3. **ADM-009 (P1)** — Permissions screen exposes raw `module.action` keys and a bare role-count number — a developer reference table, not an admin screen.
4. **ADM-008 (P1)** — Site Settings mixes English section headings with transliterated technical jargon ("ক্যানোনিক্যাল ওয়েবসাইট URL") that a non-technical admin cannot be expected to parse.
5. **ADM-011 (P1)** — Dark mode leaves the sidebar on its light background while the content area goes dark — simultaneously half-light, half-dark on one screen.
6. **ADM-013 (P2, cross-system)** — Admin dates ("05 Sep 2026") don't match the public site's Bengali-digit dates for the identical underlying data.
7. **ADM-004 (P2)** — 4 of 6 dashboard stat cards read "0," adding visual noise without value.
8. **ADM-005 (P2)** — The "Filter" button renders in two different colors (purple vs. gray) depending which list page you're on.
9. **ADM-012 (P2)** — Dashboard stat cards fully stack on mobile, producing a ~2900px scroll for six single numbers.
10. **ADM-010 (P2)** — Users table rows vary in height depending on badge count, with no handling for overflow.

## 4. Admin information architecture

The sidebar's actual grouping — Overview / Organisation / Programmes / Content / System / Account — is structurally sound; a new admin could plausibly guess "where do I manage activities" correctly (Programmes → Activities). The problem is not the IA, it's that every group and item label is in English (ADM-002), and the Permissions screen (ADM-009) sits inside System without any indication to a non-technical user that it's a read-only developer reference rather than something they're meant to actively manage.

## 5. Dashboard

Real, substantive issues: redundant bilingual headings where a Bengali heading is immediately followed by its own English translation directly beneath it, repeated identically three times (ADM-003) — this reads as an incomplete translation pass, not a deliberate bilingual design. Four of six stat cards show "0" (ADM-004), and the two "recent applications" panels show large generic empty-state icons that don't carry any Provatferi visual identity. The 3 real, correct activity records do display correctly in the "recent activities" panel — the underlying data pipeline is solid; it's the presentation layer carrying the problems.

## 6. Navigation/sidebar

Structurally reasonable grouping (see IA above). Visually, the sidebar is plain but functional in light mode. Its most serious issue is theme-related: it does not participate in dark mode at all (ADM-011), which is more of a "does this system even have dark mode" defect than a subtle contrast issue.

## 7. Tables

The Activities and Organizational Units tables are genuinely well-built — appropriately dense, real filter/search controls, correct real data, sensible column choices (Title/Type/Start/Status/Actions). The Users table has one concrete row-height inconsistency (ADM-010) where a user with 4 role badges creates a visibly taller row than others. The Permissions "table" (ADM-009) is the real outlier — it's a reference dump, not an operational table, and doesn't belong at the same navigational level as the others without a clear "this is read-only reference" framing.

## 8. Forms

This is where the admin's real quality is most visible and most inconsistent. The Activities edit form and the About content form are strong: clear field grouping, helper text that actively discourages fabricating data, a live preview panel on the About form showing exactly what the public site will render. Against that, Site Settings (ADM-008) is the weakest form in the system — English section headings ("Identity," "SEO / Entity") sitting above Bengali labels that themselves contain untranslated technical jargon, in the one form most likely to be edited by non-technical organizational leadership rather than a developer. The required, always-numeric participant-count field (ADM-007) is a small but real friction point against the form's own "don't guess" instruction.

## 9. CRUD workflows

List → Create → Edit was verified to actually function for Organizational Units, Activities, Users, Roles, Job Postings, Membership Types, Positions, Committees, and Activity Types — all reachable, all rendering real forms, not stubs. This is a meaningful positive finding: the CRUD engineering itself is broadly complete across the modules audited, which means the presentation-layer fixes recommended here can be applied without needing new backend work in most cases.

## 10. Empty/error states

Membership Applications and Job Applications both show honest, clearly-labeled "no data yet" states with appropriate icons — functionally fine, though the icons themselves read as generic template stock art rather than anything Provatferi-specific. Not tested in this pass: a real validation-error submission, an unauthorized-access screen, and a 404/500 admin page — flagged under Limitations.

## 11. Light theme

Functionally complete and mostly consistent within itself, aside from the purple/gray Filter button inconsistency (ADM-005) and the pervasive language mixing (ADM-002) that cuts across every screen regardless of theme.

## 12. Dark theme

The content area's dark theme, where it does apply, is reasonably well executed — good contrast, legible badges, orange accent preserved. But the sidebar not switching at all (ADM-011) is a severe, immediately visible defect that undermines any credit the content-area dark theme earns. This needs to be treated as a completeness bug, not a polish item.

## 13. Mobile

The admin was clearly built desktop-first, which is a defensible product decision for an internal tool — but where mobile *is* reachable (an admin checking something from a phone), it currently just narrows the desktop layout rather than adapting it: six dashboard stat cards fully stack (ADM-012), producing a very long scroll for very little information per screen.

## 14. Accessibility

Not a full WCAG audit. Directly observed: the login form's email field shows a clear, on-brand orange focus ring (a genuine positive). Required fields are marked with a red asterisk consistently. Not verified in this pass: keyboard-only navigation through a full CRUD workflow, screen-reader labeling on icon-only buttons (e.g., the row action "⋮" menus), and color-contrast measurement on the purple Filter button and gray secondary buttons against their backgrounds — flagged under Limitations.

## 15. Typography

Bengali form labels and helper text render cleanly wherever they appear — no rendering-quality issues found. The typographic problem in admin is not legibility, it's the mixed-language pattern already covered under ADM-002/ADM-008; fixing that is a content/localization task, not a font or sizing task.

## 16. Component/design-system consistency

The clearest evidence that admin has no enforced design system: the same "Filter" button renders in two different colors on different pages (ADM-005), and a second, brand-unrelated purple/indigo accent appears in admin (Filter button, role badges) with no equivalent anywhere on the public site's design language. Badge/status text (Active, Published, role names) is uniformly English regardless of surrounding Bengali content — another sign these are template/library defaults left unconfigured rather than deliberately styled.

## 17. Zircos/template residue

The login page (ADM-001) is the single clearest piece of evidence: an unmodified diagonal pastel gradient background is a hallmark of stock Bootstrap admin templates generally, and very plausibly the "Zircos" template itself, sitting completely disconnected from the Provatferi brand used one click away on every other authenticated screen. The purple/indigo accent color (ADM-005, CROSS-002) is the second clearest signal — it does not appear anywhere in the Provatferi brand system and most likely is a template default that was never overridden everywhere it appears. The generic "no data" icons on empty states (Dashboard, Membership/Job applications) are a third, smaller instance of the same pattern.

## 18. Severity matrix

| Severity | Count |
|---|---|
| P0 | 0 |
| P1 | 6 |
| P2 | 6 |
| P3 | 2 |
| Positive finding (not a defect) | 1 |
| Excluded — audit limitation / closed, not a defect | 2 (ADM-014, ADM-017) |

Updated per reviewer correction, on top of the §20 addendum: ADM-001 and ADM-002 reclassified P0 → P1 (neither is individually task-blocking, though ADM-002 remains systemic across nearly every screen). ADM-016 upgraded P2 → P1 — re-verification found the aria-label was already present (the original "no accessible name" claim was inaccurate and is corrected in `UI_UX_ISSUES_MASTER.md`), but the touch-target-size defect was real and independently meets the P1 bar. ADM-014 moved out of the defect count entirely — a real Committees record not existing is a data prerequisite, not a UI defect; no fake committee was created to force a screenshot. ADM-017 re-evaluated against actual scale (4 rows, not growing) and closed as not a defect — adding a filter toolbar there would be UI added for symmetry, not a demonstrated need.

**TOTAL_IDENTIFIED_P1 (admin): 6** (ADM-001, ADM-002, ADM-008, ADM-009, ADM-011, ADM-016). **FIXED_P1: 6.** ADM-002's last confirmed concrete gaps — English status badges/options (now driven by one shared `status_label()` map, see `app/helpers.php`) and 4 remaining filter labels (Unit ×2, Posting, Role) — were closed in the final correction pass. This is based on two targeted sweeps (table headers/data-labels/dropdown-actions, then status text/filter-form labels), not an exhaustive re-check of literally every screen; "Super Admin" (a stored `roles.name` value, not template chrome) was deliberately left untranslated as data, not UI text. **OPEN_P1: 0.**

## 20. Addendum — expanded module coverage

The first pass sampled representative screens per module group. This addendum closes the gap against the full named module list: Positions, Committees, Membership Types, Members, Mission, Vision, and Objectives were each captured directly (desktop 1440, mobile 390; three of them also in dark mode) for the first time.

**New findings:** ADM-014 (Committees has zero real records, so Committee Members — a named audit target — has nothing to evaluate; same root pattern as PUB-017), ADM-015 (a third sighting of the unexplained purple accent, on the Membership Types "Student" badge — reinforces ADM-005/CROSS-002, does not change its severity), ADM-016 (the Objectives reorder up/down buttons are small, unlabeled icon-only controls — a genuine accessibility gap on the one screen most dependent on them), ADM-017 (Membership Types is the only list screen audited with no Search/Filter toolbar, inconsistent with every sibling list).

**Two genuine positives worth crediting:** the Mission and Vision content-editing screens match the same high standard already noted for About — real content, a live public-preview panel, a clear last-updated timestamp. And the Positions table reflows into a clean, readable card-per-row layout at 390px (label/value pairs, no horizontal scroll) rather than the desktop table simply narrowing — the strongest example of table responsiveness found in either system, and worth using as the reference pattern when fixing ADM-010/ADM-012.

**A necessary correction to ADM-011's framing:** re-testing dark mode via a direct theme-flag reload (rather than the in-session toggle click used originally) showed the sidebar theming correctly alongside the content area on Positions and Mission — full dark, no light/dark split. ADM-011 itself is not withdrawn; the original screenshot of a half-light sidebar is real evidence. But the two results together point to a more precise root cause than "the sidebar doesn't support dark mode": the defect is most likely in the **toggle interaction's own logic or timing** (it may not set every attribute the pre-paint bootstrap script relies on, or applies them in the wrong order) rather than the sidebar being permanently unable to render dark. This changes the likely fix from "add dark-mode CSS to the sidebar" to "make the toggle handler set the same state the pre-paint script sets on load" — a smaller, more targeted change. Severity stays P1 pending a focused re-test of the exact toggle click path.

## 19. Recommended fix order

1. ADM-001 — replace the login background (P0, highest-visibility template signal)
2. ADM-002 — decide and apply one localization policy across sidebar/chrome (P1, affects every screen — FIXED in the final correction pass: status badges/options now use a shared `status_label()` map, remaining filter labels translated; see `PHASE1_VISUAL_REVIEW.md` §5 for the gaps this closed)
3. ADM-011 — fix sidebar dark-mode theming (P1, binary broken/not-broken)
4. ADM-008 — rewrite Site Settings labels for a non-technical Bengali-speaking reader (P1)
5. ADM-009 — reframe or hide the Permissions screen for non-technical roles (P1)
6. ADM-005 / CROSS-002 — remove the purple accent, standardize on orange (P2, likely a small, high-leverage fix once located)
7. ADM-004 — dashboard zero-value card treatment (P2)
8. Remaining P2/P3 items (table row heights, mobile stat grid, participant-count field, ADM-014/015/016/017) as routine polish
