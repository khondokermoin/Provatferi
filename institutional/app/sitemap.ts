import type { MetadataRoute } from "next";
import { org } from "@/lib/content";
import { getActivitiesWithFallback } from "@/lib/api/activities";
import { getCommittees } from "@/lib/api/committees";
import { getMemberDirectory } from "@/lib/api/member-directory";
import { getNoticeSitemap } from "@/lib/api/notices";
import { getJobPostings } from "@/lib/api/recruitment";

// Activity URLs come from the exact same source as the listing page and
// generateStaticParams (getActivitiesWithFallback) — never an independent
// read of lib/content.ts here, or a sitemap/route drift like the one this
// cutover was built to avoid becomes possible again. Committee, member,
// notice and recruitment detail pages have no generateStaticParams to stay
// in sync with (they render on demand) — a transient API failure here only
// ever shrinks the sitemap for one crawl, it never produces a 404 for a URL
// the sitemap listed, since both read the same live endpoint.
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const routes = ["", "/about", "/activities", "/organization", "/committee", "/members", "/notices", "/events", "/recruitment", "/membership", "/contact"];

  const staticEntries: MetadataRoute.Sitemap = routes.map((route) => ({
    url: `${org.website}${route}`,
    lastModified: new Date(),
    changeFrequency: route === "" || route === "/notices" ? "daily" : "weekly",
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

  const membersResult = await getMemberDirectory();
  const memberEntries: MetadataRoute.Sitemap = (membersResult.ok ? membersResult.data : [])
    .filter((m) => m.public_slug !== null)
    .map((member) => ({
      url: `${org.website}/members/${member.public_slug}`,
      lastModified: new Date(),
      changeFrequency: "monthly",
      priority: 0.4,
    }));

  // Archived and expired notices stay listed: they are institutional history, not removed pages.
  const noticesResult = await getNoticeSitemap();
  const noticeEntries: MetadataRoute.Sitemap = (noticesResult.ok ? noticesResult.data : []).map((notice) => ({
    url: `${org.website}/notices/${notice.slug}`,
    lastModified: notice.updated_at ? new Date(notice.updated_at) : new Date(),
    changeFrequency: "monthly",
    priority: 0.6,
  }));

  const jobsResult = await getJobPostings();
  const jobEntries: MetadataRoute.Sitemap = (jobsResult.ok ? jobsResult.data : []).map((job) => ({
    url: `${org.website}/recruitment/${job.slug}`,
    lastModified: job.published_at ? new Date(job.published_at) : new Date(),
    changeFrequency: "weekly",
    priority: 0.6,
  }));

  return [...staticEntries, ...activityEntries, ...committeeEntries, ...memberEntries, ...noticeEntries, ...jobEntries];
}
