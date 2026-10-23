import { org } from "./content";
import type { Locale, UiStrings } from "./i18n/types";

/**
 * Share / copy-link for a Leadership profile (2026-10-23): everything that decides WHAT is shared, as pure functions, so the
 * rules are unit-tested (__tests__/leadership-share.test.mts) and the browser component (components/LeaderActions.tsx) has
 * nothing left to decide.
 *
 * THE ADDRESS IS CANONICAL OR NOTHING. It is built here, on the server, from the profile's CURRENT slug (the API answers an
 * old address with the current one) and the site's own origin (`org.website`) — never from `window.location`, so a preview
 * host, a `www.` variant, a query string, a hash, a locale-internal `/bn/...` rewrite or an API address can never be copied.
 * The English address is offered only when the English page really exists (an English name AND designation, the site's
 * rule: lib/leadership.ts `hasEnglishPage`); otherwise the English page is a fallback render whose canonical is the Bangla
 * address, and that is what is shared — no English address is invented.
 *
 * What is shared is public by construction: the name, the designation and the address. No phone, no e-mail, no biography.
 * The component validates the address once more before it hands it to the Web Share API or the clipboard.
 */

/** A slug as the ERP writes it (LeadershipProfile::uniqueSlug): lower-case ASCII words joined by single hyphens, 1-80 characters. */
const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

export function isProfileSlug(value: unknown): value is string {
  return typeof value === "string" && value.length > 0 && value.length <= 80 && SLUG.test(value);
}

const escapeRegExp = (value: string) => value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
const CANONICAL_PROFILE_URL = new RegExp(`^${escapeRegExp(org.website)}(?:/en)?/leadership/[a-z0-9]+(?:-[a-z0-9]+)*$`);

/** True only for a canonical public profile address: this site's origin, an optional `/en`, `/leadership/<slug>`, nothing after it. */
export function isCanonicalProfileUrl(value: unknown): value is string {
  return typeof value === "string" && value.length <= 200 && CANONICAL_PROFILE_URL.test(value);
}

/** The canonical address of a profile page, or null when the slug is not a slug (never a guess). */
export function canonicalProfileUrl(slug: string, locale: Locale, hasEnglish: boolean): string | null {
  if (!isProfileSlug(slug)) return null;
  return `${org.website}${locale === "en" && hasEnglish ? "/en" : ""}/leadership/${slug}`;
}

/** The locale's own address of the home page and of the Leadership index (used by the breadcrumb). */
export function localeRootUrl(locale: Locale): string {
  return locale === "en" ? `${org.website}/en` : org.website;
}

// Control characters and the bidirectional overrides/isolates are dropped; ZWJ and ZWNJ (U+200D, U+200C) stay: Bangla needs them.
// Built from code points: a literal U+2028/U+2029 in a regex does not parse (see lib/json-ld.ts).
const UNSAFE_TEXT = new RegExp(
  `[\\u0000-\\u001f\\u007f-\\u009f\\u200b\\u200e\\u200f\\u202a-\\u202e\\u2066-\\u2069\\ufeff${String.fromCharCode(0x2028)}${String.fromCharCode(0x2029)}]`,
  "g",
);

/** One line of plain text, at most `max` characters (counted as characters, not UTF-16 units). */
export function cleanShareText(value: string, max: number): string {
  const oneLine = value.replace(UNSAFE_TEXT, " ").replace(/\s+/g, " ").trim();
  const characters = Array.from(oneLine);
  return characters.length <= max ? oneLine : `${characters.slice(0, max - 1).join("").trimEnd()}…`;
}

export interface ShareTarget {
  /** The canonical profile address. */
  url: string;
  /** The person's name, as the page shows it. */
  title: string;
  /** The designation, as the page shows it. */
  text: string;
}

/** What a card or a profile page shares, or null when the address cannot be built safely. */
export function buildShareTarget(args: { slug: string; name: string; designation: string; hasEnglish: boolean; locale: Locale }): ShareTarget | null {
  const url = canonicalProfileUrl(args.slug, args.locale, args.hasEnglish);
  if (url === null) return null;
  const title = cleanShareText(args.name, 120);
  if (title === "") return null;
  return { url, title, text: cleanShareText(args.designation, 160) };
}

export type ShareLinkId = "whatsapp" | "facebook" | "linkedin" | "email";

export interface ShareLink {
  id: ShareLinkId;
  href: string;
}

/**
 * The fallback for a browser without the Web Share API (most desktop browsers): plain links to the big sharing pages, each
 * carrying only the canonical address and the name and designation. The hosts are fixed here (not taken from any input),
 * the values are percent-encoded, and the component opens them with `target="_blank" rel="noopener noreferrer"`. There is
 * no `javascript:` or `data:` address anywhere: every href is https or the e-mail scheme and starts with one of the fixed
 * prefixes below (checked by a test).
 */
export const SHARE_LINK_PREFIXES: Record<ShareLinkId, string> = {
  whatsapp: "https://wa.me/?text=",
  facebook: "https://www.facebook.com/sharer/sharer.php?u=",
  linkedin: "https://www.linkedin.com/sharing/share-offsite/?url=",
  email: "mailto:?subject=",
};

export function buildShareLinks(target: ShareTarget): ShareLink[] {
  const q = encodeURIComponent;
  const line = target.text === "" ? target.title : `${target.title} — ${target.text}`;
  return [
    { id: "whatsapp", href: `${SHARE_LINK_PREFIXES.whatsapp}${q(`${line}\n${target.url}`)}` },
    { id: "facebook", href: `${SHARE_LINK_PREFIXES.facebook}${q(target.url)}` },
    { id: "linkedin", href: `${SHARE_LINK_PREFIXES.linkedin}${q(target.url)}` },
    { id: "email", href: `${SHARE_LINK_PREFIXES.email}${q(target.title)}&body=${q(`${line}\n${target.url}`)}` },
  ];
}

/** The strings the browser component needs, picked here so the whole dictionary never travels to the browser. */
export interface LeaderActionLabels {
  actions: string;
  share: string;
  copy: string;
  copied: string;
  shareVia: string;
  copyFailed: string;
  email: string;
  opensInNewTab: string;
}

export function leaderActionLabels(strings: UiStrings, name: string): LeaderActionLabels {
  const l = strings.leadership;
  return {
    actions: l.actionsLabel(name),
    share: l.shareProfile,
    copy: l.copyProfileLink,
    copied: l.linkCopied,
    shareVia: l.shareVia,
    copyFailed: l.copyFailed,
    email: l.email,
    opensInNewTab: strings.common.opensInNewTab,
  };
}
