import type { Metadata } from "next";
import { localizedMetadata } from "@/lib/social-meta";
import { activityCategories, org } from "@/lib/content";
import { activityCategories as activityCategoriesEn } from "@/lib/content.en";
import { getActivitiesWithFallback } from "@/lib/api/activities";
import PageHeader from "@/components/PageHeader";
import ActivityFilter from "@/components/ActivityFilter";
import { isLocale, type Locale } from "@/lib/i18n";

const DESCRIPTIONS: Record<Locale, string> = {
  bn: `${org.shortName}-এর সাহিত্য, সাংস্কৃতিক, সামাজিক সচেতনতা ও মানবিক কার্যক্রমের তালিকা।`,
  en: `A record of ${org.nameEn}'s literary, cultural, social-awareness and humanitarian activities.`,
};
const TITLES: Record<Locale, string> = { bn: "কার্যক্রম", en: "Activities" };

export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }): Promise<Metadata> {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  return localizedMetadata({ locale, bnPath: "/activities", title: TITLES[locale], description: DESCRIPTIONS[locale] });
}

export default async function ActivitiesPage({
  params,
  searchParams,
}: {
  params: Promise<{ locale: string }>;
  searchParams: Promise<{ category?: string }>;
}) {
  const [{ locale: rawLocale }, { category }] = await Promise.all([params, searchParams]);
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const categories = locale === "en" ? activityCategoriesEn : activityCategories;
  const { activities } = await getActivitiesWithFallback();

  return (
    <>
      <PageHeader title={TITLES[locale]} description={DESCRIPTIONS[locale]} locale={locale} />

      <section className="content-section">
        <h2>{locale === "en" ? "Types of Activities" : "কার্যক্রমের ধরন"}</h2>
        <div className="card-grid cols-2">
          {categories.map((category) => (
            <div key={category.title} className="info-card">
              <h3>{category.title}</h3>
              <ul className="mt-3 space-y-1.5 text-sm text-[var(--muted)] list-disc pl-4">
                {category.items.map((item) => (
                  <li key={item}>{item}</li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      </section>

      <section id="records" className="content-section">
        <h2>{locale === "en" ? "Recent Activity Records" : "সাম্প্রতিক কার্যক্রমের নথি"}</h2>
        <ActivityFilter activities={activities} initialCategory={category} locale={locale} />
      </section>
    </>
  );
}
