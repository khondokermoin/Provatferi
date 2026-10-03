This is a [Next.js](https://nextjs.org) project bootstrapped with [`create-next-app`](https://nextjs.org/docs/app/api-reference/cli/create-next-app).

## Getting Started

First, run the development server:

```bash
npm run dev
# or
yarn dev
# or
pnpm dev
# or
bun dev
```

Open [http://localhost:3000](http://localhost:3000) with your browser to see the result.

You can start editing the page by modifying `app/page.tsx`. The page auto-updates as you edit the file.

This project uses [`next/font`](https://nextjs.org/docs/app/building-your-application/optimizing/fonts) to automatically optimize and load [Geist](https://vercel.com/font), a new font family for Vercel.

## Learn More

To learn more about Next.js, take a look at the following resources:

- [Next.js Documentation](https://nextjs.org/docs) - learn about Next.js features and API.
- [Learn Next.js](https://nextjs.org/learn) - an interactive Next.js tutorial.

You can check out [the Next.js GitHub repository](https://github.com/vercel/next.js) - your feedback and contributions are welcome!

## Deploy on Vercel

The easiest way to deploy your Next.js app is to use the [Vercel Platform](https://vercel.com/new?utm_medium=default-template&filter=next.js&utm_source=create-next-app&utm_campaign=create-next-app-readme) from the creators of Next.js.

Check out our [Next.js deployment documentation](https://nextjs.org/docs/app/building-your-application/deploying) for more details.

## Deploying to Hostinger (sahittopata.provatferi.org)

Hostinger's Node build container crashes Turbopack production builds (the PostCSS
loader subprocess dies), so the archive build for sahittopata runs
`npm run build:hostinger` (`next build --webpack`), **not** `npm run build`:

1. `git archive --format=tar.gz -o frontend-<sha>.tar.gz HEAD frontend`
2. upload it over TUS to the sahittopata.provatferi.org website, then start a build:
   node 22, app_type `next`, root_directory `frontend`, output_directory `.next`,
   build_script **`build:hostinger`**, package_manager `npm`, source_type `archive`.
3. clear the website cache (it also purges the CDN) and check `/`, `/search`,
   `/article/<slug>` in a browser.

`build` is left on Turbopack on purpose: the GitHub-connected
`provatferi.westernwatchbd.com` site builds this directory with `npm run build` on every
push and is parked behind a 301 — making that build succeed would deploy to a site that
has not been approved for changes. Layouts may only export route-segment fields (that is
why `SITE_URL` lives in `lib/site.ts`); webpack builds type-check that, Turbopack builds
do not.
