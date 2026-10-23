import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { test } from "node:test";
import assert from "node:assert/strict";
import nextConfig from "../next.config.ts";

/**
 * Regression guard for the "Body exceeded 1 MB limit" incident: the
 * volunteer application form (and every other Server Action with a file
 * input — membership, committee registration) submits through a Server
 * Action, which Next.js caps at 1MB by default regardless of what Laravel's
 * own per-file validation allows. A real applicant's photo alone routinely
 * exceeds 1MB, so this silently blocked every real-world submission while
 * every local test (using few-KB fixture files) stayed under the limit and
 * never caught it.
 */
function parseSizeLimit(value: string | number | undefined): number {
  if (typeof value === "number") return value;
  if (typeof value !== "string") return 0;
  const match = value.trim().match(/^(\d+(?:\.\d+)?)\s*(kb|mb|gb)?$/i);
  if (!match) return 0;
  const n = parseFloat(match[1]);
  const unit = (match[2] ?? "").toLowerCase();
  const multiplier = unit === "gb" ? 1024 ** 3 : unit === "mb" ? 1024 ** 2 : unit === "kb" ? 1024 : 1;
  return n * multiplier;
}

test("Server Actions body size limit is raised well above Next.js's 1MB default", () => {
  const limit = nextConfig.experimental?.serverActions?.bodySizeLimit;
  assert.ok(limit, "experimental.serverActions.bodySizeLimit must be set — the 1MB default blocks any real photo upload");
  const bytes = parseSizeLimit(limit);
  // Form validation allows a 5MB photo AND a 5MB CV in the same submission.
  assert.ok(bytes >= 10 * 1024 * 1024, `bodySizeLimit (${limit}) must cover a 5MB photo + 5MB CV plus multipart overhead`);
});

/**
 * The image optimizer (2026-10-23 security review). /_next/image decodes with sharp, and the 2026-10 advisories (an RCE in the
 * optimizer's SVG handling, an SSRF through an allow-listed remote host, and the librsvg bug in sharp < 0.35.5) are all reached through
 * what this endpoint will accept. On this site it has one job — the two brand logos in /public/brand — so the configuration says so, and
 * these tests keep anyone from widening it without noticing.
 */
test("the image optimizer accepts the brand logos and nothing else: no remote host, no other local file, no SVG", () => {
  const images = nextConfig.images ?? {};
  assert.deepEqual(images.remotePatterns ?? [], [], "no remote host may feed the optimizer (the SSRF advisory needs an allow-listed one)");
  assert.deepEqual(images.localPatterns, [{ pathname: "/brand/**", search: "" }], "only /brand/** — the logos — may be optimized");
  assert.deepEqual(images.domains ?? [], [], "the deprecated `domains` list is not used");
  assert.notEqual(images.dangerouslyAllowSVG, true, "SVG stays refused");
  assert.notEqual(images.dangerouslyAllowLocalIP, true, "the optimizer may not fetch from private addresses");
  assert.notEqual(images.unoptimized, true, "the optimizer stays what the logos go through");
  assert.ok(!(images.formats ?? []).includes("image/avif"), "AVIF output stays off: the logos are served as WebP, as before");
});

test("every next/image in the site is either unoptimized or one of the /brand logos", () => {
  const root = new URL("..", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1");
  const files: string[] = [];
  const walk = (dir: string) => {
    for (const entry of readdirSync(join(root, dir), { withFileTypes: true })) {
      const rel = `${dir}/${entry.name}`;
      if (entry.isDirectory()) walk(rel);
      else if (/\.(tsx|ts|jsx|js|mjs)$/.test(entry.name)) files.push(rel);
    }
  };
  for (const dir of ["app", "components", "lib"]) walk(dir);

  let optimized = 0;
  for (const file of files) {
    const source = readFileSync(join(root, file), "utf8");
    if (!/from ["']next\/image["']/.test(source)) continue;
    const elements = source.match(/<Image\b[\s\S]*?\/>/g) ?? [];
    assert.ok(elements.length > 0, `${file} imports next/image but draws no <Image />`);
    for (const element of elements) {
      if (/\bunoptimized\b/.test(element)) continue;
      assert.match(element, /src="\/brand\/[a-z0-9-]+\.png"/, `${file}: an optimized <Image> must be a /brand logo, or the optimizer's allow-list has to grow on purpose`);
      optimized++;
    }
    assert.doesNotMatch(source, /\/_next\/image/, `${file} must not build optimizer addresses by hand`);
  }
  assert.ok(optimized >= 2, "the two logos are the optimized images");
});
