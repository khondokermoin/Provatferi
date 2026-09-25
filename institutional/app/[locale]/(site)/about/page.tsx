import Link from "next/link";
import { localizedMetadata } from "@/lib/social-meta";
import type { Metadata } from "next";
import {
  coreValues,
  founderMessage,
  governancePositions,
  mission as fallbackMission,
  objectives as fallbackObjectives,
  org,
  timeline,
  transparencyCommitment,
  vision as fallbackVision,
  whyProvatferi as fallbackWhyProvatferi,
} from "@/lib/content";
import * as en from "@/lib/content.en";
import { getAbout } from "@/lib/api/about";
import PageHeader from "@/components/PageHeader";
import { getStrings, isLocale, type Locale } from "@/lib/i18n";
import { pickText } from "@/lib/i18n/pick";
import { localizeHref } from "@/lib/i18n/paths";

const DESCRIPTIONS: Record<Locale, string> = {
  bn: `${org.nameBn}-এর গল্প, লক্ষ্য, মূল্যবোধ, উদ্দেশ্য ও প্রতিষ্ঠাতা পরিচিতি।`,
  en: `The story, vision, values, objectives and founder of ${org.nameEn}.`,
};

const TITLES: Record<Locale, string> = { bn: "আমাদের সম্পর্কে", en: "About Us" };

export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }): Promise<Metadata> {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  return localizedMetadata({ locale, bnPath: "/about", title: TITLES[locale], description: DESCRIPTIONS[locale] });
}

export default async function AboutPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const t = getStrings(locale);

  /*
   * /api/v1/about bundles about-content, mission, vision and objectives in
   * one response, so this one fetch covers both the "about content" and
   * "mission/vision/objectives" integration steps. Every field falls back
   * independently — a null `why_exists` (true in production today) keeps
   * the approved fallback text rather than rendering blank, while a
   * populated `mission.body` replaces the fallback mission string. Each
   * pickText() call then applies the SAME rule again one level deeper: a
   * populated bn value with no `_en` sibling yet still renders (in bn, with
   * an honest marker) rather than disappearing on the English site.
   */
  const result = await getAbout();
  const data = result.ok ? result.data : null;

  // registration_status has no `_en` column in the ERP (the same real fact
  // as org.founded, told in two places) — content.en.ts's `founded` is this
  // field's approved English rendering, used directly rather than marked as
  // a per-record fallback.
  const registrationStatus = locale === "en" ? en.founded : (data?.about?.registration_status ?? org.founded);

  const whyProvatferi = pickText(locale, data?.about?.why_exists ?? fallbackWhyProvatferi, locale === "en" ? (data?.about?.why_exists ? data?.about?.why_exists_en : en.whyProvatferi) : null);
  const vision = pickText(locale, data?.vision?.body ?? fallbackVision, locale === "en" ? (data?.vision?.body ? data?.vision?.body_en : en.vision) : null);
  const mission = pickText(locale, data?.mission?.body ?? fallbackMission, locale === "en" ? (data?.mission?.body ? data?.mission?.body_en : en.mission) : null);
  const objectives =
    data && data.objectives.length > 0
      ? data.objectives.map((o) => pickText(locale, o.body, locale === "en" ? o.body_en : null))
      : (locale === "en" ? en.objectives : fallbackObjectives).map((body) => ({ text: body, isFallback: false }));

  const values = locale === "en" ? en.coreValues : coreValues;
  const timelineItems = locale === "en" ? en.timeline : timeline;
  const positions = locale === "en" ? en.governancePositions : governancePositions;
  const founder = locale === "en" ? en.governancePositions[0].name! : org.founder;
  const founderTitle = locale === "en" ? en.founderTitle : org.founderTitleBn;
  const quote = locale === "en" ? en.founderMessage : founderMessage;
  const commitment = locale === "en" ? en.transparencyCommitment : transparencyCommitment;

  const introSuffix =
    locale === "en"
      ? `${org.nameEn} is a non-political, non-profit, volunteer-run organization for literature, culture, education and social awareness. ${registrationStatus}.`
      : `${org.nameBn} (${org.nameEn}) একটি অরাজনৈতিক, অলাভজনক ও স্বেচ্ছাসেবী সাহিত্য, সংস্কৃতি, শিক্ষা ও সমাজ-সচেতনতামূলক প্রতিষ্ঠান। ${registrationStatus}`;

  return (
    <>
      <PageHeader title={TITLES[locale]} description={introSuffix} locale={locale} />

      <section id="story" className="content-section">
        <h2>{locale === "en" ? "Our Story — Why Provatferi" : "আমাদের গল্প ও কেন প্রভাতফেরী"}</h2>
        <p>{whyProvatferi.text}</p>
        {whyProvatferi.isFallback && <p className="translation-note">{t.common.translationPending}</p>}
      </section>

      <section className="content-section">
        <h2>{locale === "en" ? "Vision & Mission" : "লক্ষ্য ও অভিলক্ষ্য"}</h2>
        <div className="card-grid cols-2">
          <div className="info-card">
            <span className="info-card-tag">Vision</span>
            <h3>{locale === "en" ? "Our Vision" : "আমাদের স্বপ্ন"}</h3>
            <p>{vision.text}</p>
            {vision.isFallback && <p className="translation-note">{t.common.translationPending}</p>}
          </div>
          <div className="info-card">
            <span className="info-card-tag">Mission</span>
            <h3>{locale === "en" ? "Our Mission" : "আমাদের অভিলক্ষ্য"}</h3>
            <p>{mission.text}</p>
            {mission.isFallback && <p className="translation-note">{t.common.translationPending}</p>}
          </div>
        </div>
      </section>

      <section className="content-section">
        <h2>{locale === "en" ? "Core Values" : "মূল মূল্যবোধ"}</h2>
        <div className="card-grid cols-3">
          {values.map((value) => (
            <div key={value.title} className="info-card">
              <span className="info-card-tag">{locale === "en" ? "Value" : "মূল্যবোধ"}</span>
              <h3>{value.title}</h3>
              <p>{value.body}</p>
            </div>
          ))}
        </div>
      </section>

      <section className="content-section">
        <h2>{locale === "en" ? "Our Journey" : "যাত্রাপথ"}</h2>
        <ol className="timeline">
          {timelineItems.map((item) => (
            <li key={item.label} className="timeline-item">
              <span className="timeline-date">{item.date}</span>
              <h3>{item.label}</h3>
              <p>{item.body}</p>
            </li>
          ))}
        </ol>
      </section>

      <section className="content-section">
        <h2>{locale === "en" ? "Our Objectives" : "মূল উদ্দেশ্য"}</h2>
        <ol className="ordered-list-grid">
          {objectives.map((objective, i) => (
            <li key={i}>
              {objective.text}
              {objective.isFallback && <span className="translation-note"> ({t.common.translationPending})</span>}
            </li>
          ))}
        </ol>
      </section>

      <section className="content-section">
        <h2>{locale === "en" ? "Founder" : "প্রতিষ্ঠাতা"}</h2>
        <div className="card-grid cols-2">
          <div className="info-card">
            <span className="info-card-tag">{locale === "en" ? "Founder" : "প্রতিষ্ঠাতা"}</span>
            <h3>{founder}</h3>
            <p>{founderTitle}</p>
          </div>
          <div className="callout is-quote">
            <p>{quote}</p>
            <cite>— {founder}, {founderTitle}</cite>
          </div>
        </div>
      </section>

      <section className="content-section">
        <h2>{locale === "en" ? "Leadership" : "নেতৃত্ব"}</h2>
        <p>
          {locale === "en" ? (
            <>
              Provatferi&apos;s current interim committee is still being formed. Aside from the Founder&apos;s seat, every
              other position will be filled soon — see the full organizational structure on the{" "}
              <Link href={localizeHref("/organization", locale)}>Organization page</Link>.
            </>
          ) : (
            <>
              প্রভাতফেরীর বর্তমান অন্তর্বর্তীকালীন কমিটি গঠন প্রক্রিয়াধীন। প্রতিষ্ঠাতা ছাড়া অন্যান্য পদ শীঘ্রই পূরণ করা হবে —
              সম্পূর্ণ সাংগঠনিক কাঠামো দেখুন <Link href={localizeHref("/organization", locale)}>সংগঠন পাতায়</Link>।
            </>
          )}
        </p>
        <div className="card-grid cols-3">
          {positions.slice(0, 3).map((position) => (
            <div key={position.title} className="info-card">
              <span className={`info-card-tag ${position.filled ? "" : "is-vacant"}`}>
                {position.filled ? (locale === "en" ? "In Office" : "দায়িত্বে আছেন") : (locale === "en" ? "Vacant" : "শূন্য পদ")}
              </span>
              <h3>{position.title}</h3>
              <p className={`info-card-name ${position.filled ? "" : "is-open"}`}>
                {position.filled ? position.name : (locale === "en" ? "To be appointed" : "নিয়োগ প্রক্রিয়াধীন")}
              </p>
            </div>
          ))}
        </div>
      </section>

      <section id="transparency" className="content-section">
        <h2>{locale === "en" ? "Transparency & Documents" : "স্বচ্ছতা ও নথি"}</h2>
        <div className="callout">
          <p>{commitment}</p>
        </div>
        <div className="empty-state is-compact" style={{ marginTop: 20 }}>
          <p>{locale === "en" ? "No public documents have been published yet" : "এখনো কোনো পাবলিক নথি প্রকাশিত হয়নি"}</p>
          <p>
            {locale === "en"
              ? "Resolutions, financial reports and other institutional documents will be published here once ready."
              : "রেজুলেশন, আর্থিক প্রতিবেদন ও অন্যান্য প্রাতিষ্ঠানিক নথি প্রস্তুত হলে এখানে প্রকাশ করা হবে।"}
          </p>
        </div>
      </section>
    </>
  );
}
