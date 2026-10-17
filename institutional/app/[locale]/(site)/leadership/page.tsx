import type { Metadata } from "next";
import LeadershipCard from "@/components/LeadershipCard";
import PageHeader from "@/components/PageHeader";
import { getLeadershipProfiles } from "@/lib/api/leadership";
import { getStrings, isLocale, type Locale } from "@/lib/i18n";
import { resolveLeader, splitFeatured } from "@/lib/leadership";
import { localizedMetadata } from "@/lib/social-meta";

/*
 * Leadership / Key Persons (2026-10-15): the institution's permanent core people — not the Committee, whose members belong
 * to a term (that is /committee). The page lists the profiles an admin has made ACTIVE, in the admin-defined order; the
 * featured ones are shown first as the executive leadership, the rest under "key persons", each group in the same
 * admin-defined order. Rendered on demand and cached by tag: an admin's change reaches it within seconds.
 */

export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }): Promise<Metadata> {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const t = getStrings(locale);

  const base = localizedMetadata({ locale, bnPath: "/leadership", title: t.leadership.title, description: t.leadership.description });
  // The page's own openGraph replaces the layout's wholesale: state the language of the share card here.
  const metadata: Metadata = { ...base, openGraph: { ...base.openGraph, locale: t.meta.ogLocale, alternateLocale: locale === "bn" ? ["en_US"] : ["bn_BD"] } };

  // An empty page is not worth indexing (nothing is published yet, or the ERP could not be reached): same page, kept out of search results.
  const result = await getLeadershipProfiles();
  return result.ok && result.data.length > 0 ? metadata : { ...metadata, robots: { index: false, follow: true } };
}

export default async function LeadershipPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const t = getStrings(locale);

  const result = await getLeadershipProfiles();
  const leaders = result.ok ? result.data.map((profile) => resolveLeader(profile, locale)) : [];
  const { featured, others } = splitFeatured(leaders);

  return (
    <>
      <PageHeader title={t.leadership.title} description={t.leadership.description} locale={locale} />

      {leaders.length === 0 ? (
        <section className="content-section">
          <div className="empty-state">
            <p>{t.leadership.emptyTitle}</p>
            <p>{t.leadership.emptyMessage}</p>
          </div>
        </section>
      ) : (
        <>
          {featured.length > 0 && (
            <section className="content-section" aria-labelledby="leadership-featured">
              <h2 id="leadership-featured">{t.leadership.featuredHeading}</h2>
              <div className="leader-grid is-featured">
                {featured.map((leader, index) => (
                  <LeadershipCard key={leader.slug} leader={leader} locale={locale} featured priority={index < 2} />
                ))}
              </div>
            </section>
          )}

          {others.length > 0 && (
            <section className="content-section" aria-labelledby="leadership-others">
              <h2 id="leadership-others">{featured.length > 0 ? t.leadership.othersHeading : t.leadership.title}</h2>
              <div className="leader-grid">
                {others.map((leader, index) => (
                  <LeadershipCard key={leader.slug} leader={leader} locale={locale} priority={featured.length === 0 && index < 3} />
                ))}
              </div>
            </section>
          )}
        </>
      )}
    </>
  );
}
