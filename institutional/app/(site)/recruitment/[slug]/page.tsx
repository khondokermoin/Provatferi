import Link from "next/link";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { org } from "@/lib/content";
import { applicationWindowLabel, getJobPosting } from "@/lib/api/recruitment";
import { formatBnDate } from "@/lib/format";
import { excerpt } from "@/lib/text-blocks";
import TextBlocks from "@/components/TextBlocks";

// Only open postings resolve (JobPostingController), so a posting that closes
// drops out of this route on the next revalidation instead of lingering.

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const result = await getJobPosting(slug);
  if (!result.ok) return {};

  const job = result.data;
  const description = job.summary ?? excerpt(job.description ?? job.title);
  const url = `/recruitment/${job.slug}`;

  return {
    title: job.title,
    description,
    alternates: { canonical: url },
    openGraph: { title: `${job.title} | ${org.shortName}`, description, url, images: [{ ...org.ogImage, alt: org.nameBn }] },
  };
}

export default async function RecruitmentDetailPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const result = await getJobPosting(slug);
  if (!result.ok) notFound();

  const job = result.data;
  const opening = formatBnDate(job.opening_date);

  const breadcrumbData = {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [
      { "@type": "ListItem", position: 1, name: "হোম", item: org.website },
      { "@type": "ListItem", position: 2, name: "নিয়োগ বিজ্ঞপ্তি", item: `${org.website}/recruitment` },
      { "@type": "ListItem", position: 3, name: job.title },
    ],
  };

  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbData) }} />

      <article className="notice-detail">
        <header className="page-header notice-detail-header">
          <nav className="breadcrumb" aria-label="ব্রেডক্রাম্ব">
            <Link href="/">হোম</Link>
            <span aria-hidden="true">/</span>
            <Link href="/recruitment">নিয়োগ বিজ্ঞপ্তি</Link>
            <span aria-hidden="true">/</span>
            <span aria-current="page">{job.title}</span>
          </nav>
          {job.employment_type_label && (
            <span className="notice-row-tags">
              <span className={`notice-type notice-type-${job.is_volunteer ? "people" : "official"}`}>{job.employment_type_label}</span>
            </span>
          )}
          <h1>{job.title}</h1>
          {job.summary && <p>{job.summary}</p>}
        </header>

        <div className="notice-detail-layout">
          <aside className="notice-aside" aria-label="সংক্ষিপ্ত তথ্য">
            <section className="notice-facts" aria-labelledby="job-facts-title">
              <h2 id="job-facts-title">সংক্ষিপ্ত তথ্য</h2>
              <dl className="definition-list">
                {job.employment_type_label && (
                  <div>
                    <dt>ধরন</dt>
                    <dd>{job.employment_type_label}</dd>
                  </div>
                )}
                {(job.organization_unit || job.department) && (
                  <div>
                    <dt>ইউনিট / বিভাগ</dt>
                    <dd>{[job.organization_unit, job.department].filter(Boolean).join(" — ")}</dd>
                  </div>
                )}
                <div>
                  <dt>আবেদনের সময়সীমা</dt>
                  <dd>{applicationWindowLabel(job, formatBnDate)}</dd>
                </div>
                {opening && (
                  <div>
                    <dt>আবেদন শুরু</dt>
                    <dd>{opening}</dd>
                  </div>
                )}
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

              {job.notice_slug ? (
                <Link href={`/notices/${job.notice_slug}`} className="button button-primary">
                  বিস্তারিত নোটিশ ও যোগদানের নির্দেশনা <span aria-hidden="true">→</span>
                </Link>
              ) : (
                <a href={`mailto:${org.email}?subject=${encodeURIComponent(job.title)}`} className="button button-primary">
                  আবেদন সংক্রান্ত যোগাযোগ
                </a>
              )}
            </section>
          </aside>

          <div className="notice-body-area">
            {job.description && (
              <section className="content-section">
                <h2>বিবরণ</h2>
                <TextBlocks text={job.description} />
              </section>
            )}
            {job.requirements && (
              <section className="content-section">
                <h2>যোগ্যতা</h2>
                <TextBlocks text={job.requirements} />
              </section>
            )}
          </div>
        </div>

        <p className="notice-back">
          <Link href="/recruitment" className="text-link">
            <span aria-hidden="true">←</span> সব নিয়োগ বিজ্ঞপ্তি
          </Link>
        </p>
      </article>
    </>
  );
}
