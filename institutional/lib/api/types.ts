/**
 * Wire types for admin.provatferi.org's public /api/v1/* endpoints.
 *
 * These mirror the exact response shapes returned by the Laravel controllers
 * in admin-erp/app/Http/Controllers/Api/V1/*.php as of 2026-09-09 — not an
 * idealized contract. If a controller's field list changes, this file and
 * the one that reads it both need updating together.
 */

// ---------------------------------------------------------------------------
// Shared result type — every api/*.ts getter returns one of these, never
// throws, and never resolves to `undefined`. Callers branch on `ok` and fall
// back to lib/content.ts on the `false` side.
// ---------------------------------------------------------------------------

export type ApiErrorReason =
  | "timeout"
  | "network_error"
  | "http_error"
  | "invalid_json"
  | "invalid_shape"
  | "not_configured";

export type ApiResult<T> = { ok: true; data: T } | { ok: false; error: ApiErrorReason };

// ---------------------------------------------------------------------------
// GET /api/v1/settings
// ---------------------------------------------------------------------------

export interface SettingsResponse {
  organization: {
    name_bn: string | null;
    name_en: string | null;
    short_name: string | null;
    acronym: string | null;
    tagline: string | null;
  };
  contact: {
    email: string | null;
    phone: string | null;
    address: string | null;
    facebook_url: string | null;
  };
  seo: {
    title: string | null;
    description: string | null;
    alternate_names: string[];
    canonical_url: string | null;
  };
  links: {
    website_url: string | null;
    literature_url: string | null;
  };
}

// ---------------------------------------------------------------------------
// GET /api/v1/about
// ---------------------------------------------------------------------------

export interface AboutSection {
  introduction: string | null;
  introduction_en: string | null;
  description: string | null;
  description_en: string | null;
  history: string | null;
  history_en: string | null;
  why_exists: string | null;
  why_exists_en: string | null;
  identity_explanation: string | null;
  identity_explanation_en: string | null;
  registration_status: string | null;
}

export interface ContentBlock {
  body: string;
  body_en: string | null;
  updated_at: string | null;
}

export interface Objective {
  id: number;
  title: string | null;
  title_en: string | null;
  body: string;
  body_en: string | null;
  sort_order: number;
}

export interface AboutResponse {
  about: AboutSection | null;
  mission: ContentBlock | null;
  vision: ContentBlock | null;
  objectives: Objective[];
}

// ---------------------------------------------------------------------------
// GET /api/v1/organization-units, /organization-units/{id|slug}
// ---------------------------------------------------------------------------

export interface OrganizationUnit {
  id: number;
  parent_id: number | null;
  name: string;
  name_en: string | null;
  slug: string;
  unit_type: string;
  address: string | null;
  phone: string | null;
  email: string | null;
}

// ---------------------------------------------------------------------------
// GET /api/v1/membership-types
// ---------------------------------------------------------------------------

export interface MembershipType {
  id: number;
  name: string;
  name_en: string | null;
  slug: string;
  description: string | null;
  description_en: string | null;
  duration_months: number | null;
  /**
   * DEPRECATED alias of `registration_fee`, still sent so a build of this site from before fee policies existed keeps
   * rendering. Decimal from Laravel, serialized as a string, e.g. "0.00". Read `registration_fee` (see lib/fees.ts).
   */
  fee: string;
  /**
   * The fee policy IN FORCE TODAY (admin-erp's MembershipFeePolicyService), as decimal strings ("500.00") — never floats.
   * Optional only because a response cached from before the change lacks them; a live response always carries all three.
   */
  registration_fee?: string;
  monthly_contribution?: string;
  /** The day ('Y-m-d') the quoted policy took effect. */
  fee_effective_from?: string;
  /** Short, permanent type identifier (LM, GM, ST …); null for a legacy type that has none. */
  code?: string | null;
  is_student: boolean;
  is_public_self_apply: boolean;
}

// ---------------------------------------------------------------------------
// GET /api/v1/public/membership/campaigns/current
//
// Always an array — a regular season and a special one-off drive can
// legitimately be open at the same time (admin-erp's own §2-4 design), so
// this is never collapsed to a single nullable campaign.
// ---------------------------------------------------------------------------

/**
 * A membership type as the campaigns endpoint nests it. That endpoint returns ONLY the types a visitor can apply for
 * themselves (status active, public self-apply), so it does not repeat `is_public_self_apply` — the flag belongs to the
 * membership-types list above. Requiring it here made every open season fail the shape check, and the application form
 * never rendered.
 */
export type CampaignMembershipType = Omit<MembershipType, "is_public_self_apply"> & { is_public_self_apply?: boolean };

export interface MembershipCampaign {
  id: number;
  name: string;
  name_en: string | null;
  slug: string;
  campaign_type: "regular" | "special";
  opens_at: string | null;
  closes_at: string | null;
  description: string | null;
  cash_payment_instructions: string | null;
  public_profile_opt_in: boolean;
  membership_types: CampaignMembershipType[];
}

// ---------------------------------------------------------------------------
// GET /api/v1/public/committees, /public/committees/{slug|id}
// ---------------------------------------------------------------------------

export interface PublicCommitteeSummary {
  id: number;
  slug: string;
  name: string;
  name_en: string | null;
  committee_type: string | null;
  term_start: string | null;
  term_end: string | null;
  status: string;
  description: string | null;
  description_en: string | null;
}

export interface PublicCommitteesIndexResponse {
  current: PublicCommitteeSummary | null;
  upcoming: PublicCommitteeSummary[];
  previous: PublicCommitteeSummary[];
}

export interface PublicCommitteeMember {
  name: string;
  name_en: string | null;
  position: string;
  position_en: string | null;
  serial_no: number | null;
  photo_url: string | null;
  bio: string | null;
  facebook_url: string | null;
  linkedin_url: string | null;
  website_url: string | null;
}

export interface PublicCommitteeDetail extends PublicCommitteeSummary {
  members: PublicCommitteeMember[];
}

// ---------------------------------------------------------------------------
// GET /api/v1/public/committees/registration-links/{token}
// GET /api/v1/public/committee-submissions/correction/{token}
// ---------------------------------------------------------------------------

export interface CommitteePositionOption {
  id: number;
  name: string;
  /** Only present on the registration-link payload (§23 warn-not-block). */
  occupied?: boolean;
}

export interface CommitteeRegistrationLinkInfo {
  committee: { id: number; name: string; slug: string };
  positions: CommitteePositionOption[];
}

export interface CommitteeCorrectionInfo {
  committee: { id: number; name: string };
  admin_note: string | null;
  full_name: string;
  name_en: string | null;
  email: string;
  phone: string;
  bio: string | null;
  provatferi_comment: string;
  facebook_url: string | null;
  linkedin_url: string | null;
  website_url: string | null;
  committee_position_id: number;
  positions: CommitteePositionOption[];
}

// ---------------------------------------------------------------------------
// GET /api/v1/job-postings, /job-postings/{id|slug}
// ---------------------------------------------------------------------------

/**
 * 2026-09-15: labels come from the ERP's own maps, dates are plain Y-m-d.
 * A volunteer role never carries a salary and a rolling call never carries
 * a deadline — both are null by contract, not by accident.
 */
export interface SkillOption {
  key: string;
  label: string;
}

export interface JobPosting {
  id: number;
  title: string;
  title_en: string | null;
  slug: string;
  summary: string | null;
  summary_en: string | null;
  department: string | null;
  description: string | null;
  description_en: string | null;
  requirements: string | null;
  requirements_en: string | null;
  organization_unit: string | null;
  organization_unit_en: string | null;
  employment_type: string | null;
  employment_type_label: string | null;
  is_volunteer: boolean;
  volunteer_note: string | null;
  salary_range: string | null;
  application_mode: string;
  application_mode_label: string;
  opening_date: string | null;
  application_deadline: string | null;
  published_at: string | null;
  notice_slug: string | null;
  /** The ERP decides whether the website form is open for this posting. */
  accepts_applications: boolean;
  /** Derived from the posting's own slug in the ERP — never composed here. */
  apply_path: string | null;
  /** The linked notice's own CTA (the WhatsApp community group): a SECONDARY channel, never the way to apply. */
  notice_action: { url: string; label: string; label_en: string | null } | null;
  /** Only present on the detail contract, and only while the form is open. */
  skill_options: SkillOption[] | null;
  /**
   * Per-field Required/Optional for this posting's application form —
   * presentation only (which labels/asterisks to show). Laravel's own
   * validation, built from the same source posting, is the real authority;
   * this is never used to decide whether to SEND a field, only how to LABEL
   * it. Same lifetime as skill_options: detail contract, form open only.
   */
  field_requirements: Record<string, "required" | "optional"> | null;
  /** §12/§13: this posting's own image, or its linked notice's — never composed client-side. */
  share_image_url: string | null;
}

// ---------------------------------------------------------------------------
// GET /api/v1/public/notices, /notices/{slug}, /notices/sitemap
// ---------------------------------------------------------------------------

export interface PublicNoticeSummary {
  slug: string;
  title: string;
  title_en: string | null;
  /** Internal key — used only for filter URLs and styling, never shown. */
  notice_type: string;
  notice_type_label: string;
  summary: string | null;
  summary_en: string | null;
  published_at: string;
  expires_at: string | null;
  is_pinned: boolean;
  is_new: boolean;
  is_expired: boolean;
  is_archived: boolean;
}

export interface NoticeTypeFacet {
  key: string;
  label: string;
  count: number;
}

export interface PublicNoticeList {
  data: PublicNoticeSummary[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
  filters: { types: NoticeTypeFacet[]; years: number[] };
}

export interface NoticeRecruitmentInfo {
  /** Only set while the posting is open — the recruitment page resolves open postings only. */
  slug: string | null;
  title: string;
  title_en: string | null;
  employment_type_label: string | null;
  is_volunteer: boolean;
  volunteer_note: string | null;
  salary_range: string | null;
  application_mode: string;
  application_mode_label: string;
  opening_date: string | null;
  application_deadline: string | null;
  is_open: boolean;
  /** Whether the ERP has the website application form open for this posting. */
  accepts_applications: boolean;
  /** Derived in the ERP from the posting's slug — never composed in the front end. */
  apply_path: string | null;
}

export interface PublicNoticeDetail extends PublicNoticeSummary {
  body: string;
  body_en: string | null;
  organization_unit: string | null;
  organization_unit_en: string | null;
  action: { url: string; label: string; label_en: string | null } | null;
  cover_image_url: string | null;
  /** §12: the dedicated Open Graph image, when one was uploaded — never the same as cover_image_url. */
  share_image_url: string | null;
  attachment: { url: string; size: number | null; mime: string | null } | null;
  recruitment: NoticeRecruitmentInfo | null;
  updated_at: string | null;
}

export interface NoticeSitemapEntry {
  slug: string;
  updated_at: string | null;
}

// ---------------------------------------------------------------------------
// GET /api/v1/activities, /activities/{id|slug}
//
// Built for completeness (all seven files this layer is meant to have), but
// NOT wired into any page yet — see lib/api/activities.ts for why.
// ---------------------------------------------------------------------------

export interface Activity {
  id: number;
  activity_type_id: number | null;
  organization_unit_id: number | null;
  title: string;
  title_en: string | null;
  slug: string;
  summary: string | null;
  summary_en: string | null;
  description: string | null;
  description_en: string | null;
  objective: string | null;
  objective_en: string | null;
  venue: string | null;
  address: string | null;
  hero_image_path: string | null;
  what_happened: string | null;
  what_happened_en: string | null;
  outcomes: string | null;
  outcomes_en: string | null;
  gallery: string[];
  related_links: string[];
  facebook_post_url: string | null;
  start_datetime: string | null;
  end_datetime: string | null;
  featured: boolean;
  participant_count: number | null;
  published_at: string | null;
  type: { id: number; name: string; name_en: string | null; slug: string } | null;
  // Snake_case on the wire, not organizationUnit — Eloquent's default
  // relationsToArray() runs Str::snake() on the relation name regardless of
  // the camelCase method name used in the controller's with(...). Confirmed
  // against the live response, not assumed; a first draft of this type had
  // this wrong, which would have made isActivity() reject every real row.
  organization_unit: { id: number; name: string; name_en: string | null; slug: string } | null;
}

export interface ActivityListResponse {
  data: Activity[];
  total: number;
  current_page: number;
  last_page: number;
}

// ---------------------------------------------------------------------------
// Member portal (§11-12) — POST /api/v1/member/auth/login, GET /member/me
// ---------------------------------------------------------------------------

export interface MemberProfile {
  member_code: string | null;
  name: string;
  email: string;
  phone: string | null;
  status: string;
  public_profile_enabled: boolean;
  public_profile_approved: boolean;
  public_slug: string | null;
}

export interface MemberMembershipSummary {
  member_code: string;
  status: string;
  start_date: string | null;
  expiry_date: string | null;
  membership_type: string | null;
  /** The registration fee the membership's application was quoted (decimal string). Absent from older ERP builds. */
  registration_fee?: string | null;
  /**
   * Where that fee stands (admin-erp App\Support\MembershipPaymentState): not_required (a zero fee — nothing is owed),
   * paid, waived, awaiting_verification, unpaid or no_quote. Absent from older ERP builds.
   */
  payment_state?: string;
  /** The monthly contribution ledger (admin-erp Membership task 4). Absent from older ERP builds. */
  monthly?: MemberMonthlyContribution;
}

/** One month owed. Amounts are decimal strings ("200.00"); `period` is "2026-10". */
export interface MemberMonthlyDue {
  period: string;
  amount: string;
  paid: string;
  waived: string;
  outstanding: string;
  /** due | partially_paid | paid | waived | overdue (a past month still owed) */
  state: string;
}

export interface MemberMonthlyContribution {
  current_period: string;
  /** The monthly contribution charged for the current month; null when no fee policy is in force. */
  current_amount: string | null;
  /** A monthly contribution is charged now (above zero, membership active). False: nothing is required. */
  required: boolean;
  /** This month: paid | partially_paid | due | waived | not_required (no due this month). */
  month_state: string;
  outstanding: string;
  overdue_count: number;
  /** Verified money not yet applied to a month — applied to future months as they are created. */
  credit: string;
  /** The last 12 months owed, newest first. */
  recent: MemberMonthlyDue[];
}

export interface MemberSeasonHistoryEntry {
  season: string | null;
  joined_at: string | null;
}

export interface MemberPaymentSummary {
  /** Null for a waiver (nothing was received). */
  amount_received: string | null;
  method: string | null;
  status: string;
  received_at: string | null;
}

/**
 * One official receipt of the signed-in member (admin-erp Membership task 5). Everything the member should see and
 * nothing else — never who received or verified the payment. The PDF is fetched through the site's own
 * /api/member/receipts/{receipt_no} route, which only ever returns the member's own.
 */
export interface MemberReceiptSummary {
  /** "PLCC-RCT-2026-000001" */
  receipt_no: string;
  /** registration | monthly | advance | voluntary | other — a word the page translates */
  purpose: string;
  amount: string;
  /** Advance credit held when the receipt was issued ("0.00" when none). */
  credit: string;
  /** The calendar day the money was received, "2026-10-08". */
  payment_date: string;
  /** The months a monthly payment or an advance was applied to, "2026-10" each. */
  periods: string[];
}

export interface MemberDashboard {
  profile: MemberProfile;
  memberships: MemberMembershipSummary[];
  season_history: MemberSeasonHistoryEntry[];
  payments: MemberPaymentSummary[];
  /** Absent from ERP builds older than the receipts (2026-10-08). */
  receipts?: MemberReceiptSummary[];
  library: { transactions: unknown[] };
}

// ---------------------------------------------------------------------------
// GET /api/v1/public/members, /public/members/{slug} — §13/§14/§16
// ---------------------------------------------------------------------------

export interface PublicMemberSummary {
  public_slug: string | null;
  name: string;
  profession: string | null;
  photo_url: string | null;
}

export interface PublicMemberDetail extends PublicMemberSummary {
  bio: string | null;
  facebook_url: string | null;
  linkedin_url: string | null;
  website_url: string | null;
}

// ---------------------------------------------------------------------------
// GET/POST /api/v1/member/profile — a member's own view of/edits to §13-14
// ---------------------------------------------------------------------------

export interface MemberProfileVersionView {
  bio: string | null;
  profession: string | null;
  facebook_url: string | null;
  linkedin_url: string | null;
  website_url: string | null;
  photo_url: string | null;
  submitted_at: string | null;
}

export interface MemberProfileState {
  public_profile_enabled: boolean;
  public_profile_approved: boolean;
  public_slug: string | null;
  live: MemberProfileVersionView | null;
  pending: MemberProfileVersionView | null;
}

// ---------------------------------------------------------------------------
// GET /api/v1/public/homepage-carousel — Phase 4
// ---------------------------------------------------------------------------

export interface CarouselSlide {
  id: number;
  image_url: string | null;
  title: string | null;
  title_en: string | null;
  alt_text: string | null;
  alt_text_en: string | null;
  link_url: string | null;
  link_label: string | null;
  link_label_en: string | null;
}
