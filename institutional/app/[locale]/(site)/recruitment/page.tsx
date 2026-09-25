import type { Metadata } from "next";
import Link from "next/link";
import { org } from "@/lib/content";
import { applicationWindowLabel, applyCtaLabel, getJobPostings } from "@/lib/api/recruitment";
import { formatDate } from "@/lib/format";
import { localizedMetadata } from "@/lib/social-meta";
import PageHeader from "@/components/PageHeader";
import { getStrings, isLocale, type Locale } from "@/lib/i18n";
import { pickText } from "@/lib/i18n/pick";
import { employmentTypeLabel } from "@/lib/i18n/enums";
import { localizeHref } from "@/lib/i18n/paths";

const TITLES: Record<Locale, string> = { bn: "নিয়োগ ও স্বেচ্ছাসেবী সুযোগ", en: "Careers & Volunteer Opportunities" };
const DESCRIPTIONS: Record<Locale, string> = {
  bn: `${org.shortName}-এর চলমান নিয়োগ ও স্বেচ্ছাসেবী সুযোগ — আবেদন করা যায় সরাসরি ওয়েবসাইট থেকেই।`,
  en: `${org.nameEn}'s current openings and volunteer opportunities — apply directly through the website.`,
};

export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }): Promise<Metadata> {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  return localizedMetadata({ locale, bnPath: "/recruitment", title: TITLES[locale], description: DESCRIPTIONS[locale] });
}

export default async function RecruitmentPage({
  params,
  searchParams,
}: {
  params: Promise<{ locale: string }>;
  searchParams: Promise<{ type?: string }>;
}) {
  const [{ locale: rawLocale }, query] = await Promise.all([params, searchParams]);
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const t = getStrings(locale);

  // Public contract only lists status = 'open' postings — applicants,
  // shortlist status and internal notes never leave the ERP. An API failure
  // renders the same honest empty state as a genuinely empty list: from a
  // visitor's perspective both mean "nothing to show right now".
  const result = await getJobPostings();
  const all = result.ok ? result.data : [];

  // §15: "স্বেচ্ছাসেবী সুযোগ" in the nav is this same page filtered, not a
  // second destination that would duplicate the listing.
  const volunteerOnly = query.type === "volunteer";
  const jobs = volunteerOnly ? all.filter((job) => job.is_volunteer) : all;
  const volunteerCount = all.filter((job) => job.is_volunteer).length;

  return (
    <>
      <PageHeader title={TITLES[locale]} description={DESCRIPTIONS[locale]} locale={locale} />

      {(volunteerOnly || volunteerCount > 0) && all.length > 0 && (
        <nav className="listing-filters" aria-label={locale === "en" ? "Filter by type" : "ধরন অনুযায়ী ছাঁকুন"}>
          <Link href={localizeHref("/recruitment", locale)} className={`filter-pill ${volunteerOnly ? "" : "is-active"}`} aria-current={volunteerOnly ? undefined : "page"}>
            {locale === "en" ? `All Opportunities (${all.length})` : `সব সুযোগ (${all.length})`}
          </Link>
          <Link
            href={localizeHref("/recruitment?type=volunteer", locale)}
            className={`filter-pill ${volunteerOnly ? "is-active" : ""}`}
            aria-current={volunteerOnly ? "page" : undefined}
          >
            {locale === "en" ? `Volunteer (${volunteerCount})` : `স্বেচ্ছাসেবী (${volunteerCount})`}
          </Link>
        </nav>
      )}

      {jobs.length === 0 ? (
        <div className="empty-state">
          <p>
            {volunteerOnly
              ? (locale === "en" ? "There are no volunteer opportunities open at the moment" : "বর্তমানে কোনো স্বেচ্ছাসেবী সুযোগ চলমান নেই")
              : (locale === "en" ? "There are no opportunities open at the moment" : "বর্তমানে কোনো সুযোগ চলমান নেই")}
          </p>
          <p>{locale === "en" ? "New opportunities will appear here and on the Notice Board once published." : "নতুন সুযোগ প্রকাশিত হলে এই পাতায় ও নোটিশ বোর্ডে দেখা যাবে।"}</p>
          <Link href={localizeHref("/notices", locale)} className="button button-outline">
            {locale === "en" ? "View Notice Board" : "নোটিশ বোর্ড দেখুন"}
          </Link>
        </div>
      ) : (
        <section className="content-section">
          <div className="card-grid cols-2">
            {jobs.map((job) => {
              const title = pickText(locale, job.title, job.title_en);
              const summary = job.summary ? pickText(locale, job.summary, job.summary_en) : null;
              return (
                <article key={job.slug} className="info-card job-card">
                  <span className="info-card-tag">
                    {employmentTypeLabel(job.employment_type, job.employment_type_label, locale) ?? job.department ?? (locale === "en" ? "Opportunity" : "সুযোগ")}
                  </span>
                  <h3>
                    <Link href={localizeHref(`/recruitment/${job.slug}`, locale)}>{title.text}</Link>
                  </h3>
                  {summary && <p>{summary.text}</p>}
                  <dl className="definition-list job-card-facts">
                    <div>
                      <dt>{locale === "en" ? "Application Window" : "আবেদনের সময়সীমা"}</dt>
                      <dd>{applicationWindowLabel(job, (v) => formatDate(v, locale), true, locale)}</dd>
                    </div>
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
                  <div className="job-card-actions">
                    {job.accepts_applications && job.apply_path && (
                      <Link href={localizeHref(job.apply_path, locale)} className="button button-primary">
                        {applyCtaLabel(job.is_volunteer, locale)} <span aria-hidden="true">→</span>
                      </Link>
                    )}
                    <Link href={localizeHref(`/recruitment/${job.slug}`, locale)} className="text-link">
                      {t.common.viewDetails} <span aria-hidden="true">→</span>
                    </Link>
                  </div>
                </article>
              );
            })}
          </div>
        </section>
      )}
    </>
  );
}
