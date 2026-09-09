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
}

// ---------------------------------------------------------------------------
// GET /api/v1/job-postings, /job-postings/{id|slug}
// ---------------------------------------------------------------------------

export interface JobPosting {
  id: number;
  title: string;
  slug: string;
  summary: string | null;
  department: string | null;
  description: string | null;
  requirements: string | null;
  employment_type: string | null;
  salary_range: string | null;
  opening_date: string | null;
  application_deadline: string | null;
  published_at: string | null;
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
