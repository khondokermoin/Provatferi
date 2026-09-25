"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { getStrings } from "@/lib/i18n";
import { splitLocaleFromPathname, localizeHref } from "@/lib/i18n/paths";

/**
 * Plain-text switcher (no flags, per the approved design) — routing keeps
 * the path identical apart from the `/en` segment (Section D: shared slugs,
 * no per-locale lookup), so switching is a pure string operation: the exact
 * same record, at the other locale's URL for the same bare path.
 *
 * Renders nothing on member/* and committee/register/* — those routes have
 * no /en mirror (see [locale]/(site)/member/layout.tsx and the register
 * pages' own locale guard) and offering a switch that 404s would be worse
 * than not offering one.
 */
const EXCLUDED_PREFIXES = ["/member", "/committee/register"];

export default function LanguageSwitcher() {
  const { locale, path } = splitLocaleFromPathname(usePathname());
  const t = getStrings(locale);

  if (EXCLUDED_PREFIXES.some((prefix) => path === prefix || path.startsWith(`${prefix}/`))) {
    return null;
  }

  return (
    <div className="language-switcher" role="group" aria-label={t.switcher.label}>
      <Link href={localizeHref(path, "bn")} aria-current={locale === "bn" ? "true" : undefined} lang="bn">
        {t.switcher.bn}
      </Link>
      <span aria-hidden="true">|</span>
      <Link href={localizeHref(path, "en")} aria-current={locale === "en" ? "true" : undefined} lang="en">
        {t.switcher.en}
      </Link>
    </div>
  );
}
