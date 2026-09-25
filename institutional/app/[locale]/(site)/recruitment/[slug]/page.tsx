import Link from "next/link";
import type { Metadata } from "next";
import { notFound, permanentRedirect } from "next/navigation";
import { org } from "@/lib/content";
import { applicationWindowLabel, applyCtaLabel, communityCtaLabel, getJobPosting } from "@/lib/api/recruitment";
import { formatDate } from "@/lib/format";
import { localizedMetadata } from "@/lib/social-meta";
import { excerpt } from "@/lib/text-blocks";
import TextBlocks from "@/components/TextBlocks";
import { getStrings, isLocale, type Locale } from "@/lib/i18n";
import { pickText, pickOptionalText } from "@/lib/i18n/pick";
import { employmentTypeLabel } from "@/lib/i18n/enums";
import { localizeHref } from "@/lib/i18n/paths";

// Only open postings resolve (JobPostingController), so a posting that closes
// drops out of this route on the next revalidation instead of lingering.

export async function generateMetadata({ params }: { params: Promise<{ locale: string; slug: string }> }): Promise<Metadata> {
  const { locale: rawLocale, slug } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const result = await getJobPosting(slug);
  if (!result.ok) return {};

  const job = result.data;
  const title = pickText(locale, job.title, job.title_en);
  const summary = pickOptionalText(locale, job.summary, job.summary_en);
  const description = summary?.text ?? excerpt(job.description ?? job.title);
  // §12/§13: share_image_url already encodes the full priority server-side
  // (this posting's own upload, then its linked notice's share/cover image)
  // — omitting width/height here on purpose, since a linked notice's cover
  // image isn't guaranteed to be 1200x630 the way a dedicated upload is, and
  // an incorrect size hint is worse than none. socialMeta()'s own fallback
  // (the approved brand mark, with its real known dimensions) applies when
  // this is null.
  const image = job.share_image_url ? { url: job.share_image_url, alt: title.text } : undefined;

  return localizedMetadata({
    locale,
    bnPath: `/recruitment/${job.slug}`,
    title: title.text,
    description,
    image,
    hasTranslation: !title.isFallback,
  });
}

export default async function RecruitmentDetailPage({ params }: { params: Promise<{ locale: string; slug: string }> }) {
  const { locale: rawLocale, slug } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const t = getStrings(locale);
  const result = await getJobPosting(slug);
  if (!result.ok) notFound();

  const job = result.data;
  // §3: the requested segment may be a retired slug that JobPostingController
  // resolved through history. job.slug is always the CURRENT one (present()
  // reads it straight off the model), so a mismatch here is exactly the
  // "old URL" case — a 308 keeps the visitor's link working forever while
  // telling search engines and browsers to remember the new, locale-correct address.
  if (job.slug !== slug) permanentRedirect(localizeHref(`/recruitment/${job.slug}`, locale));

  const title = pickText(locale, job.title, job.title_en);
  const summary = pickOptionalText(locale, job.summary, job.summary_en);
  const description = pickOptionalText(locale, job.description, job.description_en);
  const requirements = pickOptionalText(locale, job.requirements, job.requirements_en);
  const organizationUnit = pickOptionalText(locale, job.organization_unit, job.organization_unit_en);
  const employmentType = employmentTypeLabel(job.employment_type, job.employment_type_label, locale);
  const opening = formatDate(job.opening_date, locale);

  const breadcrumbData = {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [
      { "@type": "ListItem", position: 1, name: t.common.home, item: org.website },
      { "@type": "ListItem", position: 2, name: t.nav.involvedChildren["/recruitment"], item: `${org.website}${localizeHref("/recruitment", locale)}` },
      { "@type": "ListItem", position: 3, name: title.text },
    ],
  };

  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbData) }} />

      <article className="notice-detail">
        <header className="page-header notice-detail-header">
          <nav className="breadcrumb" aria-label={t.common.breadcrumbAria}>
            <Link href={localizeHref("/", locale)}>{t.common.home}</Link>
            <span aria-hidden="true">/</span>
            <Link href={localizeHref("/recruitment", locale)}>{locale === "en" ? "Careers & Volunteer Opportunities" : "নিয়োগ ও স্বেচ্ছাসেবী সুযোগ"}</Link>
            <span aria-hidden="true">/</span>
            <span aria-current="page">{title.text}</span>
          </nav>
          {employmentType && (
            <span className="notice-row-tags">
              <span className={`notice-type notice-type-${job.is_volunteer ? "people" : "official"}`}>{employmentType}</span>
            </span>
          )}
          <h1>{title.text}</h1>
          {summary && <p>{summary.text}</p>}
          {title.isFallback && <p className="translation-note">{t.common.translationPending}</p>}
        </header>

        <div className="notice-detail-layout">
          <aside className="notice-aside" aria-label={locale === "en" ? "Summary" : "সংক্ষিপ্ত তথ্য"}>
            <section className="notice-facts" aria-labelledby="job-facts-title">
              <h2 id="job-facts-title">{locale === "en" ? "Summary" : "সংক্ষিপ্ত তথ্য"}</h2>
              <dl className="definition-list">
                {employmentType && (
                  <div>
                    <dt>{t.common.type}</dt>
                    <dd>{employmentType}</dd>
                  </div>
                )}
                {(organizationUnit || job.department) && (
                  <div>
                    <dt>{t.common.unitOrDepartment}</dt>
                    <dd>{[organizationUnit?.text, job.department].filter(Boolean).join(" — ")}</dd>
                  </div>
                )}
                <div>
                  <dt>{locale === "en" ? "Application Window" : "আবেদনের সময়সীমা"}</dt>
                  <dd>{applicationWindowLabel(job, (v) => formatDate(v, locale), true, locale)}</dd>
                </div>
                {opening && (
                  <div>
                    <dt>{t.common.startDate}</dt>
                    <dd>{opening}</dd>
                  </div>
                )}
                {job.is_volunteer ? (
                  <div>
                    <dt>{t.common.stipend}</dt>
                    <dd>{job.volunteer_note}</dd>
                  </div>
                ) : (
                  job.salary_range && (
                    <div>
                      <dt>{t.common.salary}</dt>
                      <dd>{job.salary_range}</dd>
                    </div>
                  )
                )}
              </dl>

              {/* §10: applying happens on the website form. The WhatsApp
                  group is offered afterwards, as a community channel. */}
              {job.accepts_applications && job.apply_path ? (
                <Link href={localizeHref(job.apply_path, locale)} className="button button-primary">
                  {applyCtaLabel(job.is_volunteer, locale)} <span aria-hidden="true">→</span>
                </Link>
              ) : (
                <a href={`mailto:${org.email}?subject=${encodeURIComponent(title.text)}`} className="button button-primary">
                  {locale === "en" ? "Contact About This Opportunity" : "আবেদন সংক্রান্ত যোগাযোগ"}
                </a>
              )}

              {job.notice_action && (
                <a className="button button-outline" href={job.notice_action.url} target="_blank" rel="noopener noreferrer">
                  {communityCtaLabel(job.notice_action.url, pickText(locale, job.notice_action.label, job.notice_action.label_en).text, locale)} <span aria-hidden="true">↗</span>
                  <span className="sr-only"> ({t.common.opensInNewTab})</span>
                </a>
              )}

              {job.notice_slug && (
                <Link href={localizeHref(`/notices/${job.notice_slug}`, locale)} className="text-link">
                  {t.common.officialNotice} <span aria-hidden="true">→</span>
                </Link>
              )}
            </section>
          </aside>

          <div className="notice-body-area">
            {description && (
              <section className="content-section">
                <h2>{t.common.description}</h2>
                <TextBlocks text={description.text} />
                {description.isFallback && <p className="translation-note">{t.common.translationPending}</p>}
              </section>
            )}
            {requirements && (
              <section className="content-section">
                <h2>{t.common.requirements}</h2>
                <TextBlocks text={requirements.text} />
                {requirements.isFallback && <p className="translation-note">{t.common.translationPending}</p>}
              </section>
            )}
          </div>
        </div>

        <p className="notice-back">
          <Link href={localizeHref("/recruitment", locale)} className="text-link">
            <span aria-hidden="true">←</span> {locale === "en" ? "View all opportunities" : "সব সুযোগ দেখুন"}
          </Link>
        </p>
      </article>
    </>
  );
}
