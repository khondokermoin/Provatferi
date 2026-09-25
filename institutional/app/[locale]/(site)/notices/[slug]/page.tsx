import Link from "next/link";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { org } from "@/lib/content";
import { getNotice } from "@/lib/api/notices";
import { applicationWindowLabel, applyCtaLabel, communityCtaLabel } from "@/lib/api/recruitment";
import { localeAlternates } from "@/lib/social-meta";
import { canonicalNoticeUrl } from "@/lib/share";
import type { NoticeRecruitmentInfo } from "@/lib/api/types";
import { dhakaIsoDate, formatDate, formatFileSizeBn } from "@/lib/format";
import { excerpt } from "@/lib/text-blocks";
import { NoticeTags } from "@/components/NoticeList";
import ShareBar from "@/components/ShareBar";
import TextBlocks from "@/components/TextBlocks";
import { getStrings, isLocale, type Locale } from "@/lib/i18n";
import { pickText, pickOptionalText } from "@/lib/i18n/pick";
import { employmentTypeLabel } from "@/lib/i18n/enums";
import { localizeHref } from "@/lib/i18n/paths";

// A notice can be published, archived or edited at any time, so it renders on
// demand within the board's revalidation window — never pre-built from a
// slug list that could go stale.

export async function generateMetadata({ params }: { params: Promise<{ locale: string; slug: string }> }): Promise<Metadata> {
  const { locale: rawLocale, slug } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const result = await getNotice(slug);
  if (!result.ok) return {};

  const notice = result.data;
  const title = pickText(locale, notice.title, notice.title_en);
  const summary = pickOptionalText(locale, notice.summary, notice.summary_en);
  const description = summary?.text ?? excerpt(notice.body);
  const url = localizeHref(`/notices/${notice.slug}`, locale);
  // §12/§13 priority: the DEDICATED share image (admin-instructed 1200x630)
  // first, since it is sized for exactly how a link preview renders; the
  // in-page cover image next, whatever its real aspect ratio; the approved
  // brand mark last — og:image is therefore never empty.
  const shareImageUrl = notice.share_image_url ?? notice.cover_image_url;

  return {
    title: title.text,
    description,
    alternates: localeAlternates(locale, `/notices/${notice.slug}`, !title.isFallback),
    openGraph: {
      type: "article",
      title: `${title.text} | ${org.shortName}`,
      description,
      url,
      publishedTime: notice.published_at,
      modifiedTime: notice.updated_at ?? undefined,
      images: [shareImageUrl ? { url: shareImageUrl, alt: title.text } : { ...org.ogImage, alt: org.nameBn }],
    },
    // §13: a shared notice must render as a card on X too, not as a bare
    // link. The image is the notice's own (share, then cover) when it has
    // one, and the approved brand asset otherwise — never a generated one.
    twitter: {
      card: "summary_large_image",
      title: `${title.text} | ${org.shortName}`,
      description,
      images: [shareImageUrl ?? org.ogImage.url],
    },
  };
}

function RecruitmentFacts({ recruitment, locale }: { recruitment: NoticeRecruitmentInfo; locale: Locale }) {
  const t = getStrings(locale);
  const opening = formatDate(recruitment.opening_date, locale);
  const employmentType = employmentTypeLabel(null, recruitment.employment_type_label, locale);

  return (
    <section className="notice-facts" aria-labelledby="notice-facts-title">
      <h2 id="notice-facts-title">{locale === "en" ? "Application Details" : "আবেদন সংক্রান্ত তথ্য"}</h2>
      {/* §17: says plainly why the same opportunity appears in two places. */}
      <p className="notice-facts-relation">
        {locale === "en" ? (
          <>This notice relates to a {recruitment.is_volunteer ? "volunteer" : "recruitment"} opportunity.</>
        ) : (
          <>এই বিজ্ঞপ্তিটি একটি {recruitment.is_volunteer ? "স্বেচ্ছাসেবী" : "নিয়োগ"} সুযোগের সঙ্গে সম্পর্কিত।</>
        )}
      </p>
      <dl className="definition-list">
        {employmentType && (
          <div>
            <dt>{t.common.type}</dt>
            <dd>{employmentType}</dd>
          </div>
        )}
        <div>
          <dt>{locale === "en" ? "Application Window" : "আবেদনের সময়সীমা"}</dt>
          <dd>{applicationWindowLabel(recruitment, (v) => formatDate(v, locale), recruitment.is_open, locale)}</dd>
        </div>
        {opening && (
          <div>
            <dt>{t.common.startDate}</dt>
            <dd>{opening}</dd>
          </div>
        )}
        {recruitment.is_volunteer ? (
          <div>
            <dt>{t.common.stipend}</dt>
            <dd>{recruitment.volunteer_note}</dd>
          </div>
        ) : (
          recruitment.salary_range && (
            <div>
              <dt>{t.common.salary}</dt>
              <dd>{recruitment.salary_range}</dd>
            </div>
          )
        )}
      </dl>
      {recruitment.slug && (
        <Link href={localizeHref(`/recruitment/${recruitment.slug}`, locale)} className="text-link">
          {t.common.viewDetails} {locale === "en" ? "/ application details" : "/ আবেদন সংক্রান্ত তথ্য"} <span aria-hidden="true">→</span>
        </Link>
      )}
    </section>
  );
}

export default async function NoticeDetailPage({ params }: { params: Promise<{ locale: string; slug: string }> }) {
  const { locale: rawLocale, slug } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const t = getStrings(locale);
  const result = await getNotice(slug);
  if (!result.ok) notFound();

  const notice = result.data;
  const title = pickText(locale, notice.title, notice.title_en);
  const summary = pickOptionalText(locale, notice.summary, notice.summary_en);
  const body = pickText(locale, notice.body, notice.body_en);
  const organizationUnit = pickOptionalText(locale, notice.organization_unit, notice.organization_unit_en);
  const action = notice.action
    ? { ...notice.action, label: pickText(locale, notice.action.label, notice.action.label_en) }
    : null;

  const published = formatDate(notice.published_at, locale);
  const expires = formatDate(notice.expires_at, locale);
  const attachmentSize = formatFileSizeBn(notice.attachment?.size);
  // The ERP resolves the form's route from the posting; nothing here builds it.
  const applyPath = notice.recruitment?.accepts_applications ? localizeHref(notice.recruitment.apply_path ?? "", locale) : null;
  const shareUrl = canonicalNoticeUrl(org.website, notice.slug, locale);

  const breadcrumbData = {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [
      { "@type": "ListItem", position: 1, name: t.common.home, item: org.website },
      { "@type": "ListItem", position: 2, name: t.nav.notices, item: `${org.website}${localizeHref("/notices", locale)}` },
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
            <Link href={localizeHref("/notices", locale)}>{t.nav.notices}</Link>
            <span aria-hidden="true">/</span>
            <span aria-current="page">{title.text}</span>
          </nav>
          <NoticeTags notice={notice} locale={locale} />
          <h1>{title.text}</h1>
          {title.isFallback && <p className="translation-note">{t.common.translationPending}</p>}
          <dl className="notice-meta">
            <div>
              <dt>{t.common.publishedOn}</dt>
              <dd>
                <time dateTime={dhakaIsoDate(notice.published_at) ?? undefined}>{published}</time>
              </dd>
            </div>
            {organizationUnit && (
              <div>
                <dt>{t.common.publisher}</dt>
                <dd>{organizationUnit.text}</dd>
              </div>
            )}
            {expires && (
              <div>
                <dt>{notice.is_expired ? t.common.expired : t.common.expiresOn}</dt>
                <dd>
                  <time dateTime={dhakaIsoDate(notice.expires_at) ?? undefined}>{expires}</time>
                </dd>
              </div>
            )}
          </dl>
        </header>

        {notice.is_archived && (
          <div className="callout notice-archive-note">
            <p>{t.common.archivedNotice}</p>
          </div>
        )}

        <div className="notice-detail-layout">
          <div className="notice-lead-area">
            {summary && (
              <>
                <p className="notice-lead">{summary.text}</p>
                {summary.isFallback && <p className="translation-note">{t.common.translationPending}</p>}
              </>
            )}
            {notice.cover_image_url && (
              // eslint-disable-next-line @next/next/no-img-element -- streamed from admin.provatferi.org only while the notice is public.
              <img className="notice-cover" src={notice.cover_image_url} alt={title.text} loading="lazy" />
            )}
          </div>

          {(action || notice.recruitment) && (
            <aside className="notice-aside" aria-label={locale === "en" ? "Next steps" : "পরবর্তী পদক্ষেপ"}>
              {(applyPath || action) && (
                <div className="notice-cta">
                  <p className="notice-cta-title">{locale === "en" ? "Next Steps" : "পরবর্তী পদক্ষেপ"}</p>

                  {/* §9: the website form is the primary action. WhatsApp
                      drops to secondary whenever the form is open. */}
                  {applyPath && (
                    <Link className="button button-primary" href={applyPath}>
                      {applyCtaLabel(notice.recruitment?.is_volunteer ?? false, locale)} <span aria-hidden="true">→</span>
                    </Link>
                  )}

                  {action && (
                    <a
                      className={`button ${applyPath ? "button-outline" : "button-primary"}`}
                      href={action.url}
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      {applyPath ? communityCtaLabel(action.url, action.label.text, locale) : action.label.text}{" "}
                      <span aria-hidden="true">↗</span>
                      <span className="sr-only"> ({t.common.opensInNewTab})</span>
                    </a>
                  )}

                  {applyPath && action && (
                    <p className="notice-cta-note">
                      {locale === "en"
                        ? "Applications are submitted through the website form. The group is only for communication and updates — no personal information needs to be sent there."
                        : "আবেদন ওয়েবসাইটের ফরমেই জমা হবে। গ্রুপটি শুধু যোগাযোগ ও আপডেটের জন্য — সেখানে ব্যক্তিগত তথ্য পাঠানোর প্রয়োজন নেই।"}
                    </p>
                  )}

                  {action && (
                    <details className="notice-cta-link">
                      <summary>{locale === "en" ? "View or copy the link" : "লিংকটি দেখুন বা কপি করুন"}</summary>
                      <code>{action.url}</code>
                    </details>
                  )}
                </div>
              )}
              {notice.recruitment && <RecruitmentFacts recruitment={notice.recruitment} locale={locale} />}
            </aside>
          )}

          <div className="notice-body-area">
            <TextBlocks text={body.text} />
            {body.isFallback && <p className="translation-note">{t.common.translationPending}</p>}
            {notice.attachment && (
              <a className="button button-outline notice-download" href={notice.attachment.url}>
                {t.common.downloadAttachment}{attachmentSize ? ` (PDF, ${attachmentSize})` : " (PDF)"}
              </a>
            )}
          </div>
        </div>

        <ShareBar url={shareUrl} title={title.text} locale={locale} />

        <p className="notice-back">
          <Link href={localizeHref("/notices", locale)} className="text-link">
            <span aria-hidden="true">←</span> {locale === "en" ? "View all notices" : "সব নোটিশ দেখুন"}
          </Link>
        </p>
      </article>
    </>
  );
}
