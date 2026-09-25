import Link from "next/link";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { org } from "@/lib/content";
import { localizedMetadata } from "@/lib/social-meta";
import { getCommittee } from "@/lib/api/committees";
import type { PublicCommitteeMember } from "@/lib/api/types";
import { getStrings, isLocale, type Locale } from "@/lib/i18n";
import { pickText } from "@/lib/i18n/pick";
import { committeeTypeLabel, committeeStatusLabel } from "@/lib/i18n/enums";
import { localizeHref } from "@/lib/i18n/paths";

// Committee lifecycles change on an admin's own schedule (a submission
// approved, a status transition) — genuinely dynamic content, so this route
// deliberately has no generateStaticParams. It renders on demand and relies
// on getCommittee's own ISR revalidate window, the same policy every other
// lib/api/*.ts getter in this app already uses.

const MONTH_LOCALE: Record<Locale, string> = { bn: "bn-BD", en: "en-US" };

function termLabel(termStart: string | null, termEnd: string | null, locale: Locale): string | null {
  if (!termStart) return null;
  const start = new Date(termStart).toLocaleDateString(MONTH_LOCALE[locale], { year: "numeric", month: "long" });
  if (!termEnd) return locale === "en" ? `Since ${start}` : `${start} থেকে চলমান`;
  const end = new Date(termEnd).toLocaleDateString(MONTH_LOCALE[locale], { year: "numeric", month: "long" });
  return `${start} – ${end}`;
}

export async function generateMetadata({ params }: { params: Promise<{ locale: string; slug: string }> }): Promise<Metadata> {
  const { locale: rawLocale, slug } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const result = await getCommittee(slug);
  if (!result.ok) return {};

  const name = pickText(locale, result.data.name, result.data.name_en);
  const description =
    locale === "en"
      ? `Member list and details for ${org.nameEn}'s ${name.text}.`
      : `${org.shortName}-এর ${result.data.name}-এর সদস্য তালিকা ও বিবরণ।`;

  return localizedMetadata({
    locale,
    bnPath: `/committee/${result.data.slug}`,
    title: name.text,
    description,
    hasTranslation: !name.isFallback,
  });
}

function MemberCard({ member, locale }: { member: PublicCommitteeMember; locale: Locale }) {
  const name = pickText(locale, member.name, member.name_en);
  const position = pickText(locale, member.position, member.position_en);
  return (
    <div className="info-card">
      {member.photo_url && (
        // eslint-disable-next-line @next/next/no-img-element -- external, admin-approved path on admin.provatferi.org's own public disk, not a build-time-known asset.
        <img
          src={member.photo_url}
          alt={name.text}
          style={{ width: 88, height: 88, borderRadius: "50%", objectFit: "cover", marginBottom: 12 }}
        />
      )}
      <span className="info-card-tag">{position.text}</span>
      <h3>{name.text}</h3>
      {member.bio && <p>{member.bio}</p>}
      {(member.facebook_url || member.linkedin_url || member.website_url) && (
        <p className="mt-2 flex flex-wrap gap-3 text-sm">
          {member.facebook_url && <a href={member.facebook_url} target="_blank" rel="noopener noreferrer">{locale === "en" ? "Facebook" : "ফেসবুক"}</a>}
          {member.linkedin_url && <a href={member.linkedin_url} target="_blank" rel="noopener noreferrer">{locale === "en" ? "LinkedIn" : "লিংকডইন"}</a>}
          {member.website_url && <a href={member.website_url} target="_blank" rel="noopener noreferrer">{locale === "en" ? "Website" : "ওয়েবসাইট"}</a>}
        </p>
      )}
    </div>
  );
}

export default async function CommitteeDetailPage({ params }: { params: Promise<{ locale: string; slug: string }> }) {
  const { locale: rawLocale, slug } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const t = getStrings(locale);
  const result = await getCommittee(slug);
  if (!result.ok) notFound();

  const committee = result.data;
  const name = pickText(locale, committee.name, committee.name_en);
  const description = committee.description ? pickText(locale, committee.description, committee.description_en) : null;
  const term = termLabel(committee.term_start, committee.term_end, locale);
  const typeLabel = committeeTypeLabel(committee.committee_type, locale, locale === "en" ? "Committee" : "কমিটি");
  const statusLabel = committeeStatusLabel(committee.status, locale);

  const breadcrumbData = {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [
      { "@type": "ListItem", position: 1, name: t.common.home, item: org.website },
      { "@type": "ListItem", position: 2, name: t.nav.aboutChildren["/committee"], item: `${org.website}${localizeHref("/committee", locale)}` },
      { "@type": "ListItem", position: 3, name: name.text },
    ],
  };

  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbData) }} />

      <div className="page-header">
        <nav className="breadcrumb" aria-label={t.common.breadcrumbAria}>
          <Link href={localizeHref("/", locale)}>{t.common.home}</Link>
          <span aria-hidden="true">/</span>
          <Link href={localizeHref("/committee", locale)}>{t.nav.aboutChildren["/committee"]}</Link>
          <span aria-hidden="true">/</span>
          <span aria-current="page">{name.text}</span>
        </nav>
        <h1>{name.text}</h1>
        <p>
          {typeLabel}
          {statusLabel ? ` · ${statusLabel}` : ""}
          {term ? ` · ${term}` : ""}
        </p>
        {name.isFallback && <p className="translation-note">{t.common.translationPending}</p>}
      </div>

      {description && (
        <section className="content-section">
          <p>{description.text}</p>
          {description.isFallback && <p className="translation-note">{t.common.translationPending}</p>}
        </section>
      )}

      <section className="content-section">
        <h2>{locale === "en" ? "Members" : "সদস্যবৃন্দ"}</h2>
        {committee.members.length > 0 ? (
          <div className="card-grid cols-2">
            {committee.members.map((member, i) => (
              <MemberCard key={`${member.name}-${i}`} member={member} locale={locale} />
            ))}
          </div>
        ) : (
          <p>{locale === "en" ? "The member list for this committee will be published soon." : "এই কমিটির সদস্য তালিকা শীঘ্রই প্রকাশিত হবে।"}</p>
        )}
      </section>

      <section className="content-section">
        <Link href={localizeHref("/committee", locale)} className="text-link">
          <span aria-hidden="true">←</span> {locale === "en" ? "View all committees" : "সব কমিটি দেখুন"}
        </Link>
      </section>
    </>
  );
}
