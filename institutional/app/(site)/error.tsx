"use client";

import { useEffect } from "react";
import Link from "next/link";
import { org } from "@/lib/content";

// Route-segment error boundary (App Router requirement: Client Component).
// Catches a RENDER/runtime failure inside (site) — e.g. an API call that
// throws instead of returning ApiResult's ok:false — as distinct from a
// deliberate notFound() call, which not-found.tsx handles instead. Rendered
// inside (site)/layout.tsx, so Header/Footer stay present.

export default function SiteError({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
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
      <h1>কিছু একটা ভুল হয়েছে</h1>
      <p className="error-page-message">
        পাতাটি লোড করতে সমস্যা হয়েছে। এটি সাময়িক হতে পারে — আবার চেষ্টা করুন।
      </p>
      <div className="error-page-actions">
        <button type="button" onClick={() => reset()} className="button button-primary">
          আবার চেষ্টা করুন
        </button>
        <Link href="/" className="button button-outline">
          হোমপেজে ফিরে যান
        </Link>
      </div>
      <p className="error-page-footnote">
        সমস্যা চলতে থাকলে জানান: <a href={`mailto:${org.email}`}>{org.email}</a>
        {error.digest ? ` (তথ্যসূত্র: ${error.digest})` : ""}
      </p>
    </div>
  );
}
