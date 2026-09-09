import type { MetadataRoute } from "next";
import { org } from "@/lib/content";
import { getActivitiesWithFallback } from "@/lib/api/activities";

// Activity URLs come from the exact same source as the listing page and
// generateStaticParams (getActivitiesWithFallback) — never an independent
// read of lib/content.ts here, or a sitemap/route drift like the one this
// cutover was built to avoid becomes possible again.
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const routes = ["", "/about", "/activities", "/organization", "/events", "/recruitment", "/membership", "/contact"];

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

  return [...staticEntries, ...activityEntries];
}
