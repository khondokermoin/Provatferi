"use client";

import { useEffect } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { org } from "@/lib/content";
import { getStrings } from "@/lib/i18n";
import { splitLocaleFromPathname, localizeHref } from "@/lib/i18n/paths";

// Route-segment error boundary (App Router requirement: Client Component).
// Catches a RENDER/runtime failure inside [locale]/(site) — e.g. an API call
// that throws instead of returning ApiResult's ok:false — as distinct from a
// deliberate notFound() call, which not-found.tsx handles instead. Rendered
// inside (site)/layout.tsx, so Header/Footer stay present.

export default function SiteError({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  const { locale } = splitLocaleFromPathname(usePathname());
  const t = getStrings(locale);

  useEffect(() => {
    // Server-side equivalent (Laravel's own exception log) already captures
    // backend failures; this logs the CLIENT-observed failure specifically,
    // digest included so a report can be matched back to server logs.
    // eslint-disable-next-line no-console
    console.error("Site error boundary:", error.digest ?? error.message, error);
  }, [error]);

  return (
    <div className="error-page" role="main">
      <p className="error-page-code" aria-hidden="true">⚠</p>
      <h1>{t.error.heading}</h1>
      <p className="error-page-message">{t.error.message}</p>
      <div className="error-page-actions">
        <button type="button" onClick={() => reset()} className="button button-primary">
          {t.error.retry}
        </button>
        <Link href={localizeHref("/", locale)} className="button button-outline">
          {t.error.backHome}
        </Link>
      </div>
      <p className="error-page-footnote">
        {t.error.troubleContact}: <a href={`mailto:${org.email}`}>{org.email}</a>
        {error.digest ? ` (${t.globalError.reference}: ${error.digest})` : ""}
      </p>
    </div>
  );
}
