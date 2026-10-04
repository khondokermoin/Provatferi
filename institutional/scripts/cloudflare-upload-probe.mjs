// Probes the EDGE (Cloudflare) with the regression fixtures from scripts/make-trigger-fixtures.mjs, in the three shapes a
// form's upload can arrive in, and reports what each one got:
//
//   old   The Server Action shape the forms used to submit in: POST to the form's page with a `Next-Action` header.
//         The action id is INVALID on purpose (Next answers 404 "action not found" and runs nothing), so this creates
//         nothing. Expected: a fixture with the trigger bytes in the first MiB is refused by Cloudflare (403, a bare
//         edge page); every other fixture gets through to Next (404). This is the class of failure the forms had.
//   new   The route shape they submit in now: a multipart POST to the same-origin route under /api/, no Next-Action
//         header. The payload is INVALID on purpose (no session / an unknown link or season) so nothing is created.
//         Expected for every fixture: the route's own JSON answer, never an edge page.
//   native  The no-JavaScript post: a plain form post to the page, no Next-Action header, an unknown action field.
//         Expected: never refused by the rule.
//
//   node scripts/cloudflare-upload-probe.mjs --base https://provatferi.org --fixtures <dir> [--out report.json]
//        [--slug <a recruitment slug>] [--pace-ms 15000] [--only old,new,native]
//
// Exit code 1 when anything differs from the expectation above. Read-only apart from those rejected requests.

import { readFile, writeFile } from "node:fs/promises";
import { join, resolve } from "node:path";

const arg = (n, d = null) => {
  const i = process.argv.indexOf(`--${n}`);
  return i === -1 ? d : process.argv[i + 1];
};
const base = (arg("base", "https://provatferi.org") ?? "").replace(/\/$/, "");
const fixturesDir = resolve(arg("fixtures", "./trigger-fixtures"));
const reportPath = arg("out");
const slug = arg("slug", "no-such-posting-qa-probe");
const paceMs = Number(arg("pace-ms", "15000"));
const only = (arg("only", "old,new,native") ?? "").split(",");
const origin = new URL(base).origin;
const FAKE_ACTION = "7f00000000000000000000000000000000000000"; // not an action id: Next answers 404 and runs nothing
const BOGUS = "qa-probe-no-such-link-0000000000000000";

const manifest = JSON.parse(await readFile(join(fixturesDir, "manifest.json"), "utf8"));
const names = Object.keys(manifest.files);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** The five forms: the page each lives on (where a Server Action POST used to go) and the route it posts to now. */
const FORMS = [
  { name: "committee registration", page: `/committee/register/${BOGUS}`, route: `/api/committee/register/${BOGUS}`, reachesLaravel: true },
  { name: "committee correction", page: `/committee/register/correct/${BOGUS}`, route: `/api/committee/correct/${BOGUS}`, reachesLaravel: true },
  { name: "membership application", page: "/membership", route: "/api/membership/apply", reachesLaravel: true },
  { name: "member profile", page: "/member/dashboard/profile", route: "/api/member/profile", reachesLaravel: false /* no session cookie: refused by the route before Laravel */ },
  { name: "volunteer application", page: `/recruitment/${slug}/apply`, route: `/api/recruitment/${slug}/apply`, reachesLaravel: true },
];
/** What reaches Laravel is rate-limited there (5-6 a minute, one counter per IP), so those are paced and use two fixtures only. */
const LARAVEL_FIXTURES = ["trigger-dq.jpg", "trigger-sq.png"].filter((n) => names.includes(n));

async function body(fixtureName, fields) {
  const form = new FormData();
  for (const [k, v] of Object.entries(fields)) form.append(k, v);
  const bytes = await readFile(join(fixturesDir, fixtureName));
  form.append("photo", new Blob([bytes], { type: manifest.files[fixtureName].mime }), fixtureName);
  return form;
}

const isEdgeRefusal = (res) => res.status === 403 && /cloudflare/i.test(res.headers.get("server") ?? "");
const describe = (res) => ({ status: res.status, server: res.headers.get("server"), cfRay: Boolean(res.headers.get("cf-ray")), type: (res.headers.get("content-type") ?? "").split(";")[0] });

async function send(url, init) {
  const started = performance.now();
  try {
    const res = await fetch(url, { ...init, redirect: "manual", signal: AbortSignal.timeout(120_000) });
    const text = await res.text().catch(() => "");
    return { ...describe(res), edgeRefusal: isEdgeRefusal(res), text, ms: Math.round(performance.now() - started) };
  } catch (error) {
    return { status: 0, server: null, cfRay: false, type: "", edgeRefusal: false, text: String(error), ms: Math.round(performance.now() - started), error: true };
  }
}

const results = [];
const record = (row) => {
  results.push(row);
  const mark = row.ok ? "PASS" : "FAIL";
  console.log(`${mark}  ${row.shape.padEnd(6)} ${row.form.padEnd(24)} ${row.fixture.padEnd(17)} -> ${String(row.status).padEnd(3)} ${row.edgeRefusal ? "CLOUDFLARE REFUSED" : row.detail}   (expected: ${row.expected})`);
};

// --- old shape: Server Action transport ------------------------------------------------------------------------
async function probeOld() {
  console.log("\n== OLD shape: POST to the page with a Next-Action header (what the forms used to do) ==");
  for (const form of FORMS) {
    for (const fixture of names) {
      const expectBlocked = manifest.files[fixture].oldShape === "blocked";
      const res = await send(`${base}${form.page}`, {
        method: "POST",
        headers: { "Next-Action": FAKE_ACTION, Accept: "text/x-component" },
        body: await body(fixture, { 0: '[{"status":"idle"},"$K1"]' }),
      });
      // Not refused: Next's own answer for an unknown action id (404, and nothing ran).
      const nextAnswered = !res.edgeRefusal && res.status === 404;
      record({
        shape: "old", form: form.name, fixture, status: res.status, edgeRefusal: res.edgeRefusal, cfRay: res.cfRay, server: res.server,
        detail: nextAnswered ? "Next: action not found (let through)" : `${res.type || "no body"}`,
        expected: expectBlocked ? "refused by Cloudflare" : "let through to Next (404)",
        ok: expectBlocked ? res.edgeRefusal : nextAnswered,
      });
    }
  }
}

// --- new shape: same-origin route handler ------------------------------------------------------------------------
const browserHeaders = { Origin: origin, "Sec-Fetch-Site": "same-origin", Accept: "application/json" };

function routeAnswer(res) {
  try {
    const json = JSON.parse(res.text);
    return typeof json?.status === "string" ? json : null;
  } catch {
    return null;
  }
}

async function probeNew() {
  console.log("\n== NEW shape: multipart POST to the same-origin route, no Next-Action header ==");
  // The member profile route answers before Laravel (no session), so every fixture goes through it, unpaced.
  for (const form of FORMS.filter((f) => !f.reachesLaravel)) {
    for (const fixture of names) {
      const res = await send(`${base}${form.route}`, { method: "POST", headers: browserHeaders, body: await body(fixture, { profession: "QA probe" }) });
      const answer = routeAnswer(res);
      record({
        shape: "new", form: form.name, fixture, status: res.status, edgeRefusal: res.edgeRefusal, cfRay: res.cfRay, server: res.server,
        detail: answer ? `route JSON: ${answer.status}` : `${res.type || "no body"} ${res.text.slice(0, 40)}`,
        expected: "route answers JSON 'unauthenticated' (401), never an edge page",
        ok: !res.edgeRefusal && res.status === 401 && answer?.status === "unauthenticated",
      });
    }
  }
  // The rest reach Laravel with an invalid payload: a few fixtures, paced to stay under its per-IP rate limit.
  for (const form of FORMS.filter((f) => f.reachesLaravel)) {
    for (const fixture of LARAVEL_FIXTURES) {
      const fields = form.route.includes("membership") ? { membership_season_id: "0", membership_type_id: "0", applicant_name: "QA probe", applicant_email: "qa-probe@example.invalid", applicant_phone: "0" } : { full_name: "QA probe" };
      const res = await send(`${base}${form.route}`, { method: "POST", headers: browserHeaders, body: await body(fixture, fields) });
      const answer = routeAnswer(res);
      record({
        shape: "new", form: form.name, fixture, status: res.status, edgeRefusal: res.edgeRefusal, cfRay: res.cfRay, server: res.server,
        detail: answer ? `route JSON: ${answer.status}` : `${res.type || "no body"} ${res.text.slice(0, 40)}`,
        expected: "route answers JSON (validation or error: nothing is created), never an edge page",
        ok: !res.edgeRefusal && res.status === 200 && (answer?.status === "validation" || answer?.status === "error"),
      });
      await sleep(paceMs);
    }
  }
}

// --- native: the no-JavaScript form post -------------------------------------------------------------------------
async function probeNative() {
  console.log("\n== NATIVE shape: a plain form post to the page (no JavaScript, no Next-Action header) ==");
  const fixture = names.find((n) => manifest.files[n].oldShape === "blocked") ?? names[0];
  const res = await send(`${base}/membership`, { method: "POST", body: await body(fixture, { "$ACTION_ID_7f00000000000000000000000000000000000000": "" }) });
  record({
    shape: "native", form: "membership application", fixture, status: res.status, edgeRefusal: res.edgeRefusal, cfRay: res.cfRay, server: res.server,
    detail: res.type || "no body", expected: "not refused by the rule (any answer but a Cloudflare 403)", ok: !res.edgeRefusal && res.status !== 0,
  });
}

const started = Date.now();
if (only.includes("old")) await probeOld();
if (only.includes("new")) await probeNew();
if (only.includes("native")) await probeNative();

const failed = results.filter((r) => !r.ok);
const count = (shape, refused) => results.filter((r) => r.shape === shape && r.edgeRefusal === refused).length;
console.log(`\nold shape:    ${count("old", true)} refused by Cloudflare, ${count("old", false)} let through   (the refused ones are exactly the fixtures that hold the trigger bytes in the first MiB)`);
console.log(`new shape:    ${count("new", true)} refused by Cloudflare, ${count("new", false)} answered by the route`);
console.log(`native shape: ${count("native", true)} refused by Cloudflare, ${count("native", false)} let through`);
console.log(`${results.length} probes in ${Math.round((Date.now() - started) / 1000)} s — ${failed.length ? `${failed.length} FAILED` : "all as expected"}`);

if (reportPath) {
  await writeFile(
    resolve(reportPath),
    JSON.stringify({ base, at: new Date().toISOString(), windowBytes: manifest.windowBytes, results: results.map((row) => ({ ...row, text: undefined })) }, null, 2),
  );
  console.log(`report: ${resolve(reportPath)}`);
}
process.exit(failed.length ? 1 : 0);
