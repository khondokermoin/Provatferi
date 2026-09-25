"use client";

import Link from "next/link";
import { useState } from "react";
import { activityCategories } from "@/lib/content";
import { activityCategories as activityCategoriesEn } from "@/lib/content.en";
import { getStrings, type Locale } from "@/lib/i18n";
import { pickText } from "@/lib/i18n/pick";
import { localizeHref } from "@/lib/i18n/paths";

type Activity = {
  slug: string;
  date: string;
  title: string;
  titleEn: string | null;
  place: string;
  category: string;
  categoryEn: string | null;
  photos: string[];
  outcomes: string | null;
};

/** Bn category title -> its English rendering, by shared array index (both files list the same four categories, in the same order). */
const CATEGORY_LABEL_EN: Record<string, string> = Object.fromEntries(
  activityCategories.map((c, i) => [c.title, activityCategoriesEn[i]?.title ?? c.title]),
);

function categoryLabel(bnTitle: string, locale: Locale): string {
  return locale === "en" ? (CATEGORY_LABEL_EN[bnTitle] ?? bnTitle) : bnTitle;
}

/**
 * The chip list always covers all four canonical categories (not just the
 * ones with a recorded activity yet), so the Activities ▼ nav submenu links
 * to a real, matching filter state rather than silently landing on "সব" —
 * a category with zero activities shows an honest empty state instead.
 *
 * The internal `active` state and the `?category=` query value are always
 * the bn title (a stable key both this component and the nav dropdown
 * share) — only the rendered chip TEXT is localized.
 */
const ALL_KEY = "সব";
const categories = [ALL_KEY, ...activityCategories.map((c) => c.title)];

export default function ActivityFilter({ activities, initialCategory, locale }: { activities: Activity[]; initialCategory?: string; locale: Locale }) {
  const t = getStrings(locale);
  const [active, setActive] = useState(initialCategory && categories.includes(initialCategory) ? initialCategory : ALL_KEY);
  const visible = active === ALL_KEY ? activities : activities.filter((a) => a.category === active);

  return (
    <>
      <div className="filter-bar" role="group" aria-label={t.nav.activities}>
        {categories.map((category) => (
          <button
            key={category}
            type="button"
            className={`filter-chip ${active === category ? "is-active" : ""}`}
            aria-pressed={active === category}
            onClick={() => setActive(category)}
          >
            {category === ALL_KEY ? t.common.viewAll : categoryLabel(category, locale)}
          </button>
        ))}
      </div>

      {visible.length > 0 ? (
        <div className="evidence-grid">
          {visible.map((activity) => {
            const title = pickText(locale, activity.title, activity.titleEn);
            return (
              <article key={activity.slug} className={`evidence-card ${activity.photos.length === 0 ? "is-text-only" : ""}`}>
                {activity.photos.length > 0 && (
                  <div className="evidence-card-media">
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img src={activity.photos[0]} alt="" loading="lazy" />
                  </div>
                )}
                <div className="evidence-card-body">
                  <span className="evidence-card-tag">{categoryLabel(activity.category, locale)}</span>
                  <h3>
                    <Link href={localizeHref(`/activities/${activity.slug}`, locale)}>{title.text}</Link>
                  </h3>
                  <div className="evidence-card-meta">
                    <span>{activity.date}</span>
                    <span>{activity.place}</span>
                  </div>
                </div>
              </article>
            );
          })}
        </div>
      ) : (
        <div className="empty-state is-compact">
          <p>
            {locale === "en"
              ? "No documented activities in this category yet"
              : "এই ক্যাটাগরিতে এখনো কোনো নথিভুক্ত কার্যক্রম নেই"}
          </p>
          <p>
            {locale === "en"
              ? "New activities will be published here once they take place and are recorded."
              : "নতুন কার্যক্রম অনুষ্ঠিত ও নথিভুক্ত হলে এখানে প্রকাশ করা হবে।"}
          </p>
        </div>
      )}
    </>
  );
}
