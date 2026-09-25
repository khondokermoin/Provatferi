import Link from "next/link";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { org } from "@/lib/content";
import { getJobPosting } from "@/lib/api/recruitment";
import { isLocale, type Locale } from "@/lib/i18n";
import { localizeHref } from "@/lib/i18n/paths";

/**
 * §8: a dedicated destination rather than a success state left sitting on the
 * completed form. Reached by a redirect from the Server Action, so the
 * browser performs a GET — refreshing it cannot resubmit the application or
 * re-trigger the confirmation e-mail.
 *
 * §12: nothing here identifies the applicant. The page takes no id, no
 * reference, no token and no query string — it renders the same confirmation
 * for anyone who reaches it, so there is nothing to leak, guess or share.
 */
export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }): Promise<Metadata> {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  return {
    title: locale === "en" ? "Application Received" : "আবেদন গৃহীত",
    // A confirmation page has no business in search results.
    robots: { index: false, follow: false },
  };
}

export default async function VolunteerApplySuccessPage({ params }: { params: Promise<{ locale: string; slug: string }> }) {
  const { locale: rawLocale, slug } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const en = locale === "en";
  const result = await getJobPosting(slug);
  if (!result.ok) notFound();

  const job = result.data;
  // The community group comes from the linked notice's own CTA, the single
  // place that URL is configured.
  const communityUrl = job.notice_action?.url ?? null;
  const noticeHref = localizeHref(job.notice_slug ? `/notices/${job.notice_slug}` : "/notices", locale);

  return (
    <article className="apply-success">
      <p className="apply-success-badge" role="status">
        <span aria-hidden="true">✓</span> {en ? "Application Received" : "আবেদন গৃহীত"}
      </p>

      <h1>{en ? "Your application has been received" : "আপনার আবেদন সফলভাবে গ্রহণ করা হয়েছে"}</h1>

      <p className="apply-success-lead">
        {en ? "Thank you for your interest in Provatferi's volunteer programme." : "প্রভাতফেরীর স্বেচ্ছাসেবী কার্যক্রমে আগ্রহ প্রকাশ করার জন্য আপনাকে ধন্যবাদ।"}
      </p>

      <p>
        {en
          ? "Our team will review the information you provided. We'll reach out by phone or email for the next steps if needed. There's nothing more for you to do right now."
          : "আপনার দেওয়া তথ্য আমাদের টিম যাচাই করবে। প্রয়োজন অনুযায়ী পরবর্তী ধাপে আপনার সঙ্গে মোবাইল বা ই-মেইলে যোগাযোগ করা হবে। এই মুহূর্তে আপনাকে আর কিছু করতে হবে না।"}
      </p>

      {communityUrl && (
        <div className="apply-success-community">
          <p>
            {en
              ? "You're welcome to join our volunteer WhatsApp Group for updates and introductions — this is optional."
              : "যোগাযোগ, পরিচিতি এবং পরবর্তী আপডেটের জন্য আমাদের স্বেচ্ছাসেবী WhatsApp Group-এ যুক্ত হতে পারেন — এটি ঐচ্ছিক।"}
          </p>
          <a className="button button-primary" href={communityUrl} target="_blank" rel="noopener noreferrer">
            {en ? "Join WhatsApp Group" : "WhatsApp Group-এ যুক্ত হোন"} <span aria-hidden="true">↗</span>
            <span className="sr-only"> ({en ? "opens in a new tab" : "নতুন ট্যাবে খুলবে"})</span>
          </a>
        </div>
      )}

      <div className="apply-success-actions">
        <Link className="button button-outline" href={noticeHref}>
          {en ? "Back to Notice" : "নোটিশে ফিরে যান"}
        </Link>
        <Link className="text-link" href={localizeHref("/recruitment", locale)}>
          {en ? "View all opportunities" : "সব সুযোগ দেখুন"} <span aria-hidden="true">→</span>
        </Link>
      </div>

      <p className="apply-success-footnote">
        {en ? (
          <>Questions? Write to us at <a href={`mailto:${org.email}`}>{org.email}</a>.</>
        ) : (
          <>প্রশ্ন থাকলে <a href={`mailto:${org.email}`}>{org.email}</a> — এ লিখতে পারেন।</>
        )}
      </p>
    </article>
  );
}
