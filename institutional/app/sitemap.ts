import type { MetadataRoute } from "next";
import { org, recentActivities } from "@/lib/content";

export default function sitemap(): MetadataRoute.Sitemap {
  const routes = ["", "/about", "/activities", "/organization", "/events", "/recruitment", "/membership", "/contact"];

  const staticEntries: MetadataRoute.Sitemap = routes.map((route) => ({
    url: `${org.website}${route}`,
    lastModified: new Date(),
    changeFrequency: route === "" ? "daily" : "weekly",
    priority: route === "" ? 1 : 0.7,
  }));

  const activityEntries: MetadataRoute.Sitemap = recentActivities.map((activity) => ({
    url: `${org.website}/activities/${activity.slug}`,
    lastModified: new Date(),
    changeFrequency: "monthly",
    priority: 0.5,
  }));

  return [...staticEntries, ...activityEntries];
}
