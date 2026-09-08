import Link from "next/link";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { org, recentActivities } from "@/lib/content";

export function generateStaticParams() {
  return recentActivities.map((activity) => ({ slug: activity.slug }));
}

function findActivity(slug: string) {
  return recentActivities.find((activity) => activity.slug === slug);
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const activity = findActivity(slug);
  if (!activity) return {};

  const description = `${activity.date} — ${activity.place}-এ অনুষ্ঠিত ${org.shortName}-এর ${activity.title}।`;

  return {
    title: activity.title,
    description,
    alternates: { canonical: `/activities/${activity.slug}` },
    openGraph: {
      title: `${activity.title} | ${org.shortName}`,
      description,
      url: `/activities/${activity.slug}`,
      images: [{ ...org.ogImage, alt: org.nameBn }],
    },
  };
}

export default async function ActivityDetailPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const activity = findActivity(slug);
  if (!activity) notFound();

  const breadcrumbData = {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [
      { "@type": "ListItem", position: 1, name: "হোম", item: org.website },
      { "@type": "ListItem", position: 2, name: "কার্যক্রম", item: `${org.website}/activities` },
      { "@type": "ListItem", position: 3, name: activity.title },
    ],
  };

  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbData) }} />

      <div className="page-header">
        <nav className="breadcrumb" aria-label="ব্রেডক্রাম্ব">
          <Link href="/">হোম</Link>
          <span aria-hidden="true">/</span>
          <Link href="/activities">কার্যক্রম</Link>
          <span aria-hidden="true">/</span>
          <span aria-current="page">{activity.title}</span>
        </nav>
        <h1>{activity.title}</h1>
        <p>{activity.place}</p>
      </div>

      <section className="content-section">
        <dl className="definition-list">
          <div>
            <dt>ক্যাটাগরি</dt>
            <dd>{activity.category}</dd>
          </div>
          <div>
            <dt>তারিখ</dt>
            <dd>{activity.date}</dd>
          </div>
          <div>
            <dt>স্থান</dt>
            <dd>{activity.place}</dd>
          </div>
        </dl>
      </section>

      <section className="content-section">
        <h2>ফলাফল ও প্রভাব</h2>
        {activity.outcomes ? (
          <p>{activity.outcomes}</p>
        ) : (
          <div className="empty-state is-compact">
            <p>বিস্তারিত প্রতিবেদন শীঘ্রই যুক্ত করা হবে</p>
            <p>এই কার্যক্রমের ফলাফল, অংশগ্রহণকারীর সংখ্যা ও ছবি প্রস্তুত হলে এখানে প্রকাশ করা হবে।</p>
          </div>
        )}
      </section>

      <section className="content-section">
        <Link href="/activities" className="text-link">
          <span aria-hidden="true">←</span> সব কার্যক্রম দেখুন
        </Link>
      </section>
    </>
  );
}
