import type { Metadata } from "next";
import { org } from "@/lib/content";
import { getJobPostings } from "@/lib/api/recruitment";
import PageHeader from "@/components/PageHeader";

const description = `${org.shortName}-এর চলমান ও ভবিষ্যৎ নিয়োগ বিজ্ঞপ্তি।`;

export const metadata: Metadata = {
  title: "নিয়োগ বিজ্ঞপ্তি",
  description,
  alternates: { canonical: "/recruitment" },
  openGraph: { title: `নিয়োগ বিজ্ঞপ্তি | ${org.shortName}`, description, url: "/recruitment", images: [{ ...org.ogImage, alt: org.nameBn }] },
};

export default async function RecruitmentPage() {
  // Public contract only lists status = 'open' postings — applicants,
  // shortlist status and internal notes never leave the ERP (see
  // JobPostingController::publicColumns()). An API failure renders the same
  // honest empty state as a genuinely empty list: from a visitor's
  // perspective "the ERP is unreachable" and "nothing is open right now"
  // both mean "nothing to show", and neither should ever look like an error.
  const result = await getJobPostings();
  const jobs = result.ok ? result.data : [];

  return (
    <>
      <PageHeader title="নিয়োগ বিজ্ঞপ্তি" description={description} />

      {jobs.length === 0 ? (
        <div className="empty-state">
          <p>বর্তমানে কোনো নিয়োগ বিজ্ঞপ্তি চলমান নেই</p>
          <p>নতুন সুযোগ প্রকাশিত হলে এই পাতায় দেখা যাবে। প্রশ্ন থাকলে যোগাযোগ করুন।</p>
          <a href={`mailto:${org.email}`} className="button button-outline">
            {org.email}
          </a>
        </div>
      ) : (
        <section className="content-section">
          <div className="card-grid cols-2">
            {jobs.map((job) => (
              <div key={job.slug} className="info-card">
                <span className="info-card-tag">{job.department ?? "নিয়োগ"}</span>
                <h3>{job.title}</h3>
                {job.summary && <p>{job.summary}</p>}
                <dl className="definition-list">
                  {job.employment_type && (
                    <div>
                      <dt>ধরন</dt>
                      <dd>{job.employment_type}</dd>
                    </div>
                  )}
                  {job.application_deadline && (
                    <div>
                      <dt>আবেদনের শেষ তারিখ</dt>
                      <dd>{job.application_deadline}</dd>
                    </div>
                  )}
                </dl>
                <a href={`mailto:${org.email}?subject=${encodeURIComponent(job.title)}`} className="button button-primary">
                  আবেদন করুন
                </a>
              </div>
            ))}
          </div>
        </section>
      )}
    </>
  );
}
