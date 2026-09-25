import type { Metadata } from "next";
import { org } from "@/lib/content";
import { localizeHref } from "@/lib/i18n/paths";
import type { Locale } from "@/lib/i18n";

export type ShareImage = { url: string; width?: number; height?: number; alt: string };

/**
 * §13: produces openGraph AND twitter together, always.
 *
 * 13 of 16 public pages once defined `openGraph` alone in their own metadata
 * object and silently inherited the ROOT layout's org-level
 * twitter:title/twitter:image instead of their own — a shared notice or
 * recruitment link rendered as a card for the *organisation*, not for the
 * thing being shared. Centralising both blocks in one function is what stops
 * that recurring: a page can no longer define one without the other, because
 * there is exactly one call that produces both.
 *
 * Share image priority (§12/§13): the caller passes the most specific image
 * it has (an uploaded share image, then a linked cover image); omitting
 * `image` falls back to the global Provatferi mark. og:image is therefore
 * never empty.
 */
export function socialMeta(args: {
  title: string;
  description: string;
  url: string;
  type?: "website" | "article";
  image?: ShareImage;
}): Pick<Metadata, "openGraph" | "twitter"> {
  const image = args.image ?? { ...org.ogImage, alt: org.nameBn };

  return {
    openGraph: {
      type: args.type ?? "website",
      title: args.title,
      description: args.description,
      url: args.url,
      images: [image],
    },
    twitter: {
      card: "summary_large_image",
      title: args.title,
      description: args.description,
      images: [image.url],
    },
  };
}

/**
 * Phase 2 (plan Section F/E): every localized page's `alternates` computed
 * in one place, so the "omit `en` when untranslated, canonical -> bn when
 * untranslated" rule can't erode into ad-hoc per-page logic.
 *
 * `bnPath` is always the bare, canonical Bangla path (e.g. "/about" or
 * "/activities/foo") — the one path guaranteed to exist for every record.
 * `hasTranslation` is true for every static page (always fully translated by
 * this phase) and, for a dynamic record, true only when its own `_en` field
 * actually has content (pass the `!isFallback` result of pickText()).
 *
 * - bn locale: canonical is always the bn URL itself.
 * - en locale + hasTranslation: canonical is the en URL itself (a real,
 *   distinct indexable page).
 * - en locale + !hasTranslation: canonical points at the BN url — this
 *   /en/... response is a fallback render, not a distinct English page yet.
 * `x-default` always targets bn, the one locale guaranteed to exist.
 */
export function localeAlternates(locale: Locale, bnPath: string, hasTranslation = true): Metadata["alternates"] {
  const enPath = localizeHref(bnPath, "en");
  const languages: Record<string, string> = { bn: bnPath, "x-default": bnPath };
  if (hasTranslation) languages.en = enPath;

  const canonical = locale === "bn" ? bnPath : hasTranslation ? enPath : bnPath;

  return { canonical, languages };
}

/**
 * The common case: `localeAlternates` + `socialMeta` together, for a page
 * whose `openGraph`/`twitter` need nothing beyond the standard shape. A page
 * needing a custom openGraph (e.g. an `article` with publishedTime) calls
 * `localeAlternates` directly instead and builds the rest itself.
 */
export function localizedMetadata(args: {
  locale: Locale;
  bnPath: string;
  title: string;
  description: string;
  type?: "website" | "article";
  image?: ShareImage;
  hasTranslation?: boolean;
}): Metadata {
  const { locale, bnPath, hasTranslation = true, ...rest } = args;
  const alternates = localeAlternates(locale, bnPath, hasTranslation);
  const url = typeof alternates?.canonical === "string" ? alternates.canonical : bnPath;

  return {
    title: rest.title,
    description: rest.description,
    alternates,
    ...socialMeta({ title: rest.title, description: rest.description, url, type: rest.type, image: rest.image }),
  };
}
