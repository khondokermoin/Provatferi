import type { Metadata } from "next";
import Link from "next/link";
import { org } from "@/lib/content";
import { applicationWindowLabel, getJobPostings } from "@/lib/api/recruitment";
import { formatBnDate } from "@/lib/format";
import PageHeader from "@/components/PageHeader";

const description = `${org.shortName}-এর চলমান ও ভবিষ্যৎ নিয়োগ ও স্বেচ্ছাসেবী সুযোগ।`;

export const metadata: Metadata = {
  title: "নিয়োগ বিজ্ঞপ্তি",
  description,
  alternates: { canonical: "/recruitment" },
  openGraph: { title: `নিয়োগ বিজ্ঞপ্তি | ${org.shortName}`, description, url: "/recruitment", images: [{ ...org.ogImage, alt: org.nameBn }] },
};

export default async function RecruitmentPage() {
  // Public contract only lists status = 'open' postings — applicants,
  // shortlist status and internal notes never leave the ERP. An API failure
  // renders the same honest empty state as a genuinely empty list: from a
  // visitor's perspective both mean "nothing to show right now".
  const result = await getJobPostings();
  const jobs = result.ok ? result.data : [];

  return (
    <>
      <PageHeader title="নিয়োগ বিজ্ঞপ্তি" description={description} />

      {jobs.length === 0 ? (
        <div className="empty-state">
          <p>বর্তমানে কোনো নিয়োগ বিজ্ঞপ্তি চলমান নেই</p>
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
                <span className="info-card-tag">{job.employment_type_label ?? job.department ?? "নিয়োগ"}</span>
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
                <Link href={`/recruitment/${job.slug}`} className="text-link">
                  বিস্তারিত দেখুন <span aria-hidden="true">→</span>
                </Link>
              </article>
            ))}
          </div>
        </section>
      )}
    </>
  );
}
