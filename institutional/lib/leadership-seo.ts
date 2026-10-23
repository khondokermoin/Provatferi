import type { LeadershipDetail } from "./api/types";
import { org } from "./content";
import type { Locale } from "./i18n/types";
import type { LeaderDetailView } from "./leadership";
import { localeRootUrl } from "./leadership-share";
import { cleanText } from "./public-links";

/**
 * Structured data of a Leadership profile page (2026-10-23): a schema.org Person and a BreadcrumbList, built from what the
 * profile really stores and from nothing else (docs/LEADERSHIP_SYSTEM.md, "Share and search results"). Pure functions, so
 * every rule is unit-tested (__tests__/leadership-seo.test.mts) and the page only prints the result.
 *
 * THE RULES. A property is written only when its value exists: no empty string, no empty list, no null. Nothing is invented:
 * no education, awards, birth date, credentials, affiliations or qualifications — the profile has no such fields. The
 * person's public e-mail address and phone number are NOT part of the structured data (the page shows them to a visitor who
 * looks for them; a machine-readable copy would be a harvesting list). `sameAs` carries only the social profile addresses that
 * passed the site's allow-list (lib/public-links.ts, already applied by lib/leadership.ts) — not the personal website, not a
 * mailto, not a tel. `worksFor` is the organisation under its canonical name: org.nameEn, the acronym PLCC, the home page.
 *
 * Only an ACTIVE profile ever reaches the page (the API answers 404 for anything else), so there is no structured data for
 * an inactive or archived profile — the page does not exist.
 */

/** The only keys a Person block may carry: a test fails if anything else (an e-mail, a phone number, a birth date) appears. */
export const PERSON_KEYS = ["@context", "@type", "name", "alternateName", "jobTitle", "description", "image", "url", "worksFor", "sameAs"] as const;

export interface PersonJsonLd {
  "@context": "https://schema.org";
  "@type": "Person";
  name: string;
  alternateName?: string;
  jobTitle?: string;
  description?: string;
  image?: { "@type": "ImageObject"; url: string; width: number; height: number };
  url: string;
  worksFor: { "@type": "Organization"; name: string; alternateName: string; url: string };
  sameAs?: string[];
}

const isFilled = (value: string | null | undefined): value is string => typeof value === "string" && value.trim() !== "";

/**
 * @param profile the API record (both languages), @param view the same profile resolved for `locale`, @param url the canonical
 * address of the page (lib/leadership-share.ts `canonicalProfileUrl`).
 */
export function buildPersonJsonLd(args: { profile: Pick<LeadershipDetail, "name_bn" | "name_en">; view: LeaderDetailView; locale: Locale; url: string }): PersonJsonLd {
  const { profile, view, locale, url } = args;

  // The name in the OTHER language, when the profile has one and it differs: the same person under a second spelling.
  const other = locale === "bn" ? profile.name_en : profile.name_bn;
  const alternate = isFilled(other) ? cleanText(other, 200) : null;

  const person: PersonJsonLd = {
    "@context": "https://schema.org",
    "@type": "Person",
    name: view.name,
    url,
    worksFor: { "@type": "Organization", name: org.nameEn, alternateName: org.acronym, url: org.website },
  };
  if (alternate !== null && alternate !== "" && alternate !== view.name) person.alternateName = alternate;
  if (isFilled(view.designation)) person.jobTitle = view.designation;
  if (isFilled(view.shortBio)) person.description = view.shortBio;
  if (view.photo !== null) person.image = { "@type": "ImageObject", url: view.photo.src, width: view.photo.width, height: view.photo.height };
  if (view.social.length > 0) person.sameAs = view.social.map((link) => link.url);

  // Key order for a readable block: identity first, then the organisation and the profiles.
  return orderKeys(person);
}

function orderKeys(person: PersonJsonLd): PersonJsonLd {
  const ordered: Record<string, unknown> = {};
  for (const key of PERSON_KEYS) if (key in person) ordered[key] = (person as unknown as Record<string, unknown>)[key];
  return ordered as unknown as PersonJsonLd;
}

export interface BreadcrumbJsonLd {
  "@context": "https://schema.org";
  "@type": "BreadcrumbList";
  itemListElement: Array<{ "@type": "ListItem"; position: number; name: string; item: string }>;
}

/** Home → Leadership / Key Persons → the person, each with its absolute address in the page's language. */
export function buildLeaderBreadcrumbJsonLd(args: { locale: Locale; homeName: string; indexName: string; personName: string; personUrl: string }): BreadcrumbJsonLd {
  const root = localeRootUrl(args.locale);
  return {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [
      { "@type": "ListItem", position: 1, name: args.homeName, item: root },
      { "@type": "ListItem", position: 2, name: args.indexName, item: `${root}/leadership` },
      { "@type": "ListItem", position: 3, name: args.personName, item: args.personUrl },
    ],
  };
}
