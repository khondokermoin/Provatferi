# Dependencies of the Node applications

How the two Next.js applications are kept free of known vulnerabilities, what was patched on 2026-10-23, and what to run for the
next upgrade. (PHP/Composer dependencies of `admin-erp/` are a separate subject.)

## What runs, and where

| Application | Folder | Production | Built and deployed by |
|---|---|---|---|
| Institutional site | `institutional/` | provatferi.org (Hostinger Node.js website) | `git archive` of the folder → `hosting_deploy` (node 22, app type `next`, root `institutional`, output `.next`, build script `build` = `next build --webpack`) → cache clear |
| Sahittopata | `frontend/` | sahittopata.provatferi.org | the same, root `frontend`, build script **`build:hostinger`** (= `next build --webpack`) |
| Parked test site | built from `frontend/` by Hostinger's **GitHub auto-deploy on every push of `main`** with `npm run build` (Turbopack) | provatferi.westernwatchbd.com | not by us: the build fails in Hostinger's container (the PostCSS loader process dies) and is left alone — see "What was not touched" |

Other `package.json` files in the repository are not servers: `admin-erp/` (asset build for the Laravel app), the `poem-archive`
WordPress theme and the `Zircos_v4.0` admin template (vendored assets).

## How a version is checked

1. `npm audit` and `npm audit --omit=dev` inside each application. Never `npm audit fix --force`: its "fix" for a dev-only chain was
   once a *downgrade* of `eslint-config-next` by two majors.
2. `institutional/__tests__/dependency-floor.test.mts` (part of `npm test`) reads **both lockfiles** — the file Hostinger's
   `npm install` follows — and fails if any copy of `next`, `sharp` or `source-map-js`, at any depth, is older than the patched
   version, or if `next` / `eslint-config-next` stop being exact, matching pins.
3. On production, independently of our own files: Hostinger's scan of what is *deployed* (MCP operation
   `hosting_nodejs_list-vulnerabilities`, per Node.js website; it lags the deploy by a scan interval), and the version the running
   site sends to a browser — `window.next.version` — read in a real Chrome.

## The 2026-10-23 patch (Next.js 16.3.6 → 16.4.2, sharp 0.35.4 → 0.35.5, source-map-js 1.2.1 → 1.2.2)

`npm install --save-exact next@16.4.2 eslint-config-next@16.4.2` and `npm update source-map-js` in both applications. The lockfile
change is exactly 40 packages changing version (`next`, `@next/*`, `eslint-config-next`, `sharp` and its `@img/sharp-*` binaries,
`source-map-js`), none added, none removed. `npm audit`: 0 vulnerabilities, with and without dev dependencies.

16.4.2 rather than the newest 16.4.3: 16.4.1 is the first release with the fixes (14 Oct); 16.4.2 (15 Oct) is the preferred, soaked
release; 16.4.3 was published the same afternoon, carries no security fix, and was left for a later, deliberate bump.

| Advisory | What | Fixed in | Institutional site | Sahittopata |
|---|---|---|---|---|
| GHSA-673q-mjm6-r5xr (critical) | RCE through `use cache` functions that return typed arrays | next 16.4.1 | not used (no `use cache`, no Cache Components) | not used |
| GHSA-2464-fhr4-w9xw (critical) | RCE in the image optimizer with SVG input (librsvg in sharp, glibc Linux) | next 16.4.1, sharp 0.35.5 | **the optimizer is live** (the two logos, every page), on Hostinger's glibc Linux; its inputs were any public file plus the CMS host | **live** for CMS and Gravatar pictures |
| GHSA-p3m5-59mp-jv5r (high) | a crafted request holds the CPU of an App Router app that has a Server Action | next 16.4.1 | **applicable**: nine Server Action modules, public (login, membership, committee registration, volunteer form fallback) | no Server Actions |
| GHSA-cjq9-62q9-8jv4 | SSRF through an allow-listed remote host in the optimizer | next 16.3.8 | needs a remote pattern: one existed (the CMS host) and was never used — removed | CMS, Gravatar and a development `localhost` pattern — the last is now development-only |
| GHSA-mcj8-r9mp-w47p | cache poisoning of SSG/ISR with a root-level catch-all | next 16.3.8 | no catch-all route | an ISR home page and a root `/[category]` route: not a catch-all, but the closest thing the application has |
| GHSA-4jqv-mc3x-m676 / -f87g-xv8r-7p7x / -h694-7cp9-m8p3 / -3w37-wq28-93x7 | Pages Router cache poisoning; metadata image routes; `use cache` leaks | next 16.3.8 | none of those features | none |
| GHSA-39w2-rjm5-chcv | the *development* server's MCP endpoint | next 16.3.8 | `next dev` only | same |
| GHSA-wq5f-xc86-pv6w (high) | sharp: use-after-free in librsvg | sharp 0.35.5 | reachable only through the optimizer | same |
| GHSA-68fv-2mgg-jv7q (high) | source-map-js: an indexed source map blocks the event loop | source-map-js 1.2.2 | build time only (PostCSS / Tailwind), nothing parses a visitor's source map | same |
| GHSA-vfj7-8cjw-p6xm | `braces` stack exhaustion (reached `eslint-config-next` through dev tooling) | — | **withdrawn by GitHub on 2026-10-23** ("not a practically exploitable vulnerability") | same |

Next 16.4 also changed behaviour; what was seen when the old and the new build were run side by side is in the upgrade record
(`institutional/deploy/state/last-deployed.json`). Two are worth knowing: a Server Action id the server does not know now answers
**409 "Server Action unavailable"** (it was 404 "Server action not found" — `scripts/cloudflare-upload-probe.mjs` accepts either),
and the early-hint `Link:` response header (a preload of the stylesheet) is present on some renders of a page and absent on others, in
both versions (measured both ways round), so it is recorded but never compared.

## The image optimizer: only what is drawn with `next/image` may reach it

`/_next/image` decodes with sharp, so what it accepts is the attack surface of every image advisory. Therefore:

- **Institutional site** — `images.remotePatterns` is empty and `images.localPatterns` is `/brand/**`: the optimizer processes the
  two logos and nothing else. No remote host, no other public file, so no SVG, can reach the decoder through it.
  `__tests__/next-config.test.mts` pins this and scans every `<Image>` in the code: each is `unoptimized` or a `/brand/*.png` logo.
  To optimize another image, add it to the allow-list on purpose.
- **Sahittopata** — remote patterns are the CMS uploads and Gravatar avatars only (plus `http://localhost/…` outside production);
  `localPatterns` is empty, so no file of the site itself is accepted.
- Neither enables `dangerouslyAllowSVG`, `dangerouslyAllowLocalIP` or AVIF output; an SVG is refused by content, not by name. A
  throwaway lab app (Next 16.4.2, sharp 0.35.5) was given nine SVG disguises — plain, with an XML prolog, with a byte-order mark,
  with leading whitespace, behind a 9 000-byte comment, an external reference, named `.png`, named `.jpg`, gzip bytes named
  `.png` — and every one was refused with a 400 (seven as "image type is not allowed", two as "not a valid image"), while
  JPEG, PNG, WebP and AVIF inputs, a real portrait and the real carousel image were optimized; the old build gave the same 76 answers.
- Uploaded pictures (Leadership portraits, the homepage carousel, applicants' photos) are served by the ERP as plain files and drawn
  with `<img>`; they never pass through the optimizer.

## Upgrading Next.js the next time

Run in `institutional/` and `frontend/`; pins stay exact.

1. `npm install --save-exact next@<x> eslint-config-next@<x>`, then `npm audit` and `npm audit --omit=dev` — read what remains,
   and `npm ls next sharp source-map-js` for the real tree. Compare the lockfile before/after: only version changes, no additions.
2. `npm test`, `npx tsc --noEmit`, `npx eslint .`, `npm run build` (webpack; its post-build checks run).
3. Build the **old** commit too (`git archive <old> institutional | tar -x -C <dir>`, `npm ci`, build) and run both side by side:
   `scripts/site-regression-qa.mjs` (`--phase headers-*` and `pages-*`, `--suite institutional|sahittopata`) — status, headers,
   SEO data, text, layout rectangles, pixels, console, images; `scripts/framework-e2e-qa.mjs` (signed revalidation, Server Action
   login and cookies, the volunteer route handler and its proof cookie, 404s, locale rewrite — against a stub backend, no production
   data); `scripts/request-storm-qa.mjs`, `scripts/nav-qa.mjs`, `scripts/carousel-qa.mjs`; the Leadership guards
   (`scripts/leadership-seo-qa.mjs --phase baseline|compare --keep-actions|seo|verify`).
4. Deploy (`hosting_deploy` with the archive; Sahittopata with `buildScript: build:hostinger`), clear the cache, then read
   `window.next.version`, `hosting_nodejs_list-vulnerabilities` and the same QA scripts against production. A photograph is not
   bit-identical from one load to the next, so the pixel comparison allows 2/255 outside photographs and 32/255 inside them; every
   measurement is compared exactly.

## What was not touched

`provatferi.westernwatchbd.com` (a Hostinger Node.js site) is the retired test deployment. Hostinger answers `/` with a 301 to
Sahittopata, but **every other path tried is still served by the old Next.js app** (`/search`, `/blog`, `/article/hello-world`,
`/sitemap.xml`, `/robots.txt` answer 200, an unknown path 404, `/_next/image` 400; version 16.3.4 on 2026-10-23, so with the
advisories above and `next/og` GHSA-vcvr-r3jv-pc5j as well), and its GitHub auto-deploy rebuilds `frontend/` with Turbopack on each
push and fails.
It belongs to the `*.westernwatchbd.com` family that is pending the owner's decision (`WESTERNWATCHBD_RETIREMENT_PLAN.md`); the
recommendation is to retire the Node.js site (keeping the 301) rather than upgrade it. Nothing there was changed.
