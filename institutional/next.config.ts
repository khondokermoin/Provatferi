import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  images: {
    remotePatterns: [
      { protocol: "https", hostname: "cms.provatferi.org", pathname: "/wp-content/uploads/**" },
    ],
  },
  experimental: {
    // Next.js defaults this to 1mb, which a real applicant's photo alone
    // routinely exceeds — the volunteer application form (photo up to 5MB +
    // CV up to 5MB, both validated server-side) submits through a Server
    // Action, so the request never reaches Laravel's own 5MB-per-file check;
    // Next.js rejects it first with "Body exceeded 1 MB limit", surfaced to
    // the visitor as a generic error boundary. 15mb covers both files near
    // their max plus multipart boundary/field overhead.
    serverActions: {
      bodySizeLimit: "15mb",
    },
  },
};

export default nextConfig;
