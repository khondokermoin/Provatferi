import Link from "next/link";
import { socialMeta } from "@/lib/social-meta";
import type { Metadata } from "next";
import { notFound, permanentRedirect } from "next/navigation";
import { org } from "@/lib/content";
import { applicationWindowLabel, applyCtaLabel, communityCtaLabel, getJobPosting } from "@/lib/api/recruitment";
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
  // §12/§13: share_image_url already encodes the full priority server-side
  // (this posting's own upload, then its linked notice's share/cover image)
  // — omitting width/height here on purpose, since a linked notice's cover
  // image isn't guaranteed to be 1200x630 the way a dedicated upload is, and
  // an incorrect size hint is worse than none. socialMeta()'s own fallback
  // (the approved brand mark, with its real known dimensions) applies when
  // this is null.
  const image = job.share_image_url ? { url: job.share_image_url, alt: job.title } : undefined;

  return {
    title: job.title,
    description,
    alternates: { canonical: url },
    ...socialMeta({ title: `${job.title} | ${org.shortName}`, description, url, image }),
  };
}

export default async function RecruitmentDetailPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const result = await getJobPosting(slug);
  if (!result.ok) notFound();

  const job = result.data;
  // §3: the requested segment may be a retired slug that JobPostingController
  // resolved through history. job.slug is always the CURRENT one (present()
  // reads it straight off the model), so a mismatch here is exactly the
  // "old URL" case — a 308 keeps the visitor's link working forever while
  // telling search engines and browsers to remember the new address.
  if (job.slug !== slug) permanentRedirect(`/recruitment/${job.slug}`);
  const opening = formatBnDate(job.opening_date);

  const breadcrumbData = {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [
      { "@type": "ListItem", position: 1, name: "হোম", item: org.website },
      { "@type": "ListItem", position: 2, name: "নিয়োগ ও স্বেচ্ছাসেবী সুযোগ", item: `${org.website}/recruitment` },
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
            <Link href="/recruitment">নিয়োগ ও স্বেচ্ছাসেবী সুযোগ</Link>
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

              {/* §10: applying happens on the website form. The WhatsApp
                  group is offered afterwards, as a community channel. */}
              {job.accepts_applications && job.apply_path ? (
                <Link href={job.apply_path} className="button button-primary">
                  {applyCtaLabel(job.is_volunteer)} <span aria-hidden="true">→</span>
                </Link>
              ) : (
                <a href={`mailto:${org.email}?subject=${encodeURIComponent(job.title)}`} className="button button-primary">
                  আবেদন সংক্রান্ত যোগাযোগ
                </a>
              )}

              {job.notice_action && (
                <a className="button button-outline" href={job.notice_action.url} target="_blank" rel="noopener noreferrer">
                  {communityCtaLabel(job.notice_action.url, job.notice_action.label)} <span aria-hidden="true">↗</span>
                  <span className="sr-only"> (নতুন ট্যাবে খুলবে)</span>
                </a>
              )}

              {job.notice_slug && (
                <Link href={`/notices/${job.notice_slug}`} className="text-link">
                  এই সুযোগের অফিসিয়াল বিজ্ঞপ্তি দেখুন <span aria-hidden="true">→</span>
                </Link>
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
            <span aria-hidden="true">←</span> সব সুযোগ দেখুন
          </Link>
        </p>
      </article>
    </>
  );
}
