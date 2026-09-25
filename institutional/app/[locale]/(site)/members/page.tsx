import type { Metadata } from "next";
import { localizedMetadata } from "@/lib/social-meta";
import Link from "next/link";
import { org } from "@/lib/content";
import { getMemberDirectory } from "@/lib/api/member-directory";
import PageHeader from "@/components/PageHeader";
import { isLocale, type Locale } from "@/lib/i18n";
import { localizeHref } from "@/lib/i18n/paths";

const DESCRIPTIONS: Record<Locale, string> = {
  bn: `${org.shortName}-এর সদস্যদের মধ্যে যারা তাদের প্রোফাইল প্রকাশ করতে সম্মত হয়েছেন।`,
  en: `${org.nameEn} members who have chosen to make their profile public.`,
};
const TITLES: Record<Locale, string> = { bn: "সদস্য পরিচিতি", en: "Member Directory" };

export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }): Promise<Metadata> {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  return localizedMetadata({ locale, bnPath: "/members", title: TITLES[locale], description: DESCRIPTIONS[locale] });
}

export default async function MembersDirectoryPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const result = await getMemberDirectory();
  const members = result.ok ? result.data : [];

  return (
    <>
      <PageHeader title={TITLES[locale]} description={DESCRIPTIONS[locale]} locale={locale} />

      <section className="content-section">
        {members.length > 0 ? (
          <div className="card-grid cols-2">
            {members.map((member) => (
              <Link key={member.public_slug ?? member.name} href={localizeHref(`/members/${member.public_slug}`, locale)} className="info-card" style={{ display: "block" }}>
                {member.photo_url && (
                  // eslint-disable-next-line @next/next/no-img-element -- admin-approved path on admin.provatferi.org's public disk.
                  <img
                    src={member.photo_url}
                    alt={member.name}
                    style={{ width: 72, height: 72, borderRadius: "50%", objectFit: "cover", marginBottom: 12 }}
                  />
                )}
                <span className="info-card-tag">{member.profession ?? (locale === "en" ? "Member" : "সদস্য")}</span>
                <h3>{member.name}</h3>
              </Link>
            ))}
          </div>
        ) : (
          <p>{locale === "en" ? "No member has made their profile public yet." : "এখনো কোনো সদস্য তাদের প্রোফাইল পাবলিকভাবে প্রকাশ করেননি।"}</p>
        )}
      </section>
    </>
  );
}
