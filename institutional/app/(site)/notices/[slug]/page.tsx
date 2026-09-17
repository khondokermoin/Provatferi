import Link from "next/link";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { org } from "@/lib/content";
import { getNotice } from "@/lib/api/notices";
import { applicationWindowLabel, applyCtaLabel, communityCtaLabel } from "@/lib/api/recruitment";
import { canonicalNoticeUrl } from "@/lib/share";
import type { NoticeRecruitmentInfo } from "@/lib/api/types";
import { dhakaIsoDate, formatBnDate, formatFileSizeBn } from "@/lib/format";
import { excerpt } from "@/lib/text-blocks";
import { NoticeTags } from "@/components/NoticeList";
import ShareBar from "@/components/ShareBar";
import TextBlocks from "@/components/TextBlocks";

// A notice can be published, archived or edited at any time, so it renders on
// demand within the board's revalidation window — never pre-built from a
// slug list that could go stale.

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const result = await getNotice(slug);
  if (!result.ok) return {};

  const notice = result.data;
  const description = notice.summary ?? excerpt(notice.body);
  const url = `/notices/${notice.slug}`;

  return {
    title: notice.title,
    description,
    alternates: { canonical: url },
    openGraph: {
      type: "article",
      title: `${notice.title} | ${org.shortName}`,
      description,
      url,
      publishedTime: notice.published_at,
      modifiedTime: notice.updated_at ?? undefined,
      images: [notice.cover_image_url ? { url: notice.cover_image_url, alt: notice.title } : { ...org.ogImage, alt: org.nameBn }],
    },
    // §13: a shared notice must render as a card on X too, not as a bare
    // link. The image is the notice's own cover when it has one, and the
    // approved brand asset otherwise — never a generated one.
    twitter: {
      card: "summary_large_image",
      title: `${notice.title} | ${org.shortName}`,
      description,
      images: [notice.cover_image_url ?? org.ogImage.url],
    },
  };
}

function RecruitmentFacts({ recruitment }: { recruitment: NoticeRecruitmentInfo }) {
  const opening = formatBnDate(recruitment.opening_date);

  return (
    <section className="notice-facts" aria-labelledby="notice-facts-title">
      <h2 id="notice-facts-title">আবেদন সংক্রান্ত তথ্য</h2>
      {/* §17: says plainly why the same opportunity appears in two places. */}
      <p className="notice-facts-relation">
        এই বিজ্ঞপ্তিটি একটি {recruitment.is_volunteer ? "স্বেচ্ছাসেবী" : "নিয়োগ"} সুযোগের সঙ্গে সম্পর্কিত।
      </p>
      <dl className="definition-list">
        {recruitment.employment_type_label && (
          <div>
            <dt>ধরন</dt>
            <dd>{recruitment.employment_type_label}</dd>
          </div>
        )}
        <div>
          <dt>আবেদনের সময়সীমা</dt>
          <dd>{applicationWindowLabel(recruitment, formatBnDate, recruitment.is_open)}</dd>
        </div>
        {opening && (
          <div>
            <dt>আবেদন শুরু</dt>
            <dd>{opening}</dd>
          </div>
        )}
        {recruitment.is_volunteer ? (
          <div>
            <dt>পারিশ্রমিক</dt>
            <dd>{recruitment.volunteer_note}</dd>
          </div>
        ) : (
          recruitment.salary_range && (
            <div>
              <dt>বেতন</dt>
              <dd>{recruitment.salary_range}</dd>
            </div>
          )
        )}
      </dl>
      {recruitment.slug && (
        <Link href={`/recruitment/${recruitment.slug}`} className="text-link">
          বিস্তারিত দেখুন / আবেদন সংক্রান্ত তথ্য <span aria-hidden="true">→</span>
        </Link>
      )}
    </section>
  );
}

export default async function NoticeDetailPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const result = await getNotice(slug);
  if (!result.ok) notFound();

  const notice = result.data;
  const published = formatBnDate(notice.published_at);
  const expires = formatBnDate(notice.expires_at);
  const attachmentSize = formatFileSizeBn(notice.attachment?.size);
  // The ERP resolves the form's route from the posting; nothing here builds it.
  const applyPath = notice.recruitment?.accepts_applications ? notice.recruitment.apply_path : null;
  const shareUrl = canonicalNoticeUrl(org.website, notice.slug);

  const breadcrumbData = {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [
      { "@type": "ListItem", position: 1, name: "হোম", item: org.website },
      { "@type": "ListItem", position: 2, name: "নোটিশ বোর্ড", item: `${org.website}/notices` },
      { "@type": "ListItem", position: 3, name: notice.title },
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
            <Link href="/notices">নোটিশ বোর্ড</Link>
            <span aria-hidden="true">/</span>
            <span aria-current="page">{notice.title}</span>
          </nav>
          <NoticeTags notice={notice} />
          <h1>{notice.title}</h1>
          <dl className="notice-meta">
            <div>
              <dt>প্রকাশের তারিখ</dt>
              <dd>
                <time dateTime={dhakaIsoDate(notice.published_at) ?? undefined}>{published}</time>
              </dd>
            </div>
            {notice.organization_unit && (
              <div>
                <dt>প্রকাশক</dt>
                <dd>{notice.organization_unit}</dd>
              </div>
            )}
            {expires && (
              <div>
                <dt>{notice.is_expired ? "মেয়াদ শেষ হয়েছে" : "মেয়াদ"}</dt>
                <dd>
                  <time dateTime={dhakaIsoDate(notice.expires_at) ?? undefined}>{expires}</time>
                </dd>
              </div>
            )}
          </dl>
        </header>

        {notice.is_archived && (
          <div className="callout notice-archive-note">
            <p>এটি একটি আর্কাইভ করা নোটিশ — প্রাতিষ্ঠানিক ইতিহাস হিসেবে সংরক্ষিত।</p>
          </div>
        )}

        <div className="notice-detail-layout">
          <div className="notice-lead-area">
            {notice.summary && <p className="notice-lead">{notice.summary}</p>}
            {notice.cover_image_url && (
              // eslint-disable-next-line @next/next/no-img-element -- streamed from admin.provatferi.org only while the notice is public.
              <img className="notice-cover" src={notice.cover_image_url} alt={notice.title} loading="lazy" />
            )}
          </div>

          {(notice.action || notice.recruitment) && (
            <aside className="notice-aside" aria-label="পরবর্তী পদক্ষেপ">
              {(applyPath || notice.action) && (
                <div className="notice-cta">
                  <p className="notice-cta-title">পরবর্তী পদক্ষেপ</p>

                  {/* §9: the website form is the primary action. WhatsApp
                      drops to secondary whenever the form is open. */}
                  {applyPath && (
                    <Link className="button button-primary" href={applyPath}>
                      {applyCtaLabel(notice.recruitment?.is_volunteer ?? false)} <span aria-hidden="true">→</span>
                    </Link>
                  )}

                  {notice.action && (
                    <a
                      className={`button ${applyPath ? "button-outline" : "button-primary"}`}
                      href={notice.action.url}
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      {applyPath ? communityCtaLabel(notice.action.url, notice.action.label) : notice.action.label}{" "}
                      <span aria-hidden="true">↗</span>
                      <span className="sr-only"> (নতুন ট্যাবে খুলবে)</span>
                    </a>
                  )}

                  {applyPath && notice.action && (
                    <p className="notice-cta-note">
                      আবেদন ওয়েবসাইটের ফরমেই জমা হবে। গ্রুপটি শুধু যোগাযোগ ও আপডেটের জন্য — সেখানে ব্যক্তিগত তথ্য পাঠানোর প্রয়োজন নেই।
                    </p>
                  )}

                  {notice.action && (
                    <details className="notice-cta-link">
                      <summary>লিংকটি দেখুন বা কপি করুন</summary>
                      <code>{notice.action.url}</code>
                    </details>
                  )}
                </div>
              )}
              {notice.recruitment && <RecruitmentFacts recruitment={notice.recruitment} />}
            </aside>
          )}

          <div className="notice-body-area">
            <TextBlocks text={notice.body} />
            {notice.attachment && (
              <a className="button button-outline notice-download" href={notice.attachment.url}>
                সংযুক্তি ডাউনলোড করুন{attachmentSize ? ` (PDF, ${attachmentSize})` : " (PDF)"}
              </a>
            )}
          </div>
        </div>

        <ShareBar url={shareUrl} title={notice.title} />

        <p className="notice-back">
          <Link href="/notices" className="text-link">
            <span aria-hidden="true">←</span> সব নোটিশ দেখুন
          </Link>
        </p>
      </article>
    </>
  );
}
