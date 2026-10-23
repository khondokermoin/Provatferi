import type { NextConfig } from "next";

type ImageHost = NonNullable<NonNullable<NextConfig["images"]>["remotePatterns"]>[number];

// The local WordPress of the XAMPP set-up. A development convenience only: the Hostinger server's own `localhost` must never be a
// host the production image optimizer may fetch from (2026-10-23 review of the Next.js and sharp image advisories).
const developmentImageHosts: ImageHost[] =
  process.env.NODE_ENV === "production" ? [] : [{ protocol: "http", hostname: "localhost", pathname: "/project/cms/wp-content/uploads/**" }];

const nextConfig: NextConfig = {
  images: {
    remotePatterns: [
      ...developmentImageHosts,
      { protocol: "https", hostname: "cms.provatferi.org", pathname: "/wp-content/uploads/**" },
      { protocol: "https", hostname: "secure.gravatar.com", pathname: "/avatar/**" },
    ],
    // `<Image>` here only ever shows CMS and Gravatar pictures (components/ArticleCard.tsx, app/(site)/author/[slug]/page.tsx):
    // no file of this site's own is optimized, so none is accepted by the optimizer.
    localPatterns: [],
  },
};

export default nextConfig;
