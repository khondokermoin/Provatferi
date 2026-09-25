import type { Metadata } from "next";
import { localizedMetadata } from "@/lib/social-meta";
import Link from "next/link";
import { org } from "@/lib/content";
import { getCommittees } from "@/lib/api/committees";
import type { PublicCommitteeSummary } from "@/lib/api/types";
import PageHeader from "@/components/PageHeader";
import { isLocale, type Locale } from "@/lib/i18n";
import { pickText } from "@/lib/i18n/pick";
import { committeeTypeLabel } from "@/lib/i18n/enums";
import { localizeHref } from "@/lib/i18n/paths";

const DESCRIPTIONS: Record<Locale, string> = {
  bn: `${org.shortName}-এর বর্তমান, আসন্ন ও পূর্ববর্তী কমিটির তালিকা।`,
  en: `A list of ${org.nameEn}'s current, upcoming and past committees.`,
};
const TITLES: Record<Locale, string> = { bn: "কমিটি", en: "Committees" };
const MONTH_LOCALE: Record<Locale, string> = { bn: "bn-BD", en: "en-US" };

function termLabel(committee: PublicCommitteeSummary, locale: Locale): string {
  if (!committee.term_start) return "";
  const start = new Date(committee.term_start).toLocaleDateString(MONTH_LOCALE[locale], { year: "numeric", month: "long" });
  if (!committee.term_end) return locale === "en" ? `Since ${start}` : `${start} থেকে চলমান`;
  const end = new Date(committee.term_end).toLocaleDateString(MONTH_LOCALE[locale], { year: "numeric", month: "long" });
  return `${start} – ${end}`;
}

export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }): Promise<Metadata> {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  return localizedMetadata({ locale, bnPath: "/committee", title: TITLES[locale], description: DESCRIPTIONS[locale] });
}

function CommitteeCard({ committee, locale }: { committee: PublicCommitteeSummary; locale: Locale }) {
  const name = pickText(locale, committee.name, committee.name_en);
  const term = termLabel(committee, locale);
  return (
    <Link href={localizeHref(`/committee/${committee.slug}`, locale)} className="info-card" style={{ display: "block" }}>
      <span className="info-card-tag">{committeeTypeLabel(committee.committee_type, locale, locale === "en" ? "Committee" : "কমিটি")}</span>
      <h3>{name.text}</h3>
      {term && <p>{term}</p>}
    </Link>
  );
}

export default async function CommitteePage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const result = await getCommittees();
  const data = result.ok ? result.data : { current: null, upcoming: [], previous: [] };

  return (
    <>
      <PageHeader title={TITLES[locale]} description={DESCRIPTIONS[locale]} locale={locale} />

      {data.current && (
        <section className="content-section">
          <h2>{locale === "en" ? "Current Committee" : "বর্তমান কমিটি"}</h2>
          <div className="leadership-spotlight">
            <Link href={localizeHref(`/committee/${data.current.slug}`, locale)} className="leadership-card" style={{ textDecoration: "none" }}>
              <div>
                <span className="info-card-tag">{committeeTypeLabel(data.current.committee_type, locale, locale === "en" ? "Committee" : "কমিটি")}</span>
                <h3>{pickText(locale, data.current.name, data.current.name_en).text}</h3>
                {termLabel(data.current, locale) && <p>{termLabel(data.current, locale)}</p>}
              </div>
            </Link>
          </div>
        </section>
      )}

      {data.upcoming.length > 0 && (
        <section className="content-section">
          <h2>{locale === "en" ? "Upcoming Committee" : "আসন্ন কমিটি"}</h2>
          <div className="card-grid cols-2">
            {data.upcoming.map((committee) => (
              <CommitteeCard key={committee.id} committee={committee} locale={locale} />
            ))}
          </div>
        </section>
      )}

      <section className="content-section">
        <h2>{locale === "en" ? "Past Committees" : "পূর্ববর্তী কমিটি"}</h2>
        {data.previous.length > 0 ? (
          <div className="card-grid cols-2">
            {data.previous.map((committee) => (
              <CommitteeCard key={committee.id} committee={committee} locale={locale} />
            ))}
          </div>
        ) : (
          <p>{locale === "en" ? "No past committee records have been published yet." : "এখনো কোনো পূর্ববর্তী কমিটির নথি প্রকাশিত হয়নি।"}</p>
        )}
      </section>

      {!data.current && data.upcoming.length === 0 && data.previous.length === 0 && (
        <section className="content-section">
          <div className="callout">
            <p>{locale === "en" ? "Committee information will be published soon." : "কমিটির তথ্য শীঘ্রই প্রকাশিত হবে।"}</p>
          </div>
        </section>
      )}
    </>
  );
}
