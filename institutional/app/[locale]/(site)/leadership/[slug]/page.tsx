import type { Metadata } from "next";
import { notFound, permanentRedirect } from "next/navigation";
import LeaderPhoto from "@/components/LeaderPhoto";
import Link from "@/components/SiteLink";
import SocialIcon from "@/components/SocialIcon";
import SocialLinks from "@/components/SocialLinks";
import TextBlocks from "@/components/TextBlocks";
import { getLeadershipProfile } from "@/lib/api/leadership";
import { org } from "@/lib/content";
import { toBnDigits } from "@/lib/format";
import { getStrings, isLocale, type Locale } from "@/lib/i18n";
import { localizeHref } from "@/lib/i18n/paths";
import { leaderDescription, resolveLeaderDetail } from "@/lib/leadership";
import { localizedMetadata } from "@/lib/social-meta";

/*
 * One Leadership / Key Persons profile (2026-10-15). Only an ACTIVE profile exists here: an inactive, archived or unknown
 * one is a 404, exactly as if it had never been created. A profile reached through an address it used to have is answered
 * with a permanent redirect to its current address, so an old shared link keeps working.
 *
 * Everything on the page is the admin's own text and the admin's own data, shown as plain text (TextBlocks makes paragraphs,
 * bullets and plain links; nothing is ever HTML). Nothing is claimed that the profile does not say: the description of the
 * page is the short biography or, failing that, the designation — both stored facts.
 */

export async function generateMetadata({ params }: { params: Promise<{ locale: string; slug: string }> }): Promise<Metadata> {
  const { locale: rawLocale, slug } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const result = await getLeadershipProfile(slug);
  if (!result.ok) return {};

  const leader = resolveLeaderDetail(result.data, locale);
  const t = getStrings(locale);

  const metadata = localizedMetadata({
    locale,
    // The canonical (Bangla) path is always the CURRENT address, even when this request came through an old one.
    bnPath: `/leadership/${result.data.slug}`,
    title: `${leader.name} — ${leader.designation}`,
    description: leaderDescription(leader, locale === "en" ? org.nameEn : org.nameBn),
    // An English page "exists" only when the English name and designation were both entered; otherwise it is a
    // fallback render whose canonical points at the Bangla page and which hreflang does not advertise.
    hasTranslation: leader.hasEnglish,
    image: leader.photo ? { url: leader.photo.src, width: leader.photo.width, height: leader.photo.height, alt: t.leadership.photoAlt(leader.name) } : undefined,
  });

  // A page's own openGraph replaces the layout's wholesale, so the language of the share card is stated here: the page's own
  // locale, and the other one only when the other language page really exists.
  return {
    ...metadata,
    openGraph: { ...metadata.openGraph, locale: t.meta.ogLocale, ...(leader.hasEnglish ? { alternateLocale: locale === "bn" ? ["en_US"] : ["bn_BD"] } : {}) },
  };
}

export default async function LeadershipProfilePage({ params }: { params: Promise<{ locale: string; slug: string }> }) {
  const { locale: rawLocale, slug } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const t = getStrings(locale);

  const result = await getLeadershipProfile(slug);
  if (!result.ok) notFound();

  // The API resolved an address this profile used to have: send the visitor (and search engines) to the current one.
  if (result.data.slug !== slug) permanentRedirect(localizeHref(`/leadership/${result.data.slug}`, locale));

  const leader = resolveLeaderDetail(result.data, locale);
  const hasContact = leader.email !== null || leader.phone !== null || leader.website !== null || leader.social.length > 0;
  const body = leader.fullBio ?? leader.shortBio;

  return (
    <>
      <div className="page-header leader-header">
        <nav className="breadcrumb" aria-label={t.common.breadcrumbAria}>
          <Link href={localizeHref("/", locale)}>{t.common.home}</Link>
          <span aria-hidden="true">/</span>
          <Link href={localizeHref("/leadership", locale)}>{t.leadership.title}</Link>
          <span aria-hidden="true">/</span>
          <span aria-current="page">{leader.name}</span>
        </nav>
        <h1>{leader.name}</h1>
        <p>{leader.designation}</p>
      </div>

      <section className="content-section leader-profile" aria-label={leader.name}>
        <div className="leader-profile-grid">
          <aside className="leader-profile-aside">
            <LeaderPhoto leader={leader} alt={t.leadership.photoAlt(leader.name)} variant="page" />

            {(leader.expertise !== null || leader.sinceYear !== null) && (
              <ul className="leader-facts">
                {leader.expertise !== null && (
                  <li>
                    <strong>{t.leadership.expertise}:</strong> {leader.expertise}
                  </li>
                )}
                {leader.sinceYear !== null && (
                  <li>{t.leadership.associatedSince(locale === "bn" ? toBnDigits(leader.sinceYear) : String(leader.sinceYear))}</li>
                )}
              </ul>
            )}

            {hasContact && (
              <section className="leader-contact" aria-labelledby="leader-contact-title">
                <h2 id="leader-contact-title">{t.leadership.contactHeading}</h2>
                {(leader.email !== null || leader.phone !== null || leader.website !== null) && (
                  <ul className="leader-contact-list">
                    {leader.email !== null && (
                      <li>
                        <a href={`mailto:${leader.email}`}>
                          <SocialIcon platform="mail" size={18} />
                          <span>
                            <span className="sr-only">{t.leadership.email}: </span>
                            {leader.email}
                          </span>
                        </a>
                      </li>
                    )}
                    {leader.phone !== null && leader.phoneHref !== null && (
                      <li>
                        <a href={leader.phoneHref}>
                          <SocialIcon platform="phone" size={18} />
                          <span>
                            <span className="sr-only">{t.leadership.phone}: </span>
                            {leader.phone}
                          </span>
                        </a>
                      </li>
                    )}
                    {leader.website !== null && (
                      <li>
                        <a href={leader.website} target="_blank" rel="noopener noreferrer">
                          <SocialIcon platform="globe" size={18} />
                          <span>
                            <span className="sr-only">{t.leadership.website}: </span>
                            {leader.website.replace(/^https:\/\//, "")}
                            <span className="sr-only"> ({t.common.opensInNewTab})</span>
                          </span>
                        </a>
                      </li>
                    )}
                  </ul>
                )}
                <SocialLinks links={leader.social} locale={locale} variant="profile" />
              </section>
            )}
          </aside>

          <div className="leader-profile-main">
            {leader.quote !== null && (
              <blockquote className="leader-quote">
                <p>{leader.quote}</p>
              </blockquote>
            )}
            {body !== null && <TextBlocks text={body} className="leader-bio-text" />}
            {leader.usesFallback && <p className="translation-note">{t.common.translationPending}</p>}
          </div>
        </div>

        <p className="leader-back">
          <Link href={localizeHref("/leadership", locale)} className="text-link">
            <span className="icon-back" aria-hidden="true">
              <SocialIcon platform="arrow-right" size={16} inline />
            </span>
            {t.leadership.backToList}
          </Link>
        </p>
      </section>
    </>
  );
}
