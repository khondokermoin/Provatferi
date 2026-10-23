import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  images: {
    // The image optimizer (/_next/image, which decodes with sharp) has exactly one job on this site: the two brand logos in
    // /public/brand, drawn by components/BrandLogo.tsx. So nothing else may be handed to it (2026-10-23 review of the Next.js and
    // sharp image advisories): no remote host is allow-listed — the SSRF advisory needs one — and the only local files it accepts are
    // the logos, so no uploaded or look-alike file (an SVG or any other) can reach the decoder through this endpoint. Optimizing
    // another image means adding it here deliberately; __tests__/next-config.test.mts explains and pins this.
    remotePatterns: [],
    localPatterns: [{ pathname: "/brand/**", search: "" }],
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
