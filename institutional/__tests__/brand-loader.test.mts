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
 *   - every upload form shows it through ONE submit control (components/SubmitControl.tsx), with the exact wording
 *     and the ~2 s helper rule the owner specified.
 */

const ROOT = new URL("..", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1");
const read = (...parts: string[]) => readFileSync(join(ROOT, ...parts), "utf8");
const css = read("app", "globals.css").replace(/\r\n/g, "\n");
const loader = read("components", "BrandLoader.tsx");
const form = read("components", "VolunteerApplicationForm.tsx");
const control = read("components", "SubmitControl.tsx");
const hook = read("lib", "use-upload-submit.ts");
const messages = read("lib", "form-messages.ts");
/** Every form that posts a file and so shows the submit state. */
const UPLOAD_FORMS = ["VolunteerApplicationForm.tsx", "CommitteeRegistrationForm.tsx", "CommitteeCorrectionForm.tsx", "MembershipApplicationForm.tsx", "MemberProfileEditForm.tsx"];

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
  for (const file of [...UPLOAD_FORMS, "SubmitControl.tsx", "SuccessNote.tsx"]) {
    const source = read("components", file);
    assert.doesNotMatch(source, /pf-spin|submit-spinner|<svg[^>]*animate|animation:/i, `${file} draws its own spinner`);
  }
});

test("every upload form shows the brand loader through the ONE submit control — none renders a loader or a button of its own", () => {
  for (const file of UPLOAD_FORMS) {
    const source = read("components", file);
    assert.match(source, /<SubmitControl[\s\S]*?busy=\{busy\}/, `${file} must render <SubmitControl busy={busy} ...>`);
    assert.doesNotMatch(source, /BrandLoader|<button[^>]*type="submit"/, `${file} must not render its own loader or submit button — SubmitControl is the one`);
  }
});

test("the submit control shows the brand loader in its button, silently (the status line is the one live region)", () => {
  assert.match(control, /import BrandLoader, \{ preloadBrandLoader \} from "@\/components\/BrandLoader"/);
  assert.match(control, /<BrandLoader size="sm" announce=\{false\} label=\{busyLabel\} \/>/);
  assert.match(control, /preloadBrandLoader\(\)/, "the mark images must be warmed up before the click");
  assert.match(control, /disabled=\{busy\}/, "disabled the instant it is clicked");
  assert.match(control, /aria-busy=\{busy \|\| undefined\}/);
  assert.match(control, /role="status" aria-live="polite"/, "the one live region");
});

test("the owner's wording, in both languages, and the ~2 s helper rule", () => {
  for (const text of [
    "আবেদন জমা হচ্ছে…",
    "Submitting application…",
    "অনুগ্রহ করে অপেক্ষা করুন, আপনার তথ্য নিরাপদভাবে জমা হচ্ছে।",
    "Please wait while your application is being submitted securely.",
  ]) assert.ok(form.includes(text), `missing in the volunteer form: ${text}`);
  // The other forms share the same two helper sentences from one module, plus a "saving" one for the profile.
  assert.ok(messages.includes("অনুগ্রহ করে অপেক্ষা করুন, আপনার তথ্য নিরাপদভাবে জমা হচ্ছে।"));
  assert.ok(messages.includes("Please wait while your application is being submitted securely."));
  assert.ok(messages.includes("অনুগ্রহ করে অপেক্ষা করুন, আপনার তথ্য নিরাপদভাবে সংরক্ষণ করা হচ্ছে।"));
  assert.match(read("components", "MembershipApplicationForm.tsx"), /Submitting application…/);
  assert.match(hook, /slowAfterMs \?\? 2000/);
  assert.match(hook, /setTimeout\(\(\) => setSlow\(true\), slowAfterMs\)/, "the helper sentence must wait for the slow threshold, not show at once");
  for (const file of UPLOAD_FORMS) assert.doesNotMatch(read("components", file), /%|progress=|<progress/i, `${file}: no fake percentage`);
});

test("the idle label cannot flash back between a success and the confirmation page", () => {
  // The volunteer form hands over to the confirmation page: its success answer is held (the form stays busy)...
  const answer = form.match(/onAnswer: \(result\) => \{([\s\S]*?)\n    \},/);
  assert.ok(answer, "onAnswer not found");
  assert.match(answer[1], /result\.status !== "success"\) return;[\s\S]*router\.push\(successHref\);[\s\S]*return "hold"/, "success must navigate and hold the form busy, never go back to idle");
  // ...and the hook honours the hold before it ever releases the lock or sets the phase back to idle.
  const afterAnswer = hook.match(/onAnswer\?\.\(result\) === "hold"\) \{([\s\S]*?)\n      \}/);
  assert.ok(afterAnswer, "the hold branch is gone from the hook");
  assert.match(afterAnswer[1], /setPhase\("navigating"\)[\s\S]*return;/);
  assert.doesNotMatch(afterAnswer[1], /setPhase\("idle"\)|lock\.current = false/);
});
