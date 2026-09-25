import Link from "next/link";
import { localizedMetadata } from "@/lib/social-meta";
import type { Metadata } from "next";
import { org, recentActivities } from "@/lib/content";
import { recentActivities as recentActivitiesEn } from "@/lib/content.en";
import PageHeader from "@/components/PageHeader";
import { isLocale, type Locale } from "@/lib/i18n";
import { localizeHref } from "@/lib/i18n/paths";

const DESCRIPTIONS: Record<Locale, string> = {
  bn: `${org.shortName}-এর আসন্ন অনুষ্ঠানের তালিকা ও সাম্প্রতিক কার্যক্রমের সংরক্ষণাগার।`,
  en: `${org.nameEn}'s upcoming events and an archive of recent activities.`,
};
const TITLES: Record<Locale, string> = { bn: "ইভেন্ট", en: "Events" };

export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }): Promise<Metadata> {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  return localizedMetadata({ locale, bnPath: "/events", title: TITLES[locale], description: DESCRIPTIONS[locale] });
}

export default async function EventsPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale: rawLocale } = await params;
  const locale: Locale = isLocale(rawLocale) ? rawLocale : "bn";
  const activities = locale === "en" ? recentActivitiesEn : recentActivities;

  return (
    <>
      <PageHeader title={TITLES[locale]} description={DESCRIPTIONS[locale]} locale={locale} />

      <section className="content-section">
        <h2>{locale === "en" ? "Upcoming Events" : "আসন্ন ইভেন্ট"}</h2>
        <div className="empty-state">
          <p>{locale === "en" ? "No events are scheduled at the moment" : "এই মুহূর্তে কোনো নির্ধারিত ইভেন্ট নেই"}</p>
          <p>
            {locale === "en"
              ? "New events will be announced here. If you'd like to propose or collaborate on one, please get in touch."
              : "নতুন ইভেন্ট ঘোষণা করা হলে এখানে প্রকাশ করা হবে। ইভেন্টের প্রস্তাব বা সহযোগিতার আগ্রহ থাকলে যোগাযোগ করুন।"}
          </p>
          <a href={org.facebook} className="button button-outline" target="_blank" rel="noreferrer">
            {locale === "en" ? "Follow our Facebook page" : "ফেসবুক পেজ অনুসরণ করুন"} <span aria-hidden="true">↗</span>
          </a>
          <Link href={localizeHref("/contact", locale)} className="button button-primary">
            {locale === "en" ? "Contact Us" : "যোগাযোগ করুন"} <span aria-hidden="true">↗</span>
          </Link>
        </div>
      </section>

      <section className="content-section">
        <h2>{locale === "en" ? "Recently Completed Activities" : "সাম্প্রতিক সম্পন্ন কার্যক্রম"}</h2>
        <p>
          {locale === "en"
            ? "These aren't formal events, but here's a short list of recently completed activities."
            : "আনুষ্ঠানিক ইভেন্ট না হলেও, সম্প্রতি সম্পন্ন কার্যক্রমের একটি সংক্ষিপ্ত তালিকা নিচে দেওয়া হলো।"}
        </p>
        <div className="flex flex-col gap-4">
          {activities.map((activity) => (
            <div key={activity.slug} className="callout">
              <p>
                <strong style={{ color: "var(--accent-text)" }}>{activity.date}</strong>
                {" — "}
                <Link href={localizeHref(`/activities/${activity.slug}`, locale)}><strong>{activity.title}</strong></Link>
                <br />
                <span className="text-sm">{activity.place}</span>
              </p>
            </div>
          ))}
        </div>
        <p className="mt-3">
          <Link href={localizeHref("/activities", locale)} className="text-link">
            {locale === "en" ? "View all activities" : "সব কার্যক্রম দেখুন"} <span aria-hidden="true">→</span>
          </Link>
        </p>
      </section>
    </>
  );
}
