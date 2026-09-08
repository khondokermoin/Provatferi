import Link from "next/link";
import type { Metadata } from "next";
import { org, recentActivities } from "@/lib/content";
import PageHeader from "@/components/PageHeader";

const description = `${org.shortName}-এর আসন্ন অনুষ্ঠানের তালিকা ও সাম্প্রতিক কার্যক্রমের সংরক্ষণাগার।`;

export const metadata: Metadata = {
  title: "ইভেন্ট",
  description,
  alternates: { canonical: "/events" },
  openGraph: { title: `ইভেন্ট | ${org.shortName}`, description, url: "/events", images: [{ ...org.ogImage, alt: org.nameBn }] },
};

export default function EventsPage() {
  return (
    <>
      <PageHeader title="ইভেন্ট" description={description} />

      <section className="content-section">
        <h2>আসন্ন ইভেন্ট</h2>
        <div className="empty-state">
          <p>এই মুহূর্তে কোনো নির্ধারিত ইভেন্ট নেই</p>
          <p>নতুন ইভেন্ট ঘোষণা করা হলে এখানে প্রকাশ করা হবে। ইভেন্টের প্রস্তাব বা সহযোগিতার আগ্রহ থাকলে যোগাযোগ করুন।</p>
          <a href={org.facebook} className="button button-outline" target="_blank" rel="noreferrer">
            ফেসবুক পেজ অনুসরণ করুন <span aria-hidden="true">↗</span>
          </a>
          <Link href="/contact" className="button button-primary">
            যোগাযোগ করুন <span aria-hidden="true">↗</span>
          </Link>
        </div>
      </section>

      <section className="content-section">
        <h2>সাম্প্রতিক সম্পন্ন কার্যক্রম</h2>
        <p>আনুষ্ঠানিক ইভেন্ট না হলেও, সম্প্রতি সম্পন্ন কার্যক্রমের একটি সংক্ষিপ্ত তালিকা নিচে দেওয়া হলো।</p>
        <div className="flex flex-col gap-4">
          {recentActivities.map((activity) => (
            <div key={activity.slug} className="callout">
              <p>
                <strong style={{ color: "var(--accent-text)" }}>{activity.date}</strong>
                {" — "}
                <Link href={`/activities/${activity.slug}`}><strong>{activity.title}</strong></Link>
                <br />
                <span className="text-sm">{activity.place}</span>
              </p>
            </div>
          ))}
        </div>
        <p className="mt-3">
          <Link href="/activities" className="text-link">
            সব কার্যক্রম দেখুন <span aria-hidden="true">→</span>
          </Link>
        </p>
      </section>
    </>
  );
}
