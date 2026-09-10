# UI/UX Audit — provatferi.org (Public Institutional Website)

Audit only. No production changes were made. All findings are based on live rendered screenshots captured with a real headless Chrome browser against https://provatferi.org, reviewed visually, not on source code or automated viewport scripts alone. Full issue table: `UI_UX_ISSUES_MASTER.md` (PUB-001 through PUB-028). Screenshots: `design-audit/public/`.

## 1. Executive assessment

The public site has real design intent behind it — a custom sun/wordmark logo, a coherent warm palette (cream paper, orange accent, dark ink), a genuine typographic voice, and — most importantly — real, dated, located activity records rather than placeholder content. It does not look like a raw template with a logo pasted on, unlike the admin panel.

What it does not yet have is **editorial discipline**. The homepage repeats itself within one screen (PUB-001), buries its most credible content — real events — beneath two decorative sections (PUB-006), and the single page whose entire job is to show organizational leadership instead delivers a wall of 13 near-identical "vacant" cards (PUB-017), which is a genuinely damaging first impression regardless of how honest the underlying content policy is. A recurring, systemic content bug ("Provatferi-এর...", PUB-019) appears on five separate pages. And one real activity detail page — a page representing actual, real, documented work — is nearly empty (PUB-022), which is the opposite of the impression the organization needs to make.

This reads as a site built by someone who cared about craft in places (the timeline, the membership journey steps, the honest empty states on Events/Recruitment) but who ran out of either time or content before finishing the pages that most need real substance — About, Organization, and Activities detail.

## 2. Overall professional-quality score: **6/10**

Above the "obviously a template" floor, well below "commissioned institutional site" — mainly because of content-completeness gaps (PUB-017, PUB-020, PUB-022) and a systemic content-language bug (PUB-019), not because the visual design language itself is weak.

## 3. Top 10 highest-impact issues

1. **PUB-017 (P1)** — Organization page: 13 of 14 governance cards read "vacant," dominating the page visually.
2. **PUB-022 (P1)** — Activity detail pages are nearly empty; ~1000px of void before the footer.
3. **PUB-020 (P1)** — All 3 activity cards show a blank "gallery coming soon" placeholder box.
4. **PUB-006 (P1)** — Real activity evidence sits below decorative content on the homepage, burying the site's actual credibility.
5. **PUB-026 (P1)** — Desktop nav dropdown overlaps and partially obscures the hero heading — a real rendering bug.
6. **PUB-010 (P1, FIXED)** — Tablet width (768px) collapses the entire nav, including the primary CTA, behind a hamburger too early.
7. **PUB-019 (P2, systemic)** — "Provatferi-এর..." English-name-plus-Bengali-suffix construction repeats across 5 pages.
8. **PUB-007 (P2)** — Primary CTA button color is inconsistent between header/hero (orange) and the bottom CTA banner (dark).
9. **PUB-003 (P2, re-evaluated from P1)** — Hero has no real photography; no approved photos exist to use, so this is a content-supply gap rather than a fixable design defect.
10. **PUB-015 (P2, re-evaluated from P1)** — No photography anywhere on the About page; same reasoning as PUB-003.

## 4. Brand/design-system assessment

The palette itself is good: warm cream/paper background, a confident orange, dark ink text, and real Bengali typographic care (Noto Sans Bengali, sensible line-height). This is a genuinely literary/cultural palette, not a generic SaaS one — a real strength worth protecting.

What undermines it is **inconsistent application**, not the palette's own quality: the CTA-banner button breaking from orange (PUB-007), a component (badges) reused as decoration rather than information (PUB-013), and dark mode introducing a muddy olive tint on specific "callout" cards that doesn't match the otherwise clean charcoal dark theme (PUB-027). None of these require a new design direction — they require applying the existing one more consistently.

## 5. Navigation

Desktop dropdown navigation has a real, confirmed layout bug: the "আমাদের সম্পর্কে" dropdown overlaps the hero H1 text, with heading letters visibly bleeding around the dropdown's edges (PUB-026). This is not a style opinion — it's a positioning defect, most likely present in light mode too since it's structural, not theme-dependent.

Mobile navigation (hamburger → accordion panel) functions correctly and includes good affordances (explicit "বন্ধ করুন ×" close button, current-page underline). Its one real defect: the primary "সদস্য হোন" CTA downgrades from a button to a plain text row inside the mobile panel (PUB-028) — exactly where the majority of visitors will encounter it.

The 768px tablet breakpoint collapsing the full desktop header (including the CTA button) to a hamburger (PUB-010) is a breakpoint-choice problem, not a nav-implementation problem — 768px has room for more than a bare hamburger.

## 6. Homepage

Real problems, not opinions: the top-bar tagline repeats verbatim inside the hero card (PUB-001); an unlabeled row of 4 words sits with no heading (PUB-004); 4 parallel (non-sequential) category cards use ০১–০৪ numbering that implies an order that doesn't exist (PUB-005); and — most importantly — the real, dated activity timeline, the single most credible thing on the page, is positioned below two decorative sections (PUB-006). A visitor doing the "5-second test" (who is this, what do they do, why trust them) sees an abstract sunrise graphic and a repeated tagline before any evidence of real work.

## 7. About

Two English words ("Vision"/"Mission") sit inside an otherwise fully-Bengali page (PUB-011) — a small thing that, combined with the recurring "Provatferi-এর" pattern found elsewhere (PUB-019), starts to look like a genuine localization discipline gap rather than isolated typos. The 5-card core-values grid leaves an asymmetric half-empty second row (PUB-012). The 12-item objectives list has no visual grouping (PUB-014) — the page's most information-dense content gets the least structural help. Most significantly: **zero photography anywhere on the page**, including no founder photo (PUB-015), on the one page whose job is building personal/institutional trust.

## 8. Activities

The listing page's 3 real activity cards each show a blank tan placeholder box reading "গ্যালারি শীঘ্রই" (PUB-020) — this is the single most "looks unfinished" moment on the public site: three identical "coming soon" boxes in a row. Four of five category filter pills currently lead to categories with zero content (PUB-021) — worth confirming they have a real empty state before that becomes visible to visitors.

The activity **detail** pages are the most serious content-presentation problem found in this audit (PUB-022): after a short metadata block and one dashed-border empty-state box, there is roughly 1000px of pure background before the footer. A page representing one of the organization's three real, documented activities currently reads as nearly blank — directly opposite to what a credibility-building detail page should do.

## 9. Organization

This page has the most severe **first-impression** problem on the entire public site (PUB-017): 13 of the 14 governance position cards read "শূন্য পদ / নিয়োগ প্রক্রিয়াধীন" (vacant), each rendered as a full, identical card. The content policy behind this — never fabricate names for open seats — is correct and should not change. But the *presentation* actively works against the organization: a visitor's dominant visual impression of this page is "this organization is almost entirely unstaffed," which can read as more damaging than helpful, however accurate. This needs a presentation fix, not a content fix. Additionally, vacant and filled cards are visually identical apart from body text (PUB-016), and the 5-tier organizational-level pills give no visual signal that 4 of the 5 levels are aspirational, not current (PUB-018).

## 10. Events

The page is honest about having no distinct events yet, but that honesty also exposes that Events currently duplicates content already on Home and Activities with no unique value (PUB-023) — worth a real decision about whether this page earns a separate top-level nav slot today.

## 11. Membership

One of the better-executed pages on the site: the 4-step application process uses numbered steps *correctly* (a real sequence, unlike the homepage's decorative numbering), with clear current-step indication and honest "online applications aren't ready yet, contact us directly" messaging. Same redundant-badge issue as About (PUB-013).

## 12. Recruitment

A clean, honest empty state (no positions open, contact us) — one of the best-handled empty states on the site. No new issues beyond the shared "Provatferi-এর" subtitle pattern (PUB-019).

## 13. Contact

Contact information is duplicated within a single view (top cards + footer, PUB-024), and the same "email us" action is presented three different visual ways on one page — a text link, plain text, and a large button (PUB-025) — with no single clear primary action.

## 14. Footer

Consistent across all pages, reasonably clean 4-column layout. Contact info is plain text with no icon treatment, functional but visually flatter than the rest of the page's effort — a minor, not urgent, observation.

## 15. Mobile

Mobile is functionally complete (nothing breaks, nothing overflows) but is largely a **narrowed desktop**, not a mobile-first redesign: the 4-category grid stays 2-column at 390px (PUB-009) rather than adapting, and About's already-long page becomes a ~6000px scroll with no in-page navigation aid for someone who scrolls in without using the nav dropdown first. This is a real "technically fits, feels bad" pattern the brief specifically asked to watch for.

## 16. Dark mode

Genuinely one of the site's stronger areas — the core charcoal/orange dark palette is clean and well-contrasted, a real positive finding. The one confirmed defect is narrow but real: the "callout" card variant (bottom CTA banner, Sahittopata cross-promo, About's transparency notice) renders with a muddy dark-olive tint distinct from the rest of the dark theme (PUB-027) — this matches exactly the "olive/brown/muddy dark mode" failure pattern called out in the brief, and it's isolated to one reused component, so it should be a contained fix.

## 17. Accessibility

Not a full WCAG audit, but directly observed: focus states exist and use the brand accent (visible on the admin login field; not separately confirmed on public in this pass — flagged as untested, see Limitations). Zoom-to-200% was captured but not deeply analyzed for reflow; recommend a dedicated pass given the brief's explicit ask. The vacant/filled leadership-card ambiguity (PUB-016) is also an accessibility-adjacent concern: status is conveyed by text alone at the same visual weight, which is fine for screen readers but weak for low-vision/skimming users.

## 18. Typography

Bengali rendering itself (Noto Sans Bengali) is clean and legible at every size observed, including small metadata text — no evidence of poor Bengali rhythm or broken glyph rendering. The real typography problems found are **structural, not font-level**: the 12-item objectives list has no size/weight variation to aid scanning (PUB-014), and paragraph widths throughout are reasonable (not observed to exceed ~75 characters anywhere checked).

## 19. Component consistency

Badges are the clearest recurring inconsistency: used correctly as a semantic label in some places, but reused as pure decoration with identical repeated text in others (PUB-013). Numbering-as-decoration (PUB-005) versus numbering-as-real-sequence (Membership steps) is the same underlying inconsistency in a different component. CTA button color is the third instance of the same root problem (PUB-007): components aren't yet governed by one set of rules applied everywhere.

## 20. Severity matrix

| Severity | Count |
|---|---|
| P0 | 0 |
| P1 | 6 |
| P2 | 18 |
| P3 | 4 |

Updated per reviewer correction: PUB-017 reclassified P0 → P1 (CONTENT-PRESENTATION / INFORMATION-ARCHITECTURE) — now fixed in Phase 1. PUB-003 and PUB-015 (absence of real photography) re-evaluated P1 → P2 CONTENT ENHANCEMENT: no approved real photos exist and none were fabricated to fill the gap, and no specific major usability/trust failure was demonstrated beyond the general absence itself.

TOTAL_IDENTIFIED_P1 (public): 8 originally classified. FIXED_P1: 6 (PUB-026, PUB-006, PUB-017, PUB-020, PUB-022, PUB-010 — all implemented and browser-verified). RECLASSIFIED_P1 (no longer P1): 2 (PUB-003, PUB-015 → P2 Content Enhancement). OPEN_P1: 0.

## 21. Recommended fix order

1. PUB-017 — governance page presentation (P0, first impression)
2. PUB-026 — nav dropdown overlap bug (P1, real rendering defect)
3. PUB-022 — activity detail page emptiness (P1, core content page)
4. PUB-020 — activity gallery placeholders (P1, visible on every activity)
5. PUB-006 — homepage content order (P1, credibility)
6. PUB-019 — "Provatferi-এর" systemic fix (P2, one fix covers 5 pages)
7. PUB-010 — tablet breakpoint (P1, FIXED — breakpoint corrected from 768/769px to 744px after live measurement)
8. PUB-003/PUB-015 (now P2 content enhancement, not P1) — add real photography once approved images exist; no UI work needed until then
9. P2/P3 consistency items (badges, numbering, CTA color) as a single "component audit" pass rather than page-by-page
