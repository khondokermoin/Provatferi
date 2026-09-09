import type { Metadata } from "next";
import { activityCategories, org } from "@/lib/content";
import { getActivitiesWithFallback } from "@/lib/api/activities";
import PageHeader from "@/components/PageHeader";
import ActivityFilter from "@/components/ActivityFilter";

const description = `${org.shortName}-এর সাহিত্য, সাংস্কৃতিক, সামাজিক সচেতনতা ও মানবিক কার্যক্রমের তালিকা।`;

export const metadata: Metadata = {
  title: "কার্যক্রম",
  description,
  alternates: { canonical: "/activities" },
  openGraph: { title: `কার্যক্রম | ${org.shortName}`, description, url: "/activities", images: [{ ...org.ogImage, alt: org.nameBn }] },
};

export default async function ActivitiesPage({ searchParams }: { searchParams: Promise<{ category?: string }> }) {
  const { category } = await searchParams;
  const { activities } = await getActivitiesWithFallback();

  return (
    <>
      <PageHeader title="কার্যক্রম" description={description} />

      <section className="content-section">
        <h2>কার্যক্রমের ধরন</h2>
        <div className="card-grid cols-2">
          {activityCategories.map((category) => (
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
        <h2>সাম্প্রতিক কার্যক্রমের নথি</h2>
        <ActivityFilter activities={activities} initialCategory={category} />
      </section>
    </>
  );
}
