import type { MetadataRoute } from "next";
import { org } from "@/lib/content";
import { localizeHref } from "@/lib/i18n/paths";
import { getActivitiesWithFallback } from "@/lib/api/activities";
import { getCommittees } from "@/lib/api/committees";
import { getMemberDirectory } from "@/lib/api/member-directory";
import { getNoticeSitemap } from "@/lib/api/notices";
import { getJobPostings } from "@/lib/api/recruitment";

/**
 * Phase 2 (plan Section F): one entry per logical (bn) page, with
 * `alternates.languages` naming its `/en` counterpart ONLY when a real
 * English rendering exists — omitted otherwise, so a fallback-rendered
 * English page is never advertised as a distinct indexable URL. This rule
 * lives in this ONE helper, not repeated ad hoc per entry, so it can't erode.
 */
function entry(
  path: string,
  opts: { lastModified?: Date; changeFrequency: MetadataRoute.Sitemap[number]["changeFrequency"]; priority: number; hasTranslation?: boolean },
): MetadataRoute.Sitemap[number] {
  const { hasTranslation = true, ...rest } = opts;
  return {
    url: `${org.website}${path === "/" ? "" : path}`,
    lastModified: rest.lastModified ?? new Date(),
    changeFrequency: rest.changeFrequency,
    priority: rest.priority,
    alternates: {
      languages: hasTranslation ? { bn: `${org.website}${path === "/" ? "" : path}`, en: `${org.website}${localizeHref(path, "en")}` } : { bn: `${org.website}${path === "/" ? "" : path}` },
    },
  };
}

// Activity URLs come from the exact same source as the listing page and
// generateStaticParams (getActivitiesWithFallback) — never an independent
// read of lib/content.ts here, or a sitemap/route drift like the one this
// cutover was built to avoid becomes possible again. Committee, member,
// notice and recruitment detail pages have no generateStaticParams to stay
// in sync with (they render on demand) — a transient API failure here only
// ever shrinks the sitemap for one crawl, it never produces a 404 for a URL
// the sitemap listed, since both read the same live endpoint.
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  // Every static page is fully translated by Increment 2 — hasTranslation stays true (the default) for all of these.
  const routes: { path: string; changeFrequency: MetadataRoute.Sitemap[number]["changeFrequency"]; priority: number }[] = [
    { path: "/", changeFrequency: "daily", priority: 1 },
    { path: "/about", changeFrequency: "weekly", priority: 0.7 },
    { path: "/activities", changeFrequency: "weekly", priority: 0.7 },
    { path: "/organization", changeFrequency: "weekly", priority: 0.7 },
    { path: "/committee", changeFrequency: "weekly", priority: 0.7 },
    { path: "/members", changeFrequency: "weekly", priority: 0.7 },
    { path: "/notices", changeFrequency: "daily", priority: 0.7 },
    { path: "/events", changeFrequency: "weekly", priority: 0.7 },
    { path: "/recruitment", changeFrequency: "weekly", priority: 0.7 },
    { path: "/membership", changeFrequency: "weekly", priority: 0.7 },
    { path: "/contact", changeFrequency: "weekly", priority: 0.7 },
  ];
  const staticEntries: MetadataRoute.Sitemap = routes.map((r) => entry(r.path, r));

  const { activities } = await getActivitiesWithFallback();
  const activityEntries: MetadataRoute.Sitemap = activities.map((activity) =>
    entry(`/activities/${activity.slug}`, { changeFrequency: "monthly", priority: 0.5, hasTranslation: activity.titleEn !== null }),
  );

  const committeesResult = await getCommittees();
  const committees = committeesResult.ok
    ? [committeesResult.data.current, ...committeesResult.data.upcoming, ...committeesResult.data.previous].filter((c) => c !== null)
    : [];
  const committeeEntries: MetadataRoute.Sitemap = committees.map((committee) =>
    entry(`/committee/${committee.slug}`, { changeFrequency: "monthly", priority: 0.5, hasTranslation: committee.name_en !== null }),
  );

  const membersResult = await getMemberDirectory();
  // Member profiles have no `_en` field (personal names/bios aren't
  // translated content) — the same page renders at both locales, so it's
  // always treated as having a translation for hreflang purposes.
  const memberEntries: MetadataRoute.Sitemap = (membersResult.ok ? membersResult.data : [])
    .filter((m) => m.public_slug !== null)
    .map((member) => entry(`/members/${member.public_slug}`, { changeFrequency: "monthly", priority: 0.4 }));

  // Archived and expired notices stay listed: they are institutional history, not removed pages.
  const noticesResult = await getNoticeSitemap();
  const noticeEntries: MetadataRoute.Sitemap = (noticesResult.ok ? noticesResult.data : []).map((notice) =>
    entry(`/notices/${notice.slug}`, {
      lastModified: notice.updated_at ? new Date(notice.updated_at) : new Date(),
      changeFrequency: "monthly",
      priority: 0.6,
      // The sitemap-list endpoint doesn't carry title_en, so this can't check
      // per-notice translation state — always offered, and the fallback
      // policy's own canonical-points-to-bn rule (Section E) is what
      // prevents an untranslated notice from being treated as duplicate
      // content even when both alternates are listed here.
    }),
  );

  const jobsResult = await getJobPostings();
  const jobEntries: MetadataRoute.Sitemap = (jobsResult.ok ? jobsResult.data : []).map((job) =>
    entry(`/recruitment/${job.slug}`, {
      lastModified: job.published_at ? new Date(job.published_at) : new Date(),
      changeFrequency: "weekly",
      priority: 0.6,
      hasTranslation: job.title_en !== null,
    }),
  );

  return [...staticEntries, ...activityEntries, ...committeeEntries, ...memberEntries, ...noticeEntries, ...jobEntries];
}
