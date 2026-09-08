import type { MetadataRoute } from "next";
import { org } from "@/lib/content";

export default function robots(): MetadataRoute.Robots {
  return {
    rules: { userAgent: "*", allow: "/" },
    sitemap: `${org.website}/sitemap.xml`,
  };
}
