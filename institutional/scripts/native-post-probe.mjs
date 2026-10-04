// Replays what a browser with JavaScript DISABLED sends when it submits one of the upload forms: a plain multipart POST to the
// page itself, carrying the hidden `$ACTION_*` fields React rendered, and no `Next-Action` header. Every answer must come back
// within a few seconds as a normal page. It exists because of an incident (2026-10-04): a form that bound its Server Action
// inside the client component made the server's render of that very answer loop forever — the request never completed and the
// Node worker spun at 100% CPU until it was killed — and nothing in a build, a unit test or a normal browser run shows it.
//
//   node scripts/native-post-probe.mjs --base http://127.0.0.1:3100 --state qa-state.json --fixtures <dir> [--slug qa-local-posting]
//        [--cpu-pid <pid of the local next server>] [--allow-remote]
//
// LOCAL by default: against anything but 127.0.0.1/localhost it refuses to run unless --allow-remote is given, because on a
// build that still has the bug a single one of these requests takes the site down. Each probe has a hard timeout and is never
// retried (a retry would only start another spinner). With --cpu-pid it also checks the server is idle afterwards.
//
// It creates real QA rows through the forms (a registration, a corrected submission, an application, a profile revision); use
// it with the QA data from admin-erp/deploy/qa/upload-forms-qa.php and clean up with that script.

import { execFileSync } from "node:child_process";
import { readFile } from "node:fs/promises";
import { resolve } from "node:path";

const arg = (n, d = null) => {
  const i = process.argv.indexOf(`--${n}`);
  return i === -1 ? d : process.argv[i + 1];
};
const base = (arg("base", "http://127.0.0.1:3100") ?? "").replace(/\/+$/, "");
const state = JSON.parse(await readFile(arg("state"), "utf8"));
const fixtures = arg("fixtures");
const slug = arg("slug", "qa-local-posting");
const cpuPid = arg("cpu-pid");
const timeoutMs = Number(arg("timeout-ms", "10000"));
const local = /^https?:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/.test(base);
if (!local && !process.argv.includes("--allow-remote")) {
  console.error(`refusing to POST native Server Action forms to ${base}: on a build with the bound-action bug this takes the site down. Pass --allow-remote only after the fix is verified locally.`);
  process.exit(2);
}
const photoBytes = fixtures ? await readFile(resolve(fixtures, "photo-tiny.png")) : null;
const unesc = (s) => s.replace(/&quot;/g, '"').replace(/&amp;/g, "&").replace(/&#x27;/g, "'");
const results = [];

/** The hidden inputs React rendered for the progressive-enhancement postback. */
const hiddenFields = (html) => [...html.matchAll(/<input type="hidden" name="(\$ACTION[^"]*)"(?: value="([^"]*)")?/g)].map((m) => [m[1], unesc(m[2] ?? "")]);

async function probe(label, { url, cookie = "", fields, file = false, expect, unexpected = null, status = [200] }) {
  const page = await fetch(url, { headers: cookie ? { cookie } : {}, signal: AbortSignal.timeout(timeoutMs) });
  const hidden = hiddenFields(await page.text());
  if (!hidden.length) return results.push({ label, ok: false, why: "the page rendered no $ACTION_* fields (is the QA data valid?)" }) && console.log(`FAIL  ${label}: no hidden action fields`);
  const form = new FormData();
  for (const [k, v] of hidden) form.append(k, v);
  for (const [k, v] of Object.entries(fields)) form.append(k, v);
  if (file && photoBytes) form.append("photo", new Blob([photoBytes], { type: "image/png" }), "photo-tiny.png");
  const started = Date.now();
  const res = await fetch(url, { method: "POST", body: form, redirect: "manual", headers: { Origin: new URL(url).origin, ...(cookie ? { cookie } : {}) }, signal: AbortSignal.timeout(timeoutMs) }).catch((e) => ({ hung: String(e).slice(0, 60) }));
  const ms = Date.now() - started;
  if (res.hung) {
    results.push({ label, ok: false, why: `NO ANSWER within ${timeoutMs} ms (${res.hung}) — the server is probably looping` });
    return console.log(`FAIL  ${label}: no answer in ${timeoutMs} ms — the server render is hanging`);
  }
  const body = res.status >= 300 && res.status < 400 ? `redirect → ${res.headers.get("location")}` : await res.text();
  const ok = (status.includes(res.status) || (res.status >= 300 && res.status < 400)) && expect.test(body) && !(unexpected && unexpected.test(body));
  results.push({ label, ok, ms, status: res.status });
  console.log(`${ok ? "PASS" : "FAIL"}  ${label.padEnd(54)} ${res.status} in ${String(ms).padStart(4)} ms${ok ? "" : `  — ${body.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").slice(0, 160)}`}`);
}

const email = (t) => `khondokermoin2k23+${state.suffix}-${t}@gmail.com`;
const common = { committee_position_id: String(state.positions[0].id), full_name: `QA-NP-${state.suffix}`, phone: "01712345678", provatferi_comment: "QA native post", publishing_consent: "1", accuracy_declaration: "1" };

// bound actions (the pages that once looped): committee registration, committee correction, volunteer application
await probe("registration, native post, validation error", { url: `${base}/committee/register/${state.registrationToken}`, fields: { ...common, email: "not-an-email" }, file: true, expect: /form-field-error/ });
await probe("registration, native post, success", { url: `${base}/committee/register/${state.registrationToken}`, fields: { ...common, full_name: `QA-NR-${state.suffix}`, email: email("nr") }, file: true, expect: /সফলভাবে জমা হয়েছে/ });
await probe("correction, native post, validation error", { url: `${base}/committee/register/correct/${state.corrections.E.token}`, fields: { ...common, email: "bad" }, expect: /form-field-error/ });
// After a SUCCESSFUL correction the single-use link is spent, so the page that is re-rendered around the answer finds no form to show and
// is a 404 (the record IS saved; with JavaScript the form is replaced in place and never reloads). The answer itself still rides in the
// page's flight payload, which is what is asserted here.
await probe("correction, native post, success (the spent link then 404s)", { url: `${base}/committee/register/correct/${state.corrections.E.token}`, fields: { ...common, full_name: `QA-NC-${state.suffix}`, email: email("nc") }, expect: /{"status":"success"}|সংশোধিত তথ্য জমা হয়েছে/, status: [200, 404] });
await probe("volunteer, native post, validation error", { url: `${base}/recruitment/${slug}/apply`, fields: { applicant_name: "QA native", applicant_phone: "abc", applicant_email: "bad" }, expect: /form-summary|form-field-error/ });

// unbound actions (never looped; the probe pins that they still answer)
await probe("membership, native post, success", { url: `${base}/membership?qa=${Date.now()}`, fields: { membership_season_id: String(state.membership.seasonId), membership_type_id: String(state.membership.typeId), applicant_name: `QA-NM-${state.suffix}`, applicant_email: email("nm"), applicant_phone: "01712345678" }, expect: /আপনার আবেদন সফলভাবে জমা হয়েছে/ });

// the member profile needs a session: log in natively, keep the cookie
{
  const loginUrl = `${base}/member/login`;
  const login = hiddenFields(await (await fetch(loginUrl, { signal: AbortSignal.timeout(timeoutMs) })).text());
  const form = new FormData();
  for (const [k, v] of login) form.append(k, v);
  form.append("email", state.member.email);
  form.append("password", state.member.password);
  const res = await fetch(loginUrl, { method: "POST", body: form, redirect: "manual", headers: { Origin: new URL(loginUrl).origin }, signal: AbortSignal.timeout(timeoutMs) }).catch(() => null);
  const cookie = (res?.headers.getSetCookie?.() ?? []).map((c) => c.split(";")[0]).join("; ");
  if (!cookie) {
    results.push({ label: "member profile login", ok: false });
    console.log("FAIL  member login (no session cookie): the profile probe is skipped");
  } else {
    await probe("member profile, native post, success", { url: `${base}/member/dashboard/profile`, cookie, fields: { public_profile_enabled: "0", profession: `QA-NP-${state.suffix}`, bio: "QA native post" }, expect: /সংরক্ষণ করা হয়েছে/ });
  }
}

// the server must be idle afterwards, and still answer a plain request at once
const after = await fetch(`${base}/`, { signal: AbortSignal.timeout(timeoutMs) }).then((r) => r.status).catch(() => 0);
results.push({ label: "the site still answers a plain GET", ok: after === 200 });
console.log(`${after === 200 ? "PASS" : "FAIL"}  the site still answers a plain GET (${after})`);
if (cpuPid) {
  const cpu = () => {
    const out = process.platform === "win32" ? execFileSync("powershell", ["-NoProfile", "-Command", `(Get-Process -Id ${cpuPid}).CPU`]).toString() : execFileSync("ps", ["-o", "cputime=", "-p", cpuPid]).toString();
    return parseFloat(out.trim().replace(/^.*:/, "")) || Number(out);
  };
  const a = cpu();
  await new Promise((r) => setTimeout(r, 6000));
  const b = cpu();
  const busy = b - a;
  results.push({ label: "the server is idle afterwards", ok: busy < 1.5 });
  console.log(`${busy < 1.5 ? "PASS" : "FAIL"}  the server is idle afterwards: ${busy.toFixed(2)} CPU-seconds used in 6 s (pid ${cpuPid})`);
}

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length} native-post probes, ${failed.length} failed`);
process.exit(failed.length ? 1 : 0);
