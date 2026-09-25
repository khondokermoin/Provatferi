import type { Locale } from "./types";
import { DEFAULT_LOCALE } from "./types";

/**
 * middleware.ts always rewrites a bn request to an internal `/bn/...` path
 * (there is no real `/bn` segment in any browser-visible URL) and leaves an
 * `/en/...` request as-is — so `usePathname()`/the resolved route ALWAYS
 * carries one of these two prefixes internally. This strips it back to the
 * bare, locale-independent path every `navLinks`/`footerExploreLinks` href
 * is written in.
 */
export function splitLocaleFromPathname(pathname: string): { locale: Locale; path: string } {
  if (pathname === "/en" || pathname.startsWith("/en/")) {
    return { locale: "en", path: pathname.slice(3) || "/" };
  }
  if (pathname === "/bn" || pathname.startsWith("/bn/")) {
    return { locale: "bn", path: pathname.slice(3) || "/" };
  }
  return { locale: DEFAULT_LOCALE, path: pathname };
}

/** The real, browser-visible URL for a bare `path` in `locale` — bn is never prefixed. */
export function localizeHref(path: string, locale: Locale): string {
  if (locale === "bn") return path;
  return path === "/" ? "/en" : `/en${path}`;
}

/** The canonical (always-bn) absolute URL for a bare path, given the site origin. */
export function canonicalUrl(origin: string, path: string): string {
  return path === "/" ? origin : `${origin}${path}`;
}
