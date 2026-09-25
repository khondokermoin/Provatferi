import type { Metadata } from "next";
import { localizedMetadata } from "@/lib/social-meta";
import { membershipTypes as fallbackMembershipTypes, org } from "@/lib/content";
import { membershipTypes as fallbackMembershipTypesEn } from "@/lib/content.en";
import { getCurrentCampaigns, getMembershipTypes } from "@/lib/api/membership";
import PageHeader from "@/components/PageHeader";
import MembershipApplicationForm from "@/components/MembershipApplicationForm";
import { isLocale, type Locale } from "@/lib/i18n";
import { pickText } from "@/lib/i18n/pick";

const DESCRIPTIONS: Record<Locale, string> = {
  bn: `${org.shortName}-এর সদস্যপদের ধরন ও সদস্য হওয়ার প্রক্রিয়া।`,
  en: `${org.nameEn}'s membership types and how to become a member.`,
};
const TITLES: Record<Locale, string> = { bn: "সদস্য হোন", en: "Become a Member" };

const JOURNEY: Record<Locale, { title: string; body: string; current?: boolean }[]> = {
  bn: [
    { title: "আগ্রহ প্রকাশ", body: "ই-মেইল বা ফোনে যোগাযোগ করে সদস্য হওয়ার আগ্রহ জানান।", current: true },
    { title: "আবেদন", body: "অনলাইন আবেদন ব্যবস্থা শীঘ্রই চালু হবে — এখন সরাসরি যোগাযোগের মাধ্যমে আবেদন গ্রহণ করা হয়।" },
    { title: "পর্যালোচনা", body: "প্রদত্ত তথ্য যাচাই করে দায়িত্বশীল টিম সিদ্ধান্ত নেবে।" },
    { title: "সদস্যপদ নিশ্চিতকরণ", body: "অনুমোদনের পর আপনাকে সদস্যপদ নিশ্চিত করে জানানো হবে।" },
  ],
  en: [
    { title: "Express Interest", body: "Reach out by email or phone to let us know you're interested in becoming a member.", current: true },
    { title: "Apply", body: "An online application system is coming soon — for now, applications are accepted through direct contact." },
    { title: "Review", body: "Our team will verify the information provided and make a decision." },
    { title: "Confirmation", body: "Once approved, you'll be notified and your membership confirmed." },
  ],
};

export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }): Promise<Metadata> {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  return localizedMetadata({ locale, bnPath: "/membership", title: TITLES[locale], description: DESCRIPTIONS[locale] });
}

export default async function MembershipPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";

  // Whole-list fallback here, not field-by-field: a membership type list is
  // one coherent set, not independent facts, so a malformed or empty API
  // response falls back to the complete approved list rather than mixing
  // sources into a partial one.
  const result = await getMembershipTypes();
  const types =
    result.ok && result.data.length > 0
      ? result.data.map((t) => ({ name: pickText(locale, t.name, t.name_en).text, note: pickText(locale, t.description ?? "", t.description_en).text }))
      : locale === "en" ? fallbackMembershipTypesEn : fallbackMembershipTypes;

  // §42: a real application form only ever renders for a season that is
  // both open AND has at least one self-appliable type — a season open
  // exclusively for an honorary/invite-only type must still show the
  // "contact us directly" fallback, not a form with an empty dropdown.
  const campaignsResult = await getCurrentCampaigns();
  const applicableCampaigns = campaignsResult.ok
    ? campaignsResult.data.filter((c) => c.membership_types.length > 0)
    : [];

  return (
    <>
      <PageHeader
        title={TITLES[locale]}
        description={
          locale === "en"
            ? "Become a Provatferi member and get directly involved in reading, literature, culture and social work."
            : "প্রভাতফেরীর সদস্য হয়ে বইপড়া, সাহিত্য, সংস্কৃতি ও সামাজিক কার্যক্রমে সরাসরি যুক্ত হোন।"
        }
        locale={locale}
      />

      <section className="content-section">
        <h2>{locale === "en" ? "Membership Types" : "সদস্যপদের ধরন"}</h2>
        <div className="card-grid cols-2">
          {types.map((type) => (
            <div key={type.name} className="info-card">
              <span className="info-card-tag">{locale === "en" ? "Membership" : "সদস্যপদ"}</span>
              <h3>{type.name}</h3>
              <p>{type.note}</p>
            </div>
          ))}
        </div>
      </section>

      {applicableCampaigns.length > 0 ? (
        <section className="content-section">
          <h2>{locale === "en" ? "Apply Now" : "আবেদন করুন"}</h2>
          <MembershipApplicationForm campaigns={applicableCampaigns} locale={locale} />
        </section>
      ) : (
        <>
          <section className="content-section">
            <h2>{locale === "en" ? "Application Steps" : "আবেদনের ধাপ"}</h2>
            <div className="journey-steps">
              {JOURNEY[locale].map((step) => (
                <div key={step.title} className={`journey-step ${step.current ? "is-current" : ""}`}>
                  <h3>{step.title}</h3>
                  <p>{step.body}</p>
                </div>
              ))}
            </div>
          </section>

          <section className="content-section">
            <h2>{locale === "en" ? "Get in Touch Now" : "এখনই যোগাযোগ করুন"}</h2>
            <div className="callout">
              <p>
                {locale === "en" ? (
                  <>
                    There is no membership registration season open right now. If you&apos;re interested in becoming a
                    member, please contact us directly — <a href={`mailto:${org.email}`}>{org.email}</a> or{" "}
                    <a href={`tel:${org.phone}`}>{org.phone}</a>.
                  </>
                ) : (
                  <>
                    বর্তমানে কোনো নিবন্ধন সিজন চলমান নেই। সদস্য হতে আগ্রহী হলে সরাসরি যোগাযোগ করুন —{" "}
                    <a href={`mailto:${org.email}`}>{org.email}</a> অথবা{" "}
                    <a href={`tel:${org.phone}`}>{org.phone}</a>।
                  </>
                )}
              </p>
            </div>
          </section>
        </>
      )}
    </>
  );
}
