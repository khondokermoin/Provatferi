import Link from "next/link";
import type { Metadata } from "next";
import { headers } from "next/headers";
import { org } from "@/lib/content";
import { getStrings } from "@/lib/i18n";
import { splitLocaleFromPathname, localizeHref } from "@/lib/i18n/paths";

// Route-segment 404: catches every notFound() call thrown inside [locale]/(site)
// — a bad recruitment/notice/committee slug, a closed application form — AND
// any unmatched URL under this group, since it sits above every page here.
// Rendered inside (site)/layout.tsx, so Header/Footer and site-wide nav stay
// present — a visitor who mistyped a link can still get anywhere else.
//
// A genuinely unmatched URL doesn't reliably carry route params here, so
// locale is read from the `x-pathname` header middleware.ts sets on every
// request — see that file's own docblock.

async function currentLocale() {
  const pathname = (await headers()).get("x-pathname") ?? "/";
  return splitLocaleFromPathname(pathname).locale;
}

export async function generateMetadata(): Promise<Metadata> {
  const locale = await currentLocale();
  return {
    title: getStrings(locale).notFound.title,
    robots: { index: false, follow: true },
  };
}

export default async function SiteNotFound() {
  const locale = await currentLocale();
  const t = getStrings(locale);

  return (
    <div className="error-page" role="main">
      <p className="error-page-code" aria-hidden="true">৪০৪</p>
      <h1>{t.notFound.heading}</h1>
      <p className="error-page-message">{t.notFound.message}</p>
      <div className="error-page-actions">
        <Link href={localizeHref("/", locale)} className="button button-primary">
          {t.notFound.backHome}
        </Link>
        <Link href={localizeHref("/recruitment", locale)} className="button button-outline">
          {t.notFound.viewOpportunities}
        </Link>
      </div>
      <p className="error-page-footnote">
        {t.notFound.troubleContact}: <a href={`mailto:${org.email}`}>{org.email}</a>
      </p>
    </div>
  );
}
