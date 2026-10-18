import Link from "@/components/SiteLink";
import MessengerGroupCard from "@/components/MessengerGroupCard";
import ScrollToTop from "@/components/ScrollToTop";
import type { Metadata } from "next";
import { cookies } from "next/headers";
import { notFound } from "next/navigation";
import { getPublicContact } from "@/lib/contact";
import { applyCtaLabel, getJobPosting } from "@/lib/api/recruitment";
import { getStrings, isLocale, type Locale } from "@/lib/i18n";
import { localizeHref } from "@/lib/i18n/paths";
import { SUCCESS_COOKIE, verifyVolunteerSuccess } from "@/lib/volunteer-success";

/**
 * §8: a dedicated destination rather than a success state left sitting on the completed form. Reached by the form's own
 * client-side navigation (or, with no JavaScript, by a redirect from the Server Action), so the browser performs a GET —
 * refreshing it cannot resubmit the application or re-trigger the confirmation e-mail.
 *
 * THE MESSENGER GROUP (admin-erp/docs/VOLUNTEER_MESSENGER_GROUP.md). This page is the only place the central volunteer
 * Messenger group is offered, and only to a visitor whose browser has just submitted an application. "Just submitted" is
 * not a query string or a flag the page trusts: the submission left an HttpOnly cookie (lib/volunteer-success.ts) holding a
 * proof that Laravel issued, and Laravel verifies it on every load of this page — signature, expiry, this very posting,
 * application still live. Typing the address, a tampered or expired cookie, or another posting's proof shows the
 * confirmation-not-available page: no link, no button, no hint of one. The page reads a cookie, so it is rendered per
 * request (`force-dynamic`), and Next sends `Cache-Control: private, no-cache, no-store` with it: the invite is in the
 * response of a verified visitor and in no cached copy.
 *
 * §12: nothing here identifies the applicant — no name, number, e-mail or phone — and the page takes no id, token or query.
 */
export const dynamic = "force-dynamic";

export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }): Promise<Metadata> {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  return {
    // One neutral title: the same address answers a verified applicant and anyone who types it.
    title: getStrings(locale).applyConfirmation.metaTitle,
    // A confirmation page has no business in search results (and it is not in the sitemap).
    robots: { index: false, follow: false },
  };
}

/** Inline SVG, never a text glyph: the same stroke weight and colour as the rest of the page, in every font and on every device. */
function CheckIcon() {
  return (
    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
      <path d="m5 12.5 4.5 4.5L19 7.5" />
    </svg>
  );
}

function RetryIcon() {
  return (
    <svg className="apply-success-retry-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
      <path d="M20 12a8 8 0 1 1-2.4-5.7" />
      <path d="M20 4.5V9h-4.5" />
    </svg>
  );
}

export default async function VolunteerApplySuccessPage({ params }: { params: Promise<{ locale: string; slug: string }> }) {
  const { locale: rawLocale, slug } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const t = getStrings(locale);
  const text = t.applyConfirmation;

  const proof = (await cookies()).get(SUCCESS_COOKIE)?.value ?? null;
  const [result, contact, verification] = await Promise.all([getJobPosting(slug), getPublicContact(locale), verifyVolunteerSuccess(slug, proof)]);

  const verified = verification.kind === "verified";
  // An address that names no open posting is a 404 for everyone except an applicant whose posting has just closed.
  if (!result.ok && !verified) notFound();

  const job = result.ok ? result.data : null;
  const noticeHref = localizeHref(job?.notice_slug ? `/notices/${job.notice_slug}` : "/notices", locale);
  const messengerUrl = verification.kind === "verified" ? verification.messengerUrl : null;
  const applyHref = !verified && job?.accepts_applications && job.apply_path ? localizeHref(job.apply_path, locale) : null;

  return (
    <article className="apply-success">
      {/* The form's JavaScript reaches this page by a client-side navigation from the bottom of a long page. */}
      <ScrollToTop />

      {verified ? (
        <>
          <p className="apply-success-badge" role="status">
            <CheckIcon /> {text.receivedBadge}
          </p>
          <h1>{text.receivedHeading}</h1>
          <p className="apply-success-lead">{text.receivedLead}</p>
          <p>{messengerUrl ? text.receivedBodyWithGroup : text.receivedBody}</p>

          {messengerUrl && (
            <MessengerGroupCard
              url={messengerUrl}
              labels={{ ...t.messengerGroup, opensInNewTab: t.common.opensInNewTab }}
            />
          )}
        </>
      ) : (
        <>
          <p className="apply-success-badge apply-success-badge-neutral">{text.unverifiedBadge}</p>
          <h1>{text.unverifiedHeading}</h1>
          <p>{verification.kind === "unavailable" ? text.unreachableBody : text.unverifiedBody}</p>
          {verification.kind === "unavailable" && (
            <p>
              <a className="text-link" href={localizeHref(`/recruitment/${encodeURIComponent(slug)}/apply/success`, locale)}>
                {text.tryAgain} <RetryIcon />
              </a>
            </p>
          )}
          {applyHref && job && (
            <p className="apply-success-cta">
              <Link className="button button-primary" href={applyHref}>
                {applyCtaLabel(job.is_volunteer, locale)} <span aria-hidden="true">→</span>
              </Link>
            </p>
          )}
        </>
      )}

      <div className="apply-success-actions">
        <Link className="button button-outline" href={noticeHref}>
          {text.backToNotice}
        </Link>
        <Link className="text-link" href={localizeHref("/recruitment", locale)}>
          {text.viewAllOpportunities} <span aria-hidden="true">→</span>
        </Link>
      </div>

      {/* An applicant's question about their own application is support's (the receipt mail's Reply-To). */}
      <p className="apply-success-footnote">
        {text.questionsBefore}
        <a href={`mailto:${contact.supportEmail}`}>{contact.supportEmail}</a>
        {text.questionsAfter}
      </p>
    </article>
  );
}
