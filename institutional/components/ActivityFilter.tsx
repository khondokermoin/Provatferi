"use client";

import Link from "next/link";
import { useState } from "react";
import { activityCategories } from "@/lib/content";

type Activity = {
  slug: string;
  date: string;
  title: string;
  place: string;
  category: string;
  photos: string[];
  outcomes: string | null;
};

/**
 * The chip list always covers all four canonical categories (not just the
 * ones with a recorded activity yet), so the Activities ▼ nav submenu links
 * to a real, matching filter state rather than silently landing on "সব" —
 * a category with zero activities shows an honest empty state instead.
 */
const categories = ["সব", ...activityCategories.map((c) => c.title)];

export default function ActivityFilter({ activities, initialCategory }: { activities: Activity[]; initialCategory?: string }) {
  const [active, setActive] = useState(initialCategory && categories.includes(initialCategory) ? initialCategory : "সব");
  const visible = active === "সব" ? activities : activities.filter((a) => a.category === active);

  return (
    <>
      <div className="filter-bar" role="group" aria-label="কার্যক্রম ফিল্টার">
        {categories.map((category) => (
          <button
            key={category}
            type="button"
            className={`filter-chip ${active === category ? "is-active" : ""}`}
            aria-pressed={active === category}
            onClick={() => setActive(category)}
          >
            {category}
          </button>
        ))}
      </div>

      {visible.length > 0 ? (
        <div className="evidence-grid">
          {visible.map((activity) => (
            <article key={activity.slug} className={`evidence-card ${activity.photos.length === 0 ? "is-text-only" : ""}`}>
              {activity.photos.length > 0 && (
                <div className="evidence-card-media">
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img src={activity.photos[0]} alt="" loading="lazy" />
                </div>
              )}
              <div className="evidence-card-body">
                <span className="evidence-card-tag">{activity.category}</span>
                <h3>
                  <Link href={`/activities/${activity.slug}`}>{activity.title}</Link>
                </h3>
                <div className="evidence-card-meta">
                  <span>{activity.date}</span>
                  <span>{activity.place}</span>
                </div>
              </div>
            </article>
          ))}
        </div>
      ) : (
        <div className="empty-state is-compact">
          <p>এই ক্যাটাগরিতে এখনো কোনো নথিভুক্ত কার্যক্রম নেই</p>
          <p>নতুন কার্যক্রম অনুষ্ঠিত ও নথিভুক্ত হলে এখানে প্রকাশ করা হবে।</p>
        </div>
      )}
    </>
  );
}
