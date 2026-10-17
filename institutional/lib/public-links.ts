/**
 * The public site's own copy of the rules for what may be shown as a link, a phone number or a contact address
 * (admin-erp/app/Support/PublicLinks.php and InstitutionMail::publicContactProblem are the originals; keep the vectors of
 * __tests__/public-links.test.mts and tests/Unit/Support/PublicLinksTest.php in step).
 *
 * WHY A SECOND COPY. The ERP validates every value when an admin saves it and again when it builds the public API, but
 * this site renders whatever the API returns into href attributes and JSON-LD, so it checks again: a bug or a bad row on
 * the other side must never become a `javascript:` link or an `admin@` address in a visitor's browser. Anything that does
 * not pass is simply dropped, never repaired.
 *
 * The parsing is deliberately NOT done with `new URL()`: the WHATWG parser silently strips tabs and newlines, treats a
 * backslash as a slash, and percent-decodes a host, all of which let an input mean something other than what it says.
 * A link is first checked character by character, then split by hand.
 */

export const SOCIAL_PLATFORMS = ["facebook", "linkedin", "youtube"] as const;
export type SocialPlatform = (typeof SOCIAL_PLATFORMS)[number];

/** platform -> domains; each domain and any sub-domain of it is accepted. */
const SOCIAL_DOMAINS: Record<SocialPlatform, readonly string[]> = {
  facebook: ["facebook.com", "fb.com", "fb.me"],
  linkedin: ["linkedin.com"],
  youtube: ["youtube.com", "youtu.be"],
};

const SOCIAL_MAX_LENGTH = 500;
const MAP_MAX_LENGTH = 1000;

/** Anything outside printable ASCII, or a backslash, quote, angle bracket, backtick, brace, pipe or caret. */
const FORBIDDEN_CHARACTERS = /[^\x21-\x7E]|[\\<>"'`{}|^]/;
const HOST_SHAPE = /^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/;

interface Parts {
  host: string;
  path: string;
  query: string;
}

function parseHttps(input: string, maxLength: number): Parts | null {
  const value = input.trim();
  if (value === "" || value.length > maxLength) return null;
  if (!/^https:\/\//i.test(value)) return null;
  if (FORBIDDEN_CHARACTERS.test(value)) return null;

  const rest = value.slice("https://".length);
  const authorityEnd = rest.search(/[/?#]/);
  const authority = authorityEnd === -1 ? rest : rest.slice(0, authorityEnd);
  const remainder = authorityEnd === -1 ? "" : rest.slice(authorityEnd);

  if (authority.includes("@") || authority.includes("%")) return null; // user-info, or an encoded host
  const [hostPart, ...portParts] = authority.split(":");
  if (portParts.length > 1 || (portParts.length === 1 && portParts[0] !== "443")) return null;

  const host = hostPart.toLowerCase().replace(/\.$/, "");
  if (!HOST_SHAPE.test(host) || host.includes("xn--")) return null;

  const fragmentAt = remainder.indexOf("#");
  const withoutFragment = fragmentAt === -1 ? remainder : remainder.slice(0, fragmentAt);
  const queryAt = withoutFragment.indexOf("?");
  const path = queryAt === -1 ? withoutFragment : withoutFragment.slice(0, queryAt);
  const query = queryAt === -1 ? "" : withoutFragment.slice(queryAt + 1);

  return { host, path, query };
}

function build(parts: Parts): string {
  return `https://${parts.host}${parts.path}${parts.query !== "" ? `?${parts.query}` : ""}`;
}

function isRootOnly(parts: Parts): boolean {
  return (parts.path === "" || parts.path === "/") && parts.query === "";
}

function hostIsOneOf(host: string, domains: readonly string[]): boolean {
  return domains.some((domain) => host === domain || host.endsWith(`.${domain}`));
}

/** The normalised address of a page on the given platform, or null when it is anything else. */
export function safeSocialUrl(platform: string, input: unknown): string | null {
  if (typeof input !== "string" || !(SOCIAL_PLATFORMS as readonly string[]).includes(platform)) return null;
  const parts = parseHttps(input, SOCIAL_MAX_LENGTH);
  if (parts === null) return null;
  if (!hostIsOneOf(parts.host, SOCIAL_DOMAINS[platform as SocialPlatform])) return null;
  if (isRootOnly(parts)) return null;
  return build(parts);
}

/**
 * A person's own website (Leadership profiles): any public site over https, in the same character and shape rules as every
 * other link here, except that a host which cannot be somebody's public website is refused — an IP address, or a name that
 * only exists inside a network (`.local`, `.internal`, …). The home page alone is a fine address.
 */
export function safeWebsiteUrl(input: unknown): string | null {
  if (typeof input !== "string") return null;
  const parts = parseHttps(input, SOCIAL_MAX_LENGTH);
  if (parts === null) return null;

  const isIpAddress = /^\d{1,3}(\.\d{1,3}){3}$/.test(parts.host);
  const isPrivateName = /\.(local|localhost|internal|intranet|lan|home|corp|test|invalid|example)$/.test(parts.host);
  if (isIpAddress || isPrivateName || !/\.[a-z]{2,}$/.test(parts.host)) return null;

  return build(parts);
}

const GOOGLE_MAPS_HOSTS = ["google.com", "www.google.com", "google.com.bd", "www.google.com.bd"];

/** The normalised address of a Google Maps location, or null. Embed/iframe addresses are never accepted. */
export function safeMapUrl(input: unknown): string | null {
  if (typeof input !== "string") return null;
  if (/<\s*(iframe|script|embed|object|div|a)\b/i.test(input)) return null;
  const parts = parseHttps(input, MAP_MAX_LENGTH);
  if (parts === null) return null;

  const { host, path } = parts;
  const lowerPath = path.toLowerCase();
  if (lowerPath.includes("/embed") || lowerPath.includes("/api/")) return null;

  const isMapsPath = path === "/maps" || path.startsWith("/maps/");
  let allowed: boolean;
  if (GOOGLE_MAPS_HOSTS.includes(host)) allowed = isMapsPath;
  else if (host === "maps.google.com") allowed = !isRootOnly(parts);
  else if (host === "maps.app.goo.gl") allowed = /^\/[A-Za-z0-9]{6,40}$/.test(path);
  else if (host === "goo.gl") allowed = path.startsWith("/maps/");
  else allowed = false;

  return allowed ? build(parts) : null;
}

/** One phone number as typed (digits, +, spaces, brackets, dots, hyphens; 6-15 digits), or null. */
export function safePhone(input: unknown): string | null {
  if (typeof input !== "string") return null;
  const value = input.replace(/\s+/g, " ").trim();
  const digits = value.replace(/\D/g, "");
  if (!/^\+?[0-9(][0-9 ().-]{3,28}[0-9]$/.test(value) || digits.length < 6 || digits.length > 15) return null;
  return value;
}

/** The `tel:` address for a phone number that passed safePhone: a leading + and digits only. */
export function telHref(phone: string): string {
  const plus = phone.trim().startsWith("+") ? "+" : "";
  return `tel:${plus}${phone.replace(/\D/g, "")}`;
}

/** Local parts that are never a public contact: the internal mailbox, the automated sender, the security mailbox. */
const NEVER_PUBLIC_LOCAL_PARTS = new Set(["admin", "no-reply", "noreply", "donotreply", "do-not-reply", "security"]);

/**
 * A well-formed address that may be published, lower-cased, or null. The ERP applies the full role rules (it knows the
 * configured internal and SMTP mailboxes); this refuses the three roles that can be recognised by their name alone.
 */
export function usablePublicEmail(input: unknown): string | null {
  if (typeof input !== "string") return null;
  const value = input.trim().toLowerCase();
  // No percent sign in the local part: an address becomes a mailto: link, and `%0d%0a` in one is a header injection.
  if (value.length > 254 || !/^[a-z0-9._+-]+@[a-z0-9-]+(\.[a-z0-9-]+)+$/.test(value)) return null;
  const local = value.slice(0, value.indexOf("@"));
  return NEVER_PUBLIC_LOCAL_PARTS.has(local) ? null : value;
}

/** Control characters (line and paragraph separators included) become spaces; a line feed and a tab-free CR/LF pair are kept. */
function stripControlCharacters(text: string): string {
  let out = "";
  for (const ch of text) {
    const code = ch.codePointAt(0) as number;
    const isControl = code <= 0x08 || code === 0x0b || code === 0x0c || (code >= 0x0e && code <= 0x1f) || code === 0x7f || code === 0x2028 || code === 0x2029;
    out += isControl ? " " : ch;
  }
  return out;
}

/** A line of plain text from the ERP: control characters become spaces, empty becomes null. */
export function cleanText(input: unknown, maxLength = 1000): string | null {
  if (typeof input !== "string") return null;
  // Keep line breaks (an address may span lines); everything else that is a control character is a space.
  const text = stripControlCharacters(input).trim();
  return text === "" ? null : text.slice(0, maxLength);
}
