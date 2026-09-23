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
  description: string | null;
  history: string | null;
  why_exists: string | null;
  identity_explanation: string | null;
  registration_status: string | null;
}

export interface ContentBlock {
  body: string;
  updated_at: string | null;
}

export interface Objective {
  id: number;
  title: string | null;
  body: string;
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
  slug: string;
  description: string | null;
  duration_months: number | null;
  /** Decimal from Laravel, serialized as a string, e.g. "0.00". */
  fee: string;
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
  membership_types: MembershipType[];
}

// ---------------------------------------------------------------------------
// GET /api/v1/public/committees, /public/committees/{slug|id}
// ---------------------------------------------------------------------------

export interface PublicCommitteeSummary {
  id: number;
  slug: string;
  name: string;
  committee_type: string | null;
  term_start: string | null;
  term_end: string | null;
  status: string;
  description: string | null;
}

export interface PublicCommitteesIndexResponse {
  current: PublicCommitteeSummary | null;
  upcoming: PublicCommitteeSummary[];
  previous: PublicCommitteeSummary[];
}

export interface PublicCommitteeMember {
  name: string;
  position: string;
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
  slug: string;
  summary: string | null;
  department: string | null;
  description: string | null;
  requirements: string | null;
  organization_unit: string | null;
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
  notice_action: { url: string; label: string } | null;
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
  /** Internal key — used only for filter URLs and styling, never shown. */
  notice_type: string;
  notice_type_label: string;
  summary: string | null;
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
  organization_unit: string | null;
  action: { url: string; label: string } | null;
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
  slug: string;
  summary: string | null;
  description: string | null;
  objective: string | null;
  venue: string | null;
  address: string | null;
  hero_image_path: string | null;
  what_happened: string | null;
  outcomes: string | null;
  gallery: string[];
  related_links: string[];
  facebook_post_url: string | null;
  start_datetime: string | null;
  end_datetime: string | null;
  featured: boolean;
  participant_count: number | null;
  published_at: string | null;
  type: { id: number; name: string; slug: string } | null;
  // Snake_case on the wire, not organizationUnit — Eloquent's default
  // relationsToArray() runs Str::snake() on the relation name regardless of
  // the camelCase method name used in the controller's with(...). Confirmed
  // against the live response, not assumed; a first draft of this type had
  // this wrong, which would have made isActivity() reject every real row.
  organization_unit: { id: number; name: string; slug: string } | null;
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
}

export interface MemberSeasonHistoryEntry {
  season: string | null;
  joined_at: string | null;
}

export interface MemberPaymentSummary {
  amount_received: string;
  method: string | null;
  status: string;
  received_at: string | null;
}

export interface MemberDashboard {
  profile: MemberProfile;
  memberships: MemberMembershipSummary[];
  season_history: MemberSeasonHistoryEntry[];
  payments: MemberPaymentSummary[];
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
