import Link from "next/link";
import type { Metadata } from "next";
import { org } from "@/lib/content";

// Route-segment 404: catches every notFound() call thrown inside (site) —
// a bad recruitment/notice/committee slug, a closed application form — AND
// any unmatched URL under this group, since it sits above every page here.
// Rendered inside (site)/layout.tsx, so Header/Footer and site-wide nav
// stay present — a visitor who mistyped a link can still get anywhere else.

export const metadata: Metadata = {
  title: "পাতাটি পাওয়া যায়নি",
  robots: { index: false, follow: true },
};

export default function SiteNotFound() {
  return (
    <div className="error-page" role="main">
      <p className="error-page-code" aria-hidden="true">৪০৪</p>
      <h1>পাতাটি খুঁজে পাওয়া যায়নি</h1>
      <p className="error-page-message">
        যে লিংকে এসেছেন তা হয়তো মুছে ফেলা হয়েছে, স্থানান্তরিত হয়েছে, অথবা ঠিকানাটি ভুল লেখা হয়েছে।
      </p>
      <div className="error-page-actions">
        <Link href="/" className="button button-primary">
          হোমপেজে ফিরে যান
        </Link>
        <Link href="/recruitment" className="button button-outline">
          স্বেচ্ছাসেবী সুযোগ দেখুন
        </Link>
      </div>
      <p className="error-page-footnote">
        সমস্যা মনে হলে আমাদের সঙ্গে যোগাযোগ করুন: <a href={`mailto:${org.email}`}>{org.email}</a>
      </p>
    </div>
  );
}
