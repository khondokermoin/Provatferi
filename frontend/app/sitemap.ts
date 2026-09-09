import type { MetadataRoute } from "next";
import { SITE_URL } from "./layout";
import { getCategories, getPosts } from "@/lib/wp-api";

/**
 * Built entirely from live WordPress data — no hardcoded article/category
 * list. An empty CMS yields a sitemap with just the homepage, which is
 * correct: never list a page that doesn't have real content behind it.
 */
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const entries: MetadataRoute.Sitemap = [
    { url: SITE_URL, lastModified: new Date(), changeFrequency: "daily", priority: 1 },
  ];

  const [posts, categories] = await Promise.all([
    getPosts({ perPage: 100 }).catch(() => []),
    getCategories().catch(() => []),
  ]);

  for (const post of posts) {
    entries.push({
      url: `${SITE_URL}/article/${post.slug}`,
      lastModified: new Date(post.date),
      changeFrequency: "weekly",
      priority: 0.8,
    });
  }

  for (const category of categories) {
    if (category.count === 0) continue; // an empty category isn't worth indexing
    entries.push({
      url: `${SITE_URL}/${category.slug}`,
      changeFrequency: "weekly",
      priority: 0.6,
    });
  }

  return entries;
}
