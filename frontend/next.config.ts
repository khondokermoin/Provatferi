import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  images: {
    remotePatterns: [
      { protocol: "http", hostname: "localhost", pathname: "/project/cms/wp-content/uploads/**" },
      { protocol: "https", hostname: "cms.provatferi.org", pathname: "/wp-content/uploads/**" },
      { protocol: "https", hostname: "secure.gravatar.com", pathname: "/avatar/**" },
    ],
  },
};

export default nextConfig;
