# UI/UX Audit — Cross-System Consistency

provatferi.org and admin.provatferi.org are different products for different audiences — this document is not asking them to look identical. It's asking whether a person who uses both would recognize them as the same institution, and where the intentional differences are actually intentional versus accidental.

Full evidence: `UI_UX_ISSUES_MASTER.md` (CROSS-001 through CROSS-004, plus ADM-013 which is cross-system in nature).

## Shared brand inconsistencies

**Accent color (CROSS-002).** Public uses exactly one accent — the Provatferi orange — applied consistently to every primary action. Admin uses orange for primary buttons but also uses an unrelated purple/indigo for the "Filter" control and role badges. This second color has no source anywhere in the public brand system; it reads as a template default that was never replaced. This is the single most fixable, highest-leverage cross-system finding — removing one color from a handful of components would meaningfully close the gap between the two products.

**Overall visual language (CROSS-001).** Public has a warm, considered palette (cream/paper, orange, dark ink) and a real typographic voice. Admin's login page, in particular, has none of this — a generic pastel gradient with no relationship to Provatferi at all. A user moving from the public site to the admin login would have no visual cue they're still within the same institution's software.

**Dark mode maturity (CROSS-003).** Public's dark mode is largely solid (one contained defect, PUB-027's muddy callout cards). Admin's dark mode has a structural failure — the sidebar doesn't theme at all (ADM-011) — that is more severe than anything found in public dark mode. The two systems are not just visually different in dark mode, they're at different levels of *completeness*.

## Typography inconsistencies

Both systems use Noto Sans Bengali and render it cleanly — this is a genuine, working shared foundation, not a problem. The inconsistency is not in the font itself but in **what language governs structure**: public is Bengali-first with occasional English leaks (PUB-011, PUB-019); admin is largely English-first for chrome with Bengali content and labels layered on top (ADM-002). These are close to opposite localization strategies operating side by side, which is more jarring than either strategy applied consistently would be on its own.

## Color inconsistencies

Beyond the purple accent (above): admin's status/role badges (Active, Published, Super Admin, Member) are English text on colored pills; public has no direct equivalent to compare against, but the badge *component pattern itself* — a small pill label — is shared. Where public uses badges, it tends to repeat identical text across every card in a group (PUB-013); where admin uses them, at least the badge text varies meaningfully (each role, each status) — admin's badge *usage* is actually more correct than public's here, worth noting as something public could learn from admin rather than the reverse.

## Icon inconsistencies

Public's icons (category cards, contact info) are simple, custom-feeling line icons consistent with its overall custom design work. Admin's icons — sidebar nav icons, dashboard stat-card icons, empty-state icons — read as generic icon-library defaults (consistent with the broader "template residue" finding in the admin report). Neither system was found to mix multiple *conflicting* icon styles internally — the inconsistency is between systems, not within either one.

## Component philosophy differences

Public is a marketing/content site: cards, timelines, and callouts built around storytelling. Admin is operational: tables, forms, and CRUD workflows. This difference is **appropriate and should not be forced to match** — a data table has no equivalent in public's design language, and public's decorative hero cards have no place in admin. The audit found no case where public's aesthetic was wrongly forced onto admin or vice versa; the component *vocabularies* are correctly different for correctly different jobs.

## What SHOULD intentionally differ

- **Density.** Admin's tables and forms are appropriately denser than public's spacious marketing layout — this is correct for an operational tool used repeatedly by trained staff, versus a public page read once by a visitor.
- **Motion and decoration.** Public's hero graphics, callout cards, and timeline styling have no reason to exist in admin, and shouldn't be added there for "consistency's" sake.
- **Content language balance.** It's reasonable for admin to lean more bilingual/technical in specific reference screens (e.g., Permissions) than the public site ever would — the problem found here (ADM-009) is not that a technical reference screen exists, but that it isn't clearly *framed* as one, and that the technical language leaks into screens ordinary staff use daily (Settings, dashboard chrome) where it shouldn't.

## What MUST remain consistent

- **The accent color.** One orange, used the same way, everywhere a primary action appears. This is the fastest, most visible fix available across both systems (CROSS-002).
- **The logo and its light/dark handling.** Both systems already share the same logo asset and light/dark icon-swap approach — this is correctly consistent today and should stay that way as both systems evolve.
- **Date and number formatting for the same underlying facts.** The same real activity date currently renders two different ways depending on which system displays it (ADM-013 / CROSS-004). Whichever convention is chosen, it should be a deliberate decision recorded somewhere, not an accident of which developer built which screen.
- **The basic promise that both are "official Provatferi software."** Right now, admin's login screen and several of its screens actively work against that promise (CROSS-001); public consistently upholds it. Closing that gap doesn't require admin to become as decorative as public — it requires admin to stop looking like an un-configured third-party template in the specific places it currently does (login background, purple accent, raw English chrome).
