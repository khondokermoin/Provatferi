import Link from "next/link";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { org } from "@/lib/content";
import { localizedMetadata } from "@/lib/social-meta";
import { getActivitiesWithFallback } from "@/lib/api/activities";
import { getStrings, isLocale, type Locale } from "@/lib/i18n";
import { pickText, pickOptionalText } from "@/lib/i18n/pick";
import { localizeHref } from "@/lib/i18n/paths";

// Same source as the listing page and the sitemap (getActivitiesWithFallback)
// — deliberately not an independent fetch per slug, so this route never pre-
// renders (or 404s) a different set of slugs than /activities and
// sitemap.xml list. generateStaticParams cross-joins with [locale]'s own
// generateStaticParams automatically — no change needed here for that.
export async function generateStaticParams() {
  const { activities } = await getActivitiesWithFallback();
  return activities.map((activity) => ({ slug: activity.slug }));
}

async function findActivity(slug: string) {
  const { activities } = await getActivitiesWithFallback();
  return activities.find((activity) => activity.slug === slug);
}

export async function generateMetadata({ params }: { params: Promise<{ locale: string; slug: string }> }): Promise<Metadata> {
  const { locale: rawLocale, slug } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const activity = await findActivity(slug);
  if (!activity) return {};

  const title = pickText(locale, activity.title, activity.titleEn);
  const description =
    locale === "en"
      ? `${activity.date} — ${org.shortName}'s ${title.text}, held at ${activity.place}.`
      : `${activity.date} — ${activity.place}-এ অনুষ্ঠিত ${org.shortName}-এর ${activity.title}।`;

  return localizedMetadata({
    locale,
    bnPath: `/activities/${activity.slug}`,
    title: title.text,
    description,
    hasTranslation: !title.isFallback,
  });
}

export default async function ActivityDetailPage({ params }: { params: Promise<{ locale: string; slug: string }> }) {
  const { locale: rawLocale, slug } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const t = getStrings(locale);
  const activity = await findActivity(slug);
  if (!activity) notFound();

  const title = pickText(locale, activity.title, activity.titleEn);
  const summary = pickOptionalText(locale, activity.summary, activity.summaryEn);
  const outcomes = pickOptionalText(locale, activity.outcomes, activity.outcomesEn);
  const category = locale === "en" ? (activity.categoryEn ?? activity.category) : activity.category;

  const breadcrumbData = {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [
      { "@type": "ListItem", position: 1, name: t.common.home, item: org.website },
      { "@type": "ListItem", position: 2, name: t.nav.activities, item: `${org.website}${localizeHref("/activities", locale)}` },
      { "@type": "ListItem", position: 3, name: title.text },
    ],
  };

  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbData) }} />

      <div className="page-header">
        <nav className="breadcrumb" aria-label={t.common.breadcrumbAria}>
          <Link href={localizeHref("/", locale)}>{t.common.home}</Link>
          <span aria-hidden="true">/</span>
          <Link href={localizeHref("/activities", locale)}>{t.nav.activities}</Link>
          <span aria-hidden="true">/</span>
          <span aria-current="page">{title.text}</span>
        </nav>
        <h1>{title.text}</h1>
        <p>{activity.place}</p>
        {title.isFallback && <p className="translation-note">{t.common.translationPending}</p>}
      </div>

      <section className="content-section">
        <dl className="definition-list">
          <div>
            <dt>{t.common.category}</dt>
            <dd>{category}</dd>
          </div>
          <div>
            <dt>{t.common.date}</dt>
            <dd>{activity.date}</dd>
          </div>
          <div>
            <dt>{t.common.place}</dt>
            <dd>{activity.place}</dd>
          </div>
          {activity.participantCount !== null && (
            <div>
              <dt>{t.common.participants}</dt>
              <dd>{activity.participantCount}</dd>
            </div>
          )}
        </dl>
      </section>

      {summary && (
        <section className="content-section">
          <h2>{t.common.description}</h2>
          <p>{summary.text}</p>
          {summary.isFallback && <p className="translation-note">{t.common.translationPending}</p>}
        </section>
      )}

      {outcomes && (
        <section className="content-section">
          <h2>{t.common.outcomes}</h2>
          <p>{outcomes.text}</p>
          {outcomes.isFallback && <p className="translation-note">{t.common.translationPending}</p>}
        </section>
      )}

      <section className="content-section">
        <Link href={localizeHref("/activities", locale)} className="text-link">
          <span aria-hidden="true">←</span> {locale === "en" ? "View all activities" : "সব কার্যক্রম দেখুন"}
        </Link>
      </section>
    </>
  );
}
