import type { Metadata } from "next";
import Link from "next/link";
import { org } from "@/lib/content";
import { applicationWindowLabel, applyCtaLabel, getJobPostings } from "@/lib/api/recruitment";
import { formatBnDate } from "@/lib/format";
import PageHeader from "@/components/PageHeader";

const title = "নিয়োগ ও স্বেচ্ছাসেবী সুযোগ";
const description = `${org.shortName}-এর চলমান নিয়োগ ও স্বেচ্ছাসেবী সুযোগ — আবেদন করা যায় সরাসরি ওয়েবসাইট থেকেই।`;

export const metadata: Metadata = {
  title,
  description,
  alternates: { canonical: "/recruitment" },
  openGraph: { title: `${title} | ${org.shortName}`, description, url: "/recruitment", images: [{ ...org.ogImage, alt: org.nameBn }] },
  twitter: { card: "summary_large_image", title: `${title} | ${org.shortName}`, description },
};

export default async function RecruitmentPage({ searchParams }: { searchParams: Promise<{ type?: string }> }) {
  // Public contract only lists status = 'open' postings — applicants,
  // shortlist status and internal notes never leave the ERP. An API failure
  // renders the same honest empty state as a genuinely empty list: from a
  // visitor's perspective both mean "nothing to show right now".
  const [result, query] = await Promise.all([getJobPostings(), searchParams]);
  const all = result.ok ? result.data : [];

  // §15: "স্বেচ্ছাসেবী সুযোগ" in the nav is this same page filtered, not a
  // second destination that would duplicate the listing.
  const volunteerOnly = query.type === "volunteer";
  const jobs = volunteerOnly ? all.filter((job) => job.is_volunteer) : all;
  const volunteerCount = all.filter((job) => job.is_volunteer).length;

  return (
    <>
      <PageHeader title={title} description={description} />

      {(volunteerOnly || volunteerCount > 0) && all.length > 0 && (
        <nav className="listing-filters" aria-label="ধরন অনুযায়ী ছাঁকুন">
          <Link href="/recruitment" className={`filter-pill ${volunteerOnly ? "" : "is-active"}`} aria-current={volunteerOnly ? undefined : "page"}>
            সব সুযোগ ({all.length})
          </Link>
          <Link
            href="/recruitment?type=volunteer"
            className={`filter-pill ${volunteerOnly ? "is-active" : ""}`}
            aria-current={volunteerOnly ? "page" : undefined}
          >
            স্বেচ্ছাসেবী ({volunteerCount})
          </Link>
        </nav>
      )}

      {jobs.length === 0 ? (
        <div className="empty-state">
          <p>{volunteerOnly ? "বর্তমানে কোনো স্বেচ্ছাসেবী সুযোগ চলমান নেই" : "বর্তমানে কোনো সুযোগ চলমান নেই"}</p>
          <p>নতুন সুযোগ প্রকাশিত হলে এই পাতায় ও নোটিশ বোর্ডে দেখা যাবে।</p>
          <Link href="/notices" className="button button-outline">
            নোটিশ বোর্ড দেখুন
          </Link>
        </div>
      ) : (
        <section className="content-section">
          <div className="card-grid cols-2">
            {jobs.map((job) => (
              <article key={job.slug} className="info-card job-card">
                <span className="info-card-tag">{job.employment_type_label ?? job.department ?? "সুযোগ"}</span>
                <h3>
                  <Link href={`/recruitment/${job.slug}`}>{job.title}</Link>
                </h3>
                {job.summary && <p>{job.summary}</p>}
                <dl className="definition-list job-card-facts">
                  <div>
                    <dt>আবেদনের সময়সীমা</dt>
                    <dd>{applicationWindowLabel(job, formatBnDate)}</dd>
                  </div>
                  {job.is_volunteer ? (
                    <div>
                      <dt>পারিশ্রমিক</dt>
                      <dd>{job.volunteer_note}</dd>
                    </div>
                  ) : (
                    job.salary_range && (
                      <div>
                        <dt>বেতন</dt>
                        <dd>{job.salary_range}</dd>
                      </div>
                    )
                  )}
                </dl>
                <div className="job-card-actions">
                  {job.accepts_applications && job.apply_path && (
                    <Link href={job.apply_path} className="button button-primary">
                      {applyCtaLabel(job.is_volunteer)} <span aria-hidden="true">→</span>
                    </Link>
                  )}
                  <Link href={`/recruitment/${job.slug}`} className="text-link">
                    বিস্তারিত দেখুন <span aria-hidden="true">→</span>
                  </Link>
                </div>
              </article>
            ))}
          </div>
        </section>
      )}
    </>
  );
}
