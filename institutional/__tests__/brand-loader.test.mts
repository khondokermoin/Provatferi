import assert from "node:assert/strict";
import { existsSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";
import test from "node:test";

/**
 * The brand loader is ONE component (components/BrandLoader.tsx) built on the OFFICIAL icon mark, not the
 * wide wordmark and not a generic spinner. These tests pin the decisions that a build, a lint run or a
 * glance at a screenshot would not catch:
 *   - the mark comes from the official icon PNGs (resized copies, see scripts/build-brand-marks.mjs);
 *   - the only motion is gated on prefers-reduced-motion: no-preference, so reduced motion gets a still mark;
 *   - the light and dark mark are swapped by [data-theme], like the logos;
 *   - no second loader / spinner exists anywhere to drift away from it;
 *   - the volunteer form shows it, with the exact wording and the ~2 s helper rule the owner specified.
 */

const ROOT = new URL("..", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1");
const read = (...parts: string[]) => readFileSync(join(ROOT, ...parts), "utf8");
const css = read("app", "globals.css").replace(/\r\n/g, "\n");
const loader = read("components", "BrandLoader.tsx");
const form = read("components", "VolunteerApplicationForm.tsx");

test("the loader uses the official icon mark and never the wide wordmark", () => {
  assert.match(loader, /provatferi-icon-light-256\.png/);
  assert.match(loader, /provatferi-icon-dark-256\.png/);
  assert.doesNotMatch(loader, /provatferi-(light|dark)\.png/, "the wordmark logos (provatferi-light/dark.png) are for the header and footer, not the loader");
});

test("the two mark copies exist, are 256x256 PNGs, and are small enough to be on screen at once", () => {
  for (const variant of ["light", "dark"]) {
    const file = join(ROOT, "public", "brand", `provatferi-icon-${variant}-256.png`);
    assert.ok(existsSync(file), `${file} is missing — run node scripts/build-brand-marks.mjs`);
    assert.ok(statSync(file).size < 16 * 1024, `${variant} mark is ${statSync(file).size} bytes — a loader image must stay tiny`);
    const bytes = readFileSync(file);
    assert.equal(bytes.subarray(1, 4).toString("ascii"), "PNG");
    assert.equal(bytes.readUInt32BE(16), 256, "width");
    assert.equal(bytes.readUInt32BE(20), 256, "height");
    assert.ok(existsSync(join(ROOT, "public", "brand", `provatferi-icon-${variant}.png`)), "the official master it is built from must stay in public/brand");
  }
});

test("all motion is gated on prefers-reduced-motion: no-preference", () => {
  // Every `animation:` that belongs to the loader sits inside a no-preference media block.
  const gated = css.match(/@media \(prefers-reduced-motion: no-preference\) \{\n  \.brand-loader-arc \{ animation: pf-brand-orbit [^}]*\}\n  \.brand-loader:not\(\[data-size="sm"\]\) \.brand-loader-icon \{ animation: pf-brand-breathe [^}]*\}\n\}/);
  assert.ok(gated, "the orbit and breathing animations must live in one @media (prefers-reduced-motion: no-preference) block");
  const outside = css.replace(gated[0], "");
  assert.doesNotMatch(outside, /\.brand-loader[^{]*\{[^}]*animation\s*:/, "a loader animation exists outside the no-preference block");
  assert.doesNotMatch(css, /@keyframes pf-spin|\.submit-spinner/, "the old generic spinner must stay gone");
});

test("light and dark marks are swapped by [data-theme], exactly like the logos", () => {
  assert.match(css, /\.brand-loader-icon-dark \{ display: none; \}/);
  assert.match(css, /:root\[data-theme="dark"\] \.brand-loader-icon-light \{ display: none; \}/);
  assert.match(css, /:root\[data-theme="dark"\] \.brand-loader-icon-dark \{ display: block; \}/);
});

test("inside the primary button the mark gets a chip and the ring takes the button's ink (orange would swallow the red disc)", () => {
  assert.match(css, /\.button-primary \.brand-loader \{[^}]*--bl-chip: var\(--surface\)[^}]*\}/);
  assert.match(css, /\.button \.brand-loader, \.button \.brand-loader span \{ font-size: inherit; line-height: inherit; \}/, "`.button span` is the 19px arrow-glyph rule and must not reach the loader");
  assert.match(css, /@supports \(height: 1lh\) \{ \.button \.brand-loader \{ margin-block: calc\(\(1lh - var\(--bl-size\)\) \/ 2\); \} \}/, "the loader's margin box must be exactly one text line (1lh), or the button grows");
});

test("there is exactly one loader: no component draws its own spinner", () => {
  assert.ok(!existsSync(join(ROOT, "components", "SubmitSpinner.tsx")), "SubmitSpinner was replaced by BrandLoader");
  assert.equal((css.match(/^\.brand-loader \{/gm) ?? []).length, 1);
  for (const file of ["VolunteerApplicationForm.tsx", "CommitteeRegistrationForm.tsx", "MembershipApplicationForm.tsx"]) {
    const source = read("components", file);
    assert.doesNotMatch(source, /pf-spin|submit-spinner|<svg[^>]*animate|animation:/i, `${file} draws its own spinner`);
  }
});

test("the volunteer form shows the brand loader in its button, silently (the status line is the one live region)", () => {
  assert.match(form, /import BrandLoader, \{ preloadBrandLoader \} from "@\/components\/BrandLoader"/);
  assert.match(form, /<BrandLoader size="sm" announce=\{false\} label=\{copy\.submitting\} \/>/);
  assert.match(form, /preloadBrandLoader\(\)/, "the mark images must be warmed up before the click");
});

test("the owner's wording, in both languages, and the ~2 s helper rule", () => {
  for (const text of [
    "আবেদন জমা হচ্ছে…",
    "Submitting application…",
    "অনুগ্রহ করে অপেক্ষা করুন, আপনার তথ্য নিরাপদভাবে জমা হচ্ছে।",
    "Please wait while your application is being submitted securely.",
  ]) assert.ok(form.includes(text), `missing: ${text}`);
  assert.match(form, /const SLOW_AFTER_MS = 2000;/);
  assert.match(form, /setTimeout\(\(\) => setSlow\(true\), SLOW_AFTER_MS\)/, "the helper sentence must wait for SLOW_AFTER_MS, not show at once");
  assert.doesNotMatch(form, /%|progress=|<progress/i, "no fake percentage");
});

test("the idle label cannot flash back between a success and the confirmation page", () => {
  const success = form.match(/if \(result\.status === "success"\) \{([\s\S]*?)return;\s*\}/);
  assert.ok(success, "success branch not found");
  assert.match(success[1], /setPhase\("navigating"\)/, "success must keep the form busy (navigating), never go back to idle");
  assert.doesNotMatch(success[1], /setPhase\("idle"\)|submitLock\.current = false/);
});
