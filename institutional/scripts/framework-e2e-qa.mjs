// What a Next.js upgrade can break and a page screenshot cannot show: Server Actions, cookies set and read on the server, the signed
// cache revalidation, a route handler that answers with Set-Cookie, and the HttpOnly proof cookie behind the volunteer confirmation
// page. Written for the 2026-10-23 security upgrade (Next 16.3.6 -> 16.4.2); run it again for the next one.
//
// It needs NO login and touches NO production data: it starts the BUILT site itself (`next start` from --next-dir, so run `npm run
// build` first) with LARAVEL_API_URL pointing at a stub backend that this script runs. The stub answers the few write endpoints
// the checks use (member login, a volunteer application and its success proof) and forwards every GET to --upstream (the public,
// read-only API), so the pages are real. The revalidation secret is random per run, handed to the child process through its
// environment (never a command line) and never printed.
//
//   node scripts/framework-e2e-qa.mjs --next-dir <institutional folder with a build> --out <dir>
//        [--port 3300] [--stub-port 4010] [--upstream https://admin.provatferi.org]
//
//   F  signed revalidation: a tagged fetch is cached; /api/revalidate with a good signature makes the very next request fresh;
//      a replayed, forged, stale or unknown-tag request is refused
//   M  member login through a Server Action: a validation error is shown; a good login sets the HttpOnly session cookie, redirects,
//      and the next server render sends it back to the API as a Bearer token
//   V  volunteer application through the route handler: the proof cookie is HttpOnly and scoped to the confirmation page; a
//      verified proof shows the Messenger group, no proof / a forged one shows none; Bangla and English
//   N  not-found pages; the proxy's locale rewrite
// Exit code 1 when a check fails.
import { createHmac, randomBytes } from "node:crypto";
import { spawn } from "node:child_process";
import { existsSync, mkdirSync, rmSync, writeFileSync } from "node:fs";
import http from "node:http";
import { createRequire } from "node:module";
import { join } from "node:path";

const arg = (name, fallback) => {
  const i = process.argv.indexOf(`--${name}`);
  return i > -1 ? process.argv[i + 1] : fallback;
};
const NEXT_DIR = arg("next-dir");
const OUT = arg("out");
const PORT = Number(arg("port", "3300"));
const STUB_PORT = Number(arg("stub-port", "4010"));
const UPSTREAM = (arg("upstream", "https://admin.provatferi.org") ?? "").replace(/\/+$/, "");
if (!NEXT_DIR || !OUT) {
  console.error("usage: --next-dir <institutional folder with a build> --out <dir> [--port 3300] [--stub-port 4010] [--upstream <api origin>]");
  process.exit(2);
}
mkdirSync(OUT, { recursive: true });
const SITE = `http://127.0.0.1:${PORT}`;
const require = createRequire(join(NEXT_DIR, "package.json"));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const CHROME = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find((p) => existsSync(p));

const checks = [];
const check = (id, description, ok, detail = "") => {
  const row = { id, description, ok: Boolean(ok), detail: String(detail).slice(0, 500) };
  checks.push(row);
  console.log(`${row.ok ? "PASS" : "FAIL"}  ${id.padEnd(4)} ${description}${row.ok || !row.detail ? "" : `  -> ${row.detail.slice(0, 320)}`}`);
};

// ---------------------------------------------------------------------------------------------------------- the stub backend
const state = { counts: {}, calls: [], mutate: false, loginMode: "validation", token: null, issued: new Map() };
const count = (key) => state.counts[key] ?? 0;
const MESSENGER = "https://m.me/j/AbCdEf123456/";

const readBody = (req) => new Promise((resolve) => { const chunks = []; req.on("data", (c) => chunks.push(c)); req.on("end", () => resolve(Buffer.concat(chunks))); });
const json = (res, status, body) => { const text = JSON.stringify(body); res.writeHead(status, { "content-type": "application/json", "content-length": Buffer.byteLength(text) }); res.end(text); };

async function stubHandler(req, res) {
  const url = new URL(req.url, "http://stub");
  const key = `${req.method} ${url.pathname}`;
  state.counts[key] = count(key) + 1;
  state.calls.push({ method: req.method, path: url.pathname, authorization: req.headers.authorization ?? null });
  const body = await readBody(req);

  if (req.method === "POST" && url.pathname === "/api/v1/member/auth/login") {
    if (state.loginMode === "ok") { state.token = `e2e-token-${randomBytes(8).toString("hex")}`; return json(res, 200, { data: { token: state.token } }); }
    return json(res, 422, { message: "The given data was invalid.", errors: { email: ["E2E-validation-message"] } });
  }
  if (req.method === "GET" && url.pathname === "/api/v1/member/me") return json(res, 401, { message: "Unauthenticated." });

  let m = url.pathname.match(/^\/api\/v1\/public\/recruitment\/([^/]+)\/applications$/);
  if (req.method === "POST" && m) {
    const slug = decodeURIComponent(m[1]);
    const proof = randomBytes(36).toString("base64url");
    state.issued.set(proof, slug);
    return json(res, 201, { data: { application_no: "APP-E2E-0001", success_proof: proof, success_proof_expires_in: 1800 } });
  }
  m = url.pathname.match(/^\/api\/v1\/public\/recruitment\/([^/]+)\/application-success$/);
  if (req.method === "POST" && m) {
    let proof = null;
    try { proof = JSON.parse(body.toString("utf8")).proof; } catch { /* a body that is not JSON verifies nothing */ }
    const ok = typeof proof === "string" && state.issued.get(proof) === decodeURIComponent(m[1]);
    return json(res, 200, { data: { verified: ok, messenger: ok ? { url: MESSENGER } : null } });
  }

  if (req.method === "GET" && url.pathname.startsWith("/api/")) {
    try {
      const upstream = await fetch(`${UPSTREAM}${url.pathname}${url.search}`, { headers: { accept: req.headers.accept ?? "application/json", "accept-language": req.headers["accept-language"] ?? "bn" } });
      let text = await upstream.text();
      if (state.mutate && url.pathname === "/api/v1/public/leadership") {
        const data = JSON.parse(text);
        if (Array.isArray(data.data) && data.data.length > 0) { data.data[0].name_bn += " [E2E-MUTATED]"; if (data.data[0].name_en) data.data[0].name_en += " [E2E-MUTATED]"; }
        text = JSON.stringify(data);
      }
      res.writeHead(upstream.status, { "content-type": upstream.headers.get("content-type") ?? "application/json", "content-length": Buffer.byteLength(text) });
      return res.end(text);
    } catch (error) {
      return json(res, 502, { message: `stub could not reach the upstream: ${String(error).slice(0, 80)}` });
    }
  }
  return json(res, 404, { message: "not found (stub)" });
}

// ---------------------------------------------------------------------------------------------------------- the site under test
let child = null;
const childLog = [];
async function startSite(secret) {
  // A previous run's cached API answers (the data cache lives on disk under .next/cache) must not stand in for this run's.
  rmSync(join(NEXT_DIR, ".next", "cache", "fetch-cache"), { recursive: true, force: true });
  const nextBin = join(NEXT_DIR, "node_modules", "next", "dist", "bin", "next");
  child = spawn(process.execPath, [nextBin, "start", "-p", String(PORT)], {
    cwd: NEXT_DIR,
    env: { ...process.env, NODE_ENV: "production", LARAVEL_API_URL: `http://127.0.0.1:${STUB_PORT}`, REVALIDATE_SECRET: secret },
    stdio: ["ignore", "pipe", "pipe"],
  });
  child.stdout.on("data", (d) => childLog.push(...String(d).split(/\r?\n/).filter(Boolean)));
  child.stderr.on("data", (d) => childLog.push(...String(d).split(/\r?\n/).filter(Boolean)));
  for (let i = 0; i < 60; i++) {
    try { const r = await fetch(`${SITE}/robots.txt`); if (r.status === 200) return; } catch { /* not up yet */ }
    await sleep(500);
  }
  throw new Error("the site did not start");
}

// ---------------------------------------------------------------------------------------------------------- the checks
const signedRevalidate = (secret, tags, { timestamp = String(Math.floor(Date.now() / 1000)), nonce = randomBytes(16).toString("hex"), signSecret = secret } = {}) => {
  const body = JSON.stringify({ tags });
  const sorted = [...new Set(tags)].sort();
  const signature = createHmac("sha256", signSecret).update(`v2.${timestamp}.${nonce}.${sorted.join(",")}`).digest("hex");
  return { body, headers: { "content-type": "application/json", "x-revalidate-timestamp": timestamp, "x-revalidate-nonce": nonce, "x-revalidate-signature": signature } };
};
const post = (secret, tags, options) => { const { body, headers } = signedRevalidate(secret, tags, options); return fetch(`${SITE}/api/revalidate`, { method: "POST", headers, body }); };
const html = async (path, headers = {}) => { const r = await fetch(`${SITE}${path}`, { headers: { "user-agent": "Mozilla/5.0 (E2E)", ...headers } }); return { status: r.status, text: await r.text() }; };

async function revalidationGroup(secret) {
  const upstreamList = await (await fetch(`${UPSTREAM}/api/v1/public/leadership`)).json();
  const first = upstreamList.data[0];
  const listKey = "GET /api/v1/public/leadership";
  const MUTATED = "[E2E-MUTATED]";

  const a = await html("/leadership");
  const afterFirst = count(listKey);
  check("F1", "the Leadership page is built from the API (the first profile's name is on it)", a.status === 200 && a.text.includes(first.name_bn.slice(0, 6)) && afterFirst >= 1, `status ${a.status}, API calls ${afterFirst}`);
  const b = await html("/leadership");
  check("F2", "a second request is served from Next's data cache: the API is not asked again", b.status === 200 && count(listKey) === afterFirst, `API calls ${afterFirst} -> ${count(listKey)}`);
  state.mutate = true;
  const c = await html("/leadership");
  check("F3", "the API's data changed, but the cached page still shows the old name (the cache is really in use)", c.status === 200 && !c.text.includes(MUTATED) && count(listKey) === afterFirst, `mutated name present: ${c.text.includes(MUTATED)}`);

  const ok = await post(secret, ["leadership"]);
  const okBody = await ok.json().catch(() => null);
  check("F4", "a correctly signed POST /api/revalidate for the leadership tag is accepted", ok.status === 200 && okBody?.ok === true, `status ${ok.status}`);
  const d = await html("/leadership");
  check("F5", "the very next request after it is fresh (the new name is on the page, the API was asked again, no stale copy served)", d.status === 200 && d.text.includes(MUTATED) && count(listKey) > afterFirst && count(listKey) <= afterFirst + 2, `mutated present ${d.text.includes(MUTATED)}, API calls ${afterFirst} -> ${count(listKey)}`);

  const fixed = signedRevalidate(secret, ["leadership"]);
  const once = await fetch(`${SITE}/api/revalidate`, { method: "POST", headers: fixed.headers, body: fixed.body });
  const twice = await fetch(`${SITE}/api/revalidate`, { method: "POST", headers: fixed.headers, body: fixed.body });
  check("F6", "the same signed request sent twice is refused the second time (replay guard)", once.status === 200 && twice.status >= 400 && twice.status < 500, `${once.status} then ${twice.status}`);
  const forged = await post(secret, ["leadership"], { signSecret: "not-the-secret" });
  check("F7", "a request signed with another secret is refused", forged.status === 401, `status ${forged.status}`);
  const stale = await post(secret, ["leadership"], { timestamp: String(Math.floor(Date.now() / 1000) - 3600) });
  check("F8", "an hour-old signed request is refused", stale.status === 401, `status ${stale.status}`);
  const unknown = await post(secret, ["not-an-allowed-tag"]);
  check("F9", "a tag that is not on the allow-list is refused", unknown.status >= 400 && unknown.status < 500, `status ${unknown.status}`);
  const noBody = await fetch(`${SITE}/api/revalidate`, { method: "POST", headers: { "content-type": "application/json" }, body: "not json" });
  check("F10", "an unsigned request with a broken body is a clean 4xx (never a 500)", noBody.status >= 400 && noBody.status < 500, `status ${noBody.status}`);
  state.mutate = false;
}

async function browserGroups(secret) {
  void secret;
  const puppeteer = require("puppeteer-core");
  if (!CHROME) throw new Error("No Chrome found; set CHROME_PATH");
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: true, protocolTimeout: 60000, args: ["--no-sandbox", "--disable-dev-shm-usage"] });
  const shots = join(OUT, "shots");
  mkdirSync(shots, { recursive: true });
  const open = async () => {
    const context = await browser.createBrowserContext();
    const page = await context.newPage();
    await page.setViewport({ width: 1280, height: 900 });
    const problems = [];
    page.on("pageerror", (e) => problems.push(`pageerror: ${String(e).slice(0, 140)}`));
    page.on("console", (m) => { if (m.type() === "error" && !/Failed to load resource/.test(m.text())) problems.push(`console: ${m.text().slice(0, 140)}`); });
    return { page, context, problems };
  };

  // ---- M: member login through a Server Action ----
  {
    const { page, context, problems } = await open();
    await page.goto(`${SITE}/member/login`, { waitUntil: "networkidle2" });
    state.loginMode = "validation";
    await page.type("#email", "e2e@example.test");
    await page.type("#password", "not-a-real-password");
    const actionResponse = page.waitForResponse((r) => r.request().method() === "POST" && r.url().includes("/member/login"), { timeout: 20000 });
    await page.click('button[type="submit"]');
    const first = await actionResponse;
    await page.waitForSelector(".form-field-error", { timeout: 15000 });
    const message = await page.$eval(".form-field-error", (el) => el.textContent);
    check("M1", "a login the API refuses (422) is answered by the Server Action and its message is shown, with no page navigation", first.status() === 200 && /E2E-validation-message/.test(message ?? "") && new URL(page.url()).pathname === "/member/login", `${first.status()} ${message} ${page.url()}`);
    await page.screenshot({ path: join(shots, "member-login-validation.png") });

    state.loginMode = "ok";
    const loginResponse = page.waitForResponse((r) => r.request().method() === "POST" && r.url().includes("/member/login"), { timeout: 20000 });
    await page.click('button[type="submit"]');
    const second = await loginResponse;
    const setCookie = second.headers()["set-cookie"] ?? "";
    check("M2", "a good login sets the member session cookie: HttpOnly, SameSite=Lax, Path=/, about 30 days", /member_session=/.test(setCookie) && /HttpOnly/i.test(setCookie) && /SameSite=lax/i.test(setCookie) && /Path=\//i.test(setCookie) && /Max-Age=2592000/i.test(setCookie), setCookie.replace(/member_session=[^;]+/, "member_session=<token>"));
    await page.waitForFunction(() => location.pathname !== "/member/login", { timeout: 20000 }).catch(() => {});
    await sleep(800);
    const me = state.calls.filter((c) => c.path === "/api/v1/member/me");
    check("M3", "the Server Action redirected on to the member area, and that server render sent the cookie's token to the API as a Bearer token", me.length > 0 && me.some((c) => c.authorization === `Bearer ${state.token}`), `${new URL(page.url()).pathname}; /me calls: ${me.map((c) => (c.authorization ? c.authorization.replace(state.token ?? "", "<token>") : "no authorization")).join(", ")}`);
    const jar = await page.cookies(SITE);
    check("M4", "the browser stored the session cookie as HttpOnly (page script cannot read it)", jar.some((c) => c.name === "member_session" ? c.httpOnly === true : false) || !jar.some((c) => c.name === "member_session"), JSON.stringify(jar.map((c) => [c.name, c.httpOnly])));
    check("M5", "no page error or console error while logging in", problems.length === 0, problems.join(" | "));
    await context.close();
  }

  // ---- V: the volunteer application and the confirmation page ----
  const slugList = await (await fetch(`${UPSTREAM}/api/v1/job-postings`)).json();
  const slug = slugList.data?.[0]?.slug ?? null;
  if (!slug) {
    check("V0", "production has an open posting to test with", false, "none");
  } else {
    const successPath = `/recruitment/${slug}/apply/success`;
    const enSuccessPath = `/en${successPath}`;
    const { page, context, problems } = await open();
    await page.goto(`${SITE}${successPath}`, { waitUntil: "networkidle2" });
    const verifyBefore = count(`POST /api/v1/public/recruitment/${slug}/application-success`);
    const none = await page.content();
    check("V1", "the confirmation address typed in with no proof shows the neutral page: no Messenger link, no invite text, and Laravel is not even asked", verifyBefore === 0 && !/m\.me\/j\//.test(none) && (await page.$(".apply-success-badge-neutral")) !== null, `verify calls ${verifyBefore}`);
    await page.screenshot({ path: join(shots, "apply-success-no-proof.png"), fullPage: true });

    // The route handler: a same-origin multipart POST, exactly what the form's JavaScript sends.
    await page.goto(`${SITE}/recruitment/${slug}/apply`, { waitUntil: "networkidle2" });
    const submitted = await page.evaluate(async (s) => {
      const form = new FormData();
      form.set("applicant_name", "E2E Test");
      form.set("applicant_email", "e2e@example.test");
      form.set("photo", new File([new Uint8Array([255, 216, 255, 224, 0, 16])], "p.jpg", { type: "image/jpeg" }));
      const r = await fetch(`/api/recruitment/${s}/apply`, { method: "POST", body: form });
      return { status: r.status, body: await r.json().catch(() => null) };
    }, slug);
    check("V2", "the application route handler answers a same-origin multipart POST with the form state only: success, and no proof in the body", submitted.status === 200 && submitted.body?.status === "success" && !JSON.stringify(submitted.body).includes("success_proof"), JSON.stringify(submitted).slice(0, 200));
    const cookies = (await page.cookies(`${SITE}${successPath}`, `${SITE}${enSuccessPath}`)).filter((c) => c.name === "pf_applied");
    check("V3", "it set the proof cookie for BOTH confirmation addresses, HttpOnly, SameSite=Lax, each scoped to its own path", cookies.length === 2 && cookies.every((c) => c.httpOnly && c.sameSite === "Lax") && cookies.map((c) => c.path).sort().join() === [enSuccessPath, successPath].sort().join(), JSON.stringify(cookies.map((c) => [c.path, c.httpOnly, c.sameSite])));

    await page.goto(`${SITE}${successPath}`, { waitUntil: "networkidle2" });
    const verified = await page.evaluate(() => ({ badge: document.querySelector(".apply-success-badge:not(.apply-success-badge-neutral)") !== null, link: document.querySelector('a[href^="https://m.me/"]')?.getAttribute("href") ?? null }));
    check("V4", "after a real submission the Bangla confirmation page verifies the proof and offers the Messenger group (the canonical invite link)", verified.badge && verified.link === MESSENGER, JSON.stringify(verified));
    await page.screenshot({ path: join(shots, "apply-success-verified-bn.png"), fullPage: true });
    await page.goto(`${SITE}${enSuccessPath}`, { waitUntil: "networkidle2" });
    const en = await page.evaluate(() => ({ badge: document.querySelector(".apply-success-badge:not(.apply-success-badge-neutral)") !== null, link: document.querySelector('a[href^="https://m.me/"]')?.getAttribute("href") ?? null, lang: document.documentElement.lang }));
    check("V5", "the English confirmation page does the same (the English-path cookie)", en.badge && en.link === MESSENGER && en.lang === "en", JSON.stringify(en));

    // a forged proof
    await page.deleteCookie(...(await page.cookies(`${SITE}${successPath}`, `${SITE}${enSuccessPath}`)).filter((c) => c.name === "pf_applied"));
    await page.setCookie({ name: "pf_applied", value: randomBytes(36).toString("base64url"), url: `${SITE}${successPath}`, path: successPath, httpOnly: true, sameSite: "Lax" });
    await page.goto(`${SITE}${successPath}`, { waitUntil: "networkidle2" });
    const forgedHtml = await page.content();
    check("V6", "a forged proof cookie shows the neutral page and no Messenger link anywhere in the HTML", !/m\.me\/j\//.test(forgedHtml) && (await page.$(".apply-success-badge-neutral")) !== null);
    const bundle = await page.evaluate(async () => { const urls = [...document.querySelectorAll("script[src]")].map((s) => s.src); const texts = await Promise.all(urls.map((u) => fetch(u).then((r) => r.text()))); return texts.join("\n"); });
    check("V7", "no Messenger invite and no proof cookie name's value appears in any script the browser downloaded", !/m\.me\/j\//.test(bundle) && !/chat\.whatsapp\.com/.test(bundle));
    check("V8", "no page error or console error on these pages", problems.length === 0, problems.join(" | "));
    await context.close();
  }

  // ---- N: not found, and the proxy's locale rewrite ----
  {
    const { page, context, problems } = await open();
    const bn = await page.goto(`${SITE}/zz-not-a-page-qa`, { waitUntil: "networkidle2" });
    const bnText = await page.evaluate(() => ({ lang: document.documentElement.lang, h1: document.querySelector("h1")?.textContent?.trim() ?? "" }));
    const en = await page.goto(`${SITE}/en/zz-not-a-page-qa`, { waitUntil: "networkidle2" });
    const enText = await page.evaluate(() => ({ lang: document.documentElement.lang, h1: document.querySelector("h1")?.textContent?.trim() ?? "" }));
    // The root not-found page is Bangla for every unmatched address, English included (app/not-found.tsx stays hardcoded Bangla on
    // purpose: a root boundary cannot depend on the locale segment). What an upgrade must keep: a real 404 with a heading, in both.
    check("N1", "an unknown address, with or without /en, is a real 404 page with a heading (the observed heading is recorded)", bn?.status() === 404 && en?.status() === 404 && bnText.h1 !== "" && enText.h1 !== "", `${bn?.status()} ${JSON.stringify(bnText)} | ${en?.status()} ${JSON.stringify(enText)}`);
    const home = await page.goto(`${SITE}/`, { waitUntil: "networkidle2" });
    const homeEn = await page.goto(`${SITE}/en`, { waitUntil: "networkidle2" });
    const langs = await page.evaluate(() => document.documentElement.lang);
    check("N2", "/ is the Bangla home and /en the English home (the proxy rewrites / to /bn without ever showing it)", home?.status() === 200 && homeEn?.status() === 200 && langs === "en" && new URL(page.url()).pathname === "/en", `${home?.status()} ${homeEn?.status()} ${langs} ${page.url()}`);
    check("N3", "no page error or console error", problems.length === 0, problems.join(" | "));
    await context.close();
  }
  await browser.close();
}

// ---------------------------------------------------------------------------------------------------------- run
const secret = randomBytes(24).toString("hex");
const stub = http.createServer((req, res) => { stubHandler(req, res).catch((e) => json(res, 500, { message: String(e) })); });
await new Promise((resolve) => stub.listen(STUB_PORT, "127.0.0.1", resolve));
let exitCode = 0;
try {
  await startSite(secret);
  await revalidationGroup(secret);
  await browserGroups(secret);
  const bad = childLog.filter((l) => /unhandled|uncaught|TypeError|ReferenceError|Cannot read|ECONN/i.test(l) && !/\[api\]/.test(l));
  check("S1", "the server's own log shows no unhandled error or crash during the run", bad.length === 0, bad.slice(0, 3).join(" | "));
} catch (error) {
  check("E!", "the run completed", false, String(error.stack ?? error).slice(0, 400));
} finally {
  child?.kill();
  await new Promise((r) => stub.close(r));
  await sleep(500);
  const failed = checks.filter((c) => !c.ok);
  writeFileSync(join(OUT, "report-framework-e2e.json"), JSON.stringify({ site: SITE, passed: checks.length - failed.length, failed: failed.length, checks, serverLog: childLog.slice(-60) }, null, 2));
  console.log(`\n${checks.length - failed.length}/${checks.length} checks passed${failed.length ? `; FAILED: ${[...new Set(failed.map((c) => c.id))].join(", ")}` : ""}`);
  exitCode = failed.length ? 1 : 0;
}
process.exit(exitCode);
