import type { Metadata } from "next";
import { localizedMetadata } from "@/lib/social-meta";
import { governancePositions, org } from "@/lib/content";
import { governancePositions as governancePositionsEn, address as addressEn } from "@/lib/content.en";
import { getOrganizationUnits } from "@/lib/api/organization";
import PageHeader from "@/components/PageHeader";
import { isLocale, type Locale } from "@/lib/i18n";
import { pickText } from "@/lib/i18n/pick";

const DESCRIPTIONS: Record<Locale, string> = {
  bn: `${org.shortName}-এর সাংগঠনিক কাঠামো, পরিচালনা কমিটি ও বর্তমান কার্যালয়ের তথ্য।`,
  en: `${org.nameEn}'s organizational structure, governing committee and current office details.`,
};
const TITLES: Record<Locale, string> = { bn: "সংগঠন", en: "Organization" };
const LEVELS: Record<Locale, string[]> = {
  bn: ["কেন্দ্রীয়", "বিভাগ", "জেলা", "উপজেলা", "ইউনিয়ন/ইউনিট"],
  en: ["Central", "Division", "District", "Upazila", "Union/Unit"],
};

const BANGLA_DIGITS = ["০", "১", "২", "৩", "৪", "৫", "৬", "৭", "৮", "৯"];
function bnNum(n: number): string {
  return String(n).replace(/[0-9]/g, (d) => BANGLA_DIGITS[Number(d)]);
}

export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }): Promise<Metadata> {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  return localizedMetadata({ locale, bnPath: "/organization", title: TITLES[locale], description: DESCRIPTIONS[locale] });
}

export default async function OrganizationPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const positions = locale === "en" ? governancePositionsEn : governancePositions;
  const filledPositions = positions.filter((p) => p.filled);
  const vacantPositions = positions.filter((p) => !p.filled);
  const filledCount = filledPositions.length;
  const count = (n: number) => (locale === "en" ? String(n) : bnNum(n));

  /*
   * governancePositions stays static — there is no public committee/position
   * roster endpoint (only the Sanctum-gated /admin/organization-units CRUD
   * exists server-side), so inventing one here would mean fabricating names
   * for a real, currently-unfilled committee. Only the office address below
   * is API-driven, from the one real "central" unit that exists today —
   * organizational_units.name_en exists but has no address_en counterpart,
   * so the address itself falls back to the same approved English
   * transliteration used everywhere else on the English site.
   */
  const units = await getOrganizationUnits();
  const centralUnit = units.ok ? units.data.find((u) => u.unit_type === "central") : null;
  const address = centralUnit?.address ? (locale === "en" ? addressEn : centralUnit.address) : (locale === "en" ? addressEn : org.address);
  const unitName = centralUnit ? pickText(locale, centralUnit.name, centralUnit.name_en) : null;

  return (
    <>
      <PageHeader
        title={locale === "en" ? "Organizational Structure" : "সাংগঠনিক কাঠামো"}
        description={
          locale === "en"
            ? "Provatferi currently runs its core activities in Dollai Nowabpur Union, Chandina Upazila, Cumilla. There are plans to build an organizational network at the Division–District–Upazila–Unit level across Bangladesh in the future."
            : "প্রভাতফেরী বর্তমানে কুমিল্লার চান্দিনা উপজেলার দোল্লাই নোয়াবপুর ইউনিয়নে তার মূল কার্যক্রম পরিচালনা করছে। ভবিষ্যতে সারা বাংলাদেশে বিভাগ–জেলা–উপজেলা–ইউনিট পর্যায়ে সাংগঠনিক নেটওয়ার্ক গড়ে তোলার পরিকল্পনা রয়েছে।"
        }
        locale={locale}
      />

      <section id="structure" className="content-section">
        <h2>{locale === "en" ? "Organizational Levels" : "সাংগঠনিক স্তর"}</h2>
        <div className="flex flex-wrap items-center gap-2">
          {LEVELS[locale].map((level, i) => (
            <div key={level} className="flex items-center gap-2">
              <span
                className="rounded-full px-4 py-2 text-sm font-semibold"
                style={{ background: "var(--heading)", color: "var(--bg)" }}
              >
                {level}
              </span>
              {i < LEVELS[locale].length - 1 && <span style={{ color: "var(--brand-orange)" }}>→</span>}
            </div>
          ))}
        </div>
      </section>

      <section id="committee" className="content-section">
        <h2>{locale === "en" ? "Governing Committee" : "পরিচালনা কমিটি"}</h2>
        <p>
          {locale === "en" ? (
            <>
              Provatferi&apos;s current committee is a 3-month interim organizational committee, formed to organize the
              institution, distribute responsibilities and lay the groundwork for a future permanent structure. Of{" "}
              {count(positions.length)} total positions, {count(filledCount)} {filledCount === 1 ? "has" : "have"} been filled
              so far; the remaining positions are in the process of being assigned.
            </>
          ) : (
            <>
              প্রভাতফেরীর বর্তমান কমিটি একটি ৩ মাস মেয়াদি অন্তর্বর্তীকালীন সাংগঠনিক কমিটি, যার উদ্দেশ্য প্রতিষ্ঠানকে
              সংগঠিত করা, দায়িত্ব বণ্টন করা এবং ভবিষ্যৎ স্থায়ী কাঠামোর ভিত্তি তৈরি করা। মোট {count(positions.length)}টি পদের
              মধ্যে বর্তমানে {count(filledCount)}টি পদ পূরণ হয়েছে; বাকি পদগুলোতে দায়িত্বশীল ব্যক্তি নির্ধারণ প্রক্রিয়াধীন।
            </>
          )}
        </p>
        <div className="leadership-spotlight">
          {filledPositions.map((position) => (
            <div key={position.title} className="leadership-card">
              <div>
                <span className="info-card-tag">{locale === "en" ? "In Office" : "দায়িত্বে আছেন"}</span>
                <h3>{position.name}</h3>
                <p>{position.title}</p>
              </div>
            </div>
          ))}
        </div>

        <div className="vacancy-block">
          <h3 className="vacancy-heading">
            {locale === "en" ? "Constitutional Positions — Appointments Pending" : "গঠনতান্ত্রিক পদ — নিয়োগ প্রক্রিয়াধীন"}
          </h3>
          <p className="vacancy-note">
            {locale === "en" ? (
              <>
                The process of appointing people to the remaining {count(vacantPositions.length)} constitutionally-defined
                positions is underway. This section will be updated once the actual committee is formed.
              </>
            ) : (
              <>
                গঠনতন্ত্র অনুযায়ী নির্ধারিত বাকি {count(vacantPositions.length)}টি পদে দায়িত্বশীল ব্যক্তি নির্ধারণের প্রক্রিয়া চলছে।
                প্রকৃত কমিটি গঠিত হলে এখানে হালনাগাদ করা হবে।
              </>
            )}
          </p>
          <ul className="vacancy-list">
            {vacantPositions.map((position) => (
              <li key={position.title} className="vacancy-row">
                <span>{position.title}</span>
                <span className="vacancy-tag">{locale === "en" ? "Vacant" : "শূন্য"}</span>
              </li>
            ))}
          </ul>
        </div>
      </section>

      <section className="content-section">
        <h2>{locale === "en" ? "Current Office" : "বর্তমান কার্যালয়"}</h2>
        <div className="info-card" style={{ maxWidth: 560 }}>
          <span className="info-card-tag">{locale === "en" ? "Address" : "ঠিকানা"}</span>
          {unitName && <p style={{ fontWeight: 600, marginBottom: 4 }}>{unitName.text}</p>}
          <p>{address}</p>
        </div>
      </section>
    </>
  );
}
