import type { MetadataRoute } from "next";
import { org } from "@/lib/content";
import { getActivitiesWithFallback } from "@/lib/api/activities";
import { getCommittees } from "@/lib/api/committees";

// Activity URLs come from the exact same source as the listing page and
// generateStaticParams (getActivitiesWithFallback) — never an independent
// read of lib/content.ts here, or a sitemap/route drift like the one this
// cutover was built to avoid becomes possible again. Committee URLs have no
// such generateStaticParams counterpart to stay in sync with (the detail
// page renders on demand, §29) — a transient getCommittees() failure here
// only ever shrinks the sitemap for one crawl, it never produces a 404 for
// a URL the sitemap listed, since both read the same live endpoint.
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const routes = ["", "/about", "/activities", "/organization", "/committee", "/events", "/recruitment", "/membership", "/contact"];

  const staticEntries: MetadataRoute.Sitemap = routes.map((route) => ({
    url: `${org.website}${route}`,
    lastModified: new Date(),
    changeFrequency: route === "" ? "daily" : "weekly",
    priority: route === "" ? 1 : 0.7,
  }));

  const { activities } = await getActivitiesWithFallback();
  const activityEntries: MetadataRoute.Sitemap = activities.map((activity) => ({
    url: `${org.website}/activities/${activity.slug}`,
    lastModified: new Date(),
    changeFrequency: "monthly",
    priority: 0.5,
  }));

  const committeesResult = await getCommittees();
  const committees = committeesResult.ok
    ? [committeesResult.data.current, ...committeesResult.data.upcoming, ...committeesResult.data.previous].filter((c) => c !== null)
    : [];
  const committeeEntries: MetadataRoute.Sitemap = committees.map((committee) => ({
    url: `${org.website}/committee/${committee.slug}`,
    lastModified: new Date(),
    changeFrequency: "monthly",
    priority: 0.5,
  }));

  return [...staticEntries, ...activityEntries, ...committeeEntries];
}
