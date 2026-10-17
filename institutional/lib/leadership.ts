import type { LeadershipDetail, LeadershipSummary } from "./api/types";
import type { SocialLink } from "./contact-resolve";
import { pickText } from "./i18n/pick";
import type { Locale } from "./i18n/types";
import { SOCIAL_PLATFORMS, cleanText, safePhone, safeSocialUrl, safeWebsiteUrl, telHref, usablePublicEmail } from "./public-links";

/**
 * What a Leadership / Key Persons profile looks like on one page of the site (docs/LEADERSHIP_SYSTEM.md): the ERP's
 * bilingual record turned into the single-language values a card or a profile page shows. Pure (no fetch, no
 * server-only import), so every rule below is unit-tested (__tests__/leadership.test.mts).
 *
 * THE LANGUAGE RULE is the site's: Bangla is the base language. On the Bangla page only Bangla is shown. On the English
 * page a field with an English text shows it; a field without one shows the Bangla text and the page carries the
 * "translation not yet available" note (`usesFallback`) — content never disappears and nothing is invented to fill a gap.
 * A field with no Bangla text and no English text is simply not shown.
 *
 * EVERYTHING IS RE-CHECKED. The ERP builds the API from an explicit allow-list and validates every link when it is saved,
 * but this site writes these values into href attributes, so a link is dropped unless it passes lib/public-links.ts again,
 * and an e-mail address that is the internal, automated or security mailbox is never shown. Text stays text: it is
 * rendered by React (or by TextBlocks, which makes paragraphs, bullets and plain http(s) links) and never as HTML.
 */

/** The card-size image's longest side (App\Services\LeadershipPhotoService::CARD_EDGE): its box is derived from the page size. */
const CARD_EDGE = 480;

export interface LeaderPhotoView {
  /** Page size: the profile page. */
  src: string;
  width: number;
  height: number;
  /** Card size: the Leadership page and the homepage. */
  srcSm: string;
  widthSm: number;
  heightSm: number;
}

export interface LeaderView {
  slug: string;
  name: string;
  designation: string;
  shortBio: string | null;
  expertise: string | null;
  featured: boolean;
  photo: LeaderPhotoView | null;
  /** One or two letters shown in place of a missing photo. */
  initials: string;
  social: SocialLink[];
  website: string | null;
  /** English was asked for and at least one text shown here is the Bangla text instead: show the translation note. */
  usesFallback: boolean;
  /** An English page exists for this profile: both an English name and an English designation were entered. */
  hasEnglish: boolean;
}

export interface LeaderDetailView extends LeaderView {
  fullBio: string | null;
  quote: string | null;
  sinceYear: number | null;
  email: string | null;
  phone: string | null;
  phoneHref: string | null;
}

interface Picked {
  text: string;
  isFallback: boolean;
}

const isFilled = (value: string | null | undefined): value is string => typeof value === "string" && value.trim() !== "";

/** A field that may have no text in either language. English falls back to Bangla; Bangla never falls back to English. */
function pickOptional(locale: Locale, bn: string | null, en: string | null, maxLength: number): Picked | null {
  const bnText = isFilled(bn) ? cleanText(bn, maxLength) : null;
  const enText = isFilled(en) ? cleanText(en, maxLength) : null;
  if (locale === "bn") return bnText ? { text: bnText, isFallback: false } : null;
  if (enText) return { text: enText, isFallback: false };
  return bnText ? { text: bnText, isFallback: true } : null;
}

/** Image addresses come from the ERP's own public disk, never from a person; still, only an http(s) address is used. */
export function safeImageUrl(input: unknown): string | null {
  if (typeof input !== "string") return null;
  const value = input.trim();
  return /^https?:\/\/[^\s<>"'\\]+$/i.test(value) ? value : null;
}

/** The first letters of the first two words of a name (the first character of a Bangla word is its first consonant or vowel). */
export function initialsOf(name: string): string {
  const letters = name
    .trim()
    .split(/\s+/)
    .filter((word) => word !== "")
    .slice(0, 2)
    .map((word) => (Array.from(word)[0] ?? "").toLocaleUpperCase());
  return letters.join("");
}

/** An English page exists only when the English name and designation both do. */
export function hasEnglishPage(profile: Pick<LeadershipSummary, "name_en" | "designation_en">): boolean {
  return isFilled(profile.name_en) && isFilled(profile.designation_en);
}

function resolvePhoto(profile: LeadershipSummary): LeaderPhotoView | null {
  if (profile.photo === null) return null;
  const src = safeImageUrl(profile.photo.url);
  const srcSm = safeImageUrl(profile.photo.sm_url) ?? src;
  if (src === null || srcSm === null) return null;

  const width = Math.max(1, Math.round(profile.photo.width));
  const height = Math.max(1, Math.round(profile.photo.height));
  const scale = Math.min(1, CARD_EDGE / Math.max(width, height));

  return { src, width, height, srcSm, widthSm: Math.max(1, Math.round(width * scale)), heightSm: Math.max(1, Math.round(height * scale)) };
}

function resolveSocial(profile: LeadershipSummary): SocialLink[] {
  const byPlatform = new Map<SocialLink["platform"], string>();
  for (const entry of profile.social) {
    const platform = SOCIAL_PLATFORMS.find((p) => p === entry.platform);
    const url = platform ? safeSocialUrl(platform, entry.url) : null;
    if (platform && url && !byPlatform.has(platform)) byPlatform.set(platform, url);
  }
  return SOCIAL_PLATFORMS.flatMap((platform) => {
    const url = byPlatform.get(platform);
    return url ? [{ platform, url }] : [];
  });
}

export function resolveLeader(profile: LeadershipSummary, locale: Locale): LeaderView {
  const name = pickText(locale, profile.name_bn, profile.name_en);
  const designation = pickText(locale, profile.designation_bn, profile.designation_en);
  const shortBio = pickOptional(locale, profile.short_bio_bn, profile.short_bio_en, 600);
  const expertise = pickOptional(locale, profile.expertise_bn, profile.expertise_en, 160);

  return {
    slug: profile.slug,
    name: name.text,
    designation: designation.text,
    shortBio: shortBio?.text ?? null,
    expertise: expertise?.text ?? null,
    featured: profile.featured,
    photo: resolvePhoto(profile),
    initials: initialsOf(name.text),
    social: resolveSocial(profile),
    website: safeWebsiteUrl(profile.website_url),
    // What a CARD shows: name, designation and the short biography. The profile page adds its own (resolveLeaderDetail).
    usesFallback: name.isFallback || designation.isFallback || (shortBio?.isFallback ?? false),
    hasEnglish: hasEnglishPage(profile),
  };
}

export function resolveLeaderDetail(profile: LeadershipDetail, locale: Locale): LeaderDetailView {
  const base = resolveLeader(profile, locale);
  const expertise = pickOptional(locale, profile.expertise_bn, profile.expertise_en, 160);
  const fullBio = pickOptional(locale, profile.full_bio_bn, profile.full_bio_en, 10000);
  const quote = pickOptional(locale, profile.quote_bn, profile.quote_en, 500);
  const phone = safePhone(profile.public_phone);
  const year = profile.associated_since_year;

  return {
    ...base,
    fullBio: fullBio?.text ?? null,
    quote: quote?.text ?? null,
    sinceYear: typeof year === "number" && Number.isInteger(year) && year >= 1900 && year <= 2200 ? year : null,
    email: usablePublicEmail(profile.public_email),
    phone,
    phoneHref: phone ? telHref(phone) : null,
    // The profile page shows more fields than a card, so any of them falling back counts.
    usesFallback: base.usesFallback || (expertise?.isFallback ?? false) || (fullBio?.isFallback ?? false) || (quote?.isFallback ?? false),
  };
}

/**
 * The meta description of a profile page: the short biography when there is one (cut at a word boundary), otherwise the
 * designation and the organisation — both stored facts. Nothing is ever claimed that the profile does not say.
 */
export function leaderDescription(view: Pick<LeaderView, "shortBio" | "designation">, organisation: string, maxLength = 160): string {
  const source = view.shortBio ?? `${view.designation} — ${organisation}`;
  const oneLine = source.replace(/\s+/g, " ").trim();
  if (oneLine.length <= maxLength) return oneLine;

  const cut = oneLine.slice(0, maxLength - 1);
  const lastSpace = cut.lastIndexOf(" ");
  return `${(lastSpace > maxLength * 0.6 ? cut.slice(0, lastSpace) : cut).replace(/[\s,;:.–—-]+$/, "")}…`;
}

/** The profiles a section shows: featured first is NOT done here — the ERP's order is the order; this only splits. */
export function splitFeatured<T extends { featured: boolean }>(profiles: T[]): { featured: T[]; others: T[] } {
  return { featured: profiles.filter((p) => p.featured), others: profiles.filter((p) => !p.featured) };
}
