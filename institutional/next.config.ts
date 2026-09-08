import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  images: {
    remotePatterns: [
      { protocol: "https", hostname: "cms.provatferi.org", pathname: "/wp-content/uploads/**" },
    ],
  },
};

export default nextConfig;
