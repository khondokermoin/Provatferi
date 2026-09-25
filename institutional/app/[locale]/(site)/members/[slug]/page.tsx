import Link from "next/link";
import { localizedMetadata } from "@/lib/social-meta";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { org } from "@/lib/content";
import { getMemberProfile } from "@/lib/api/member-directory";
import { getStrings, isLocale, type Locale } from "@/lib/i18n";
import { localizeHref } from "@/lib/i18n/paths";

// A member's directory entry is genuinely dynamic (visibility can be
// switched off, an edit can change what's live) — no generateStaticParams,
// rendered on demand and revalidated on the same ISR window as the list.
// A member's own name/bio have no `_en` field in the ERP (personal
// information, not editorial content to translate) — they render identically
// on both locales.

export async function generateMetadata({ params }: { params: Promise<{ locale: string; slug: string }> }): Promise<Metadata> {
  const { locale: rawLocale, slug } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const result = await getMemberProfile(slug);
  if (!result.ok) return {};

  const description = locale === "en" ? `${result.data.name} — a member of ${org.nameEn}.` : `${result.data.name} — ${org.shortName}-এর একজন সদস্য।`;
  return localizedMetadata({ locale, bnPath: `/members/${slug}`, title: result.data.name, description });
}

export default async function MemberProfilePage({ params }: { params: Promise<{ locale: string; slug: string }> }) {
  const { locale: rawLocale, slug } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const t = getStrings(locale);
  const result = await getMemberProfile(slug);
  if (!result.ok) notFound();

  const member = result.data;

  return (
    <>
      <div className="page-header">
        <nav className="breadcrumb" aria-label={t.common.breadcrumbAria}>
          <Link href={localizeHref("/", locale)}>{t.common.home}</Link>
          <span aria-hidden="true">/</span>
          <Link href={localizeHref("/members", locale)}>{t.footer.exploreLinks["/members"]}</Link>
          <span aria-hidden="true">/</span>
          <span aria-current="page">{member.name}</span>
        </nav>
        <h1>{member.name}</h1>
        {member.profession && <p>{member.profession}</p>}
      </div>

      <section className="content-section">
        <div className="info-card">
          {member.photo_url && (
            // eslint-disable-next-line @next/next/no-img-element -- admin-approved path on admin.provatferi.org's public disk.
            <img
              src={member.photo_url}
              alt={member.name}
              style={{ width: 120, height: 120, borderRadius: "50%", objectFit: "cover", marginBottom: 16 }}
            />
          )}
          {member.bio && <p>{member.bio}</p>}
          {(member.facebook_url || member.linkedin_url || member.website_url) && (
            <p className="mt-2 flex flex-wrap gap-3 text-sm">
              {member.facebook_url && <a href={member.facebook_url} target="_blank" rel="noopener noreferrer">{locale === "en" ? "Facebook" : "ফেসবুক"}</a>}
              {member.linkedin_url && <a href={member.linkedin_url} target="_blank" rel="noopener noreferrer">{locale === "en" ? "LinkedIn" : "লিংকডইন"}</a>}
              {member.website_url && <a href={member.website_url} target="_blank" rel="noopener noreferrer">{locale === "en" ? "Website" : "ওয়েবসাইট"}</a>}
            </p>
          )}
        </div>
      </section>

      <section className="content-section">
        <Link href={localizeHref("/members", locale)} className="text-link">
          <span aria-hidden="true">←</span> {locale === "en" ? "View all members" : "সব সদস্য দেখুন"}
        </Link>
      </section>
    </>
  );
}
