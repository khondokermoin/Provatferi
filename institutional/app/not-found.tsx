import Link from "next/link";
import type { Metadata } from "next";

// The true root fallback — only reachable for a URL that doesn't match the
// (site) group's own not-found.tsx at all (site) has no sibling route group,
// so in practice this is a last-resort boundary. No Header/Footer here: this
// renders outside (site)/layout.tsx, so those components were never wrapped in.

export const metadata: Metadata = {
  title: "পাতাটি পাওয়া যায়নি",
  robots: { index: false, follow: true },
};

export default function RootNotFound() {
  return (
    <div className="error-page error-page-standalone" role="main">
      <p className="error-page-code" aria-hidden="true">৪০৪</p>
      <h1>পাতাটি খুঁজে পাওয়া যায়নি</h1>
      <p className="error-page-message">ঠিকানাটি ভুল অথবা পাতাটি আর নেই।</p>
      <div className="error-page-actions">
        <Link href="/" className="button button-primary">
          হোমপেজে ফিরে যান
        </Link>
      </div>
    </div>
  );
}
