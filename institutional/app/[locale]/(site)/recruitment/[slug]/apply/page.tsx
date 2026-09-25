import Link from "next/link";
import type { Metadata } from "next";
import { notFound, permanentRedirect } from "next/navigation";
import { applicationWindowLabel, getJobPosting } from "@/lib/api/recruitment";
import { formatDate } from "@/lib/format";
import { localizedMetadata } from "@/lib/social-meta";
import VolunteerApplicationForm from "@/components/VolunteerApplicationForm";
import { getStrings, isLocale, type Locale } from "@/lib/i18n";
import { pickText } from "@/lib/i18n/pick";
import { localizeHref } from "@/lib/i18n/paths";

// The form exists exactly while the ERP says the posting accepts
// applications — closing it in the admin closes this route too.

export async function generateMetadata({ params }: { params: Promise<{ locale: string; slug: string }> }): Promise<Metadata> {
  const { locale: rawLocale, slug } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const result = await getJobPosting(slug);
  if (!result.ok || !result.data.accepts_applications) return {};

  const job = result.data;
  const jobTitle = pickText(locale, job.title, job.title_en);
  const title = locale === "en" ? `Apply — ${jobTitle.text}` : `আবেদন — ${jobTitle.text}`;
  const description =
    locale === "en"
      ? `${jobTitle.text} — apply online. Your application is submitted directly to Provatferi's own system.`
      : `${jobTitle.text} — অনলাইনে আবেদন করুন। আবেদন প্রভাতফেরীর নিজস্ব সিস্টেমে জমা হবে।`;

  return localizedMetadata({ locale, bnPath: `/recruitment/${job.slug}/apply`, title, description, hasTranslation: !jobTitle.isFallback });
}

export default async function VolunteerApplyPage({ params }: { params: Promise<{ locale: string; slug: string }> }) {
  const { locale: rawLocale, slug } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const t = getStrings(locale);
  const result = await getJobPosting(slug);
  if (!result.ok) notFound();

  const job = result.data;
  // §3: same history-resolved-slug case as the detail page, redirected to
  // the canonical, locale-prefixed /apply URL rather than the detail page.
  if (job.slug !== slug) permanentRedirect(localizeHref(`/recruitment/${job.slug}/apply`, locale));
  // Both fields share the identical backend guard ($detailed && acceptsApplications()),
  // so they are always null together — checking both here lets TypeScript narrow
  // field_requirements too, the same way skill_options already was.
  if (!job.accepts_applications || job.skill_options === null || job.field_requirements === null) notFound();

  const jobTitle = pickText(locale, job.title, job.title_en);

  return (
    <article className="notice-detail">
      <header className="page-header notice-detail-header">
        <nav className="breadcrumb" aria-label={t.common.breadcrumbAria}>
          <Link href={localizeHref("/", locale)}>{t.common.home}</Link>
          <span aria-hidden="true">/</span>
          <Link href={localizeHref("/recruitment", locale)}>{locale === "en" ? "Careers & Volunteer Opportunities" : "নিয়োগ ও স্বেচ্ছাসেবী সুযোগ"}</Link>
          <span aria-hidden="true">/</span>
          <Link href={localizeHref(`/recruitment/${job.slug}`, locale)}>{jobTitle.text}</Link>
          <span aria-hidden="true">/</span>
          <span aria-current="page">{locale === "en" ? "Apply" : "আবেদন"}</span>
        </nav>
        <h1>{locale === "en" ? "Application Form" : "আবেদন ফরম"}</h1>
        <p>
          {jobTitle.text} — {applicationWindowLabel(job, (v) => formatDate(v, locale), true, locale)}
          {locale === "en" ? "." : "।"}
          {job.is_volunteer ? ` ${job.volunteer_note ?? ""}` : ""}
        </p>
      </header>

      <section className="content-section">
        {/* The form no longer owns a success panel — a successful submission
            redirects to apply/success, which reads the community group from
            the posting itself, so neither prop belongs here any more. */}
        <VolunteerApplicationForm
          slug={job.slug}
          jobTitle={jobTitle.text}
          skills={job.skill_options}
          fieldRequirements={job.field_requirements}
          locale={locale}
        />
      </section>
    </article>
  );
}
