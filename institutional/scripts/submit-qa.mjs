// Real-Chrome acceptance + timing harness for the volunteer application form.
//
//   node scripts/submit-qa.mjs --base https://provatferi.org --fixtures <dir> \
//        [--slug <posting>] [--locale bn|en] [--scenarios A,B,C,D] [--runs 2] [--spacing 14] \
//        [--email you@gmail.com] [--label before] [--json out.json] [--shots <dir>] [--photo photo-detail.jpg] [--no-shrink]
//
// Scenarios (each submits a REAL application — use a throwaway address and delete the rows after):
//   A  smallest valid   required fields only, 328-byte photo, no CV
//   B  phone photo      required + typical optional text, ~3.5 MB 12 MP photo, no CV
//   C  photo + CV       as B plus a ~1.1 MB PDF CV
//   D  CV omitted       ~1 MB photo, every optional field filled, CV left untouched
//   E1/E2/E3  double submit: three synchronous clicks / a real double-click / Enter twice — must send ONE request
//   F  error recovery   invalid phone with both files attached — the form must keep everything
//
// For every submission it records, from the browser's side: how long until the button visibly
// changed, the request's own waterfall (connect, upload, server wait, download — straight from
// Chrome's network timing), and the time until the success page is painted. With the opt-in
// `x-pf-timing: 1` header it also collects the server's phases (Next.js action, Next -> Laravel
// forward, and Laravel's own Server-Timing) from the `pf_timing` cookie the action sets.
//
// The apply route is limited to 5 requests/minute per IP (and every visitor reaches Laravel through
// the same Next.js server), so --spacing keeps the whole run under that.

import { existsSync } from "node:fs";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import puppeteer from "puppeteer-core";

const arg = (n, d = null) => {
  const i = process.argv.indexOf(`--${n}`);
  return i === -1 ? d : process.argv[i + 1];
};
const base = arg("base", "https://provatferi.org").replace(/\/+$/, "");
const slug = arg("slug", "prvatfereer-swecchasebee-time-zukt-hoozar-ahwan-0rkb");
const locale = arg("locale", "bn");
const fixtures = arg("fixtures");
const scenarioIds = arg("scenarios", "A,B,C,D").split(",").map((s) => s.trim());
const runs = Number(arg("runs", 2));
const spacingMs = Number(arg("spacing", 14)) * 1000;
const emailBase = arg("email", "khondokermoin2k23@gmail.com");
const label = arg("label", "run");
// Every invocation gets its own e-mail addresses (the endpoint refuses a second live application per address).
const suffix = arg("suffix", Math.random().toString(36).slice(2, 5));
const jsonOut = arg("json");
const debug = process.argv.includes("--debug"); // also dump the Server Action response headers + the start of its body
const bigPhoto = arg("photo", "photo-phone.jpg"); // the "phone photo" fixture used by B and C (photo-detail.jpg is the realistic-texture one)
const noShrink = process.argv.includes("--no-shrink"); // hide createImageBitmap, so the photo goes up exactly as chosen (A/B for the client-side resize)
const shotsDir = arg("shots");
if (!fixtures) {
  console.error("usage: node scripts/submit-qa.mjs --fixtures <dir> [--base url] ...   (make fixtures with scripts/make-submit-fixtures.mjs)");
  process.exit(2);
}

const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find(existsSync);
if (!chrome) throw new Error("No Chrome found; set CHROME_PATH");

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const ms = (v) => (v === null || v === undefined || Number.isNaN(v) ? null : Math.round(v));
const fx = (name) => resolve(fixtures, name);

const longText = (seed) => Array.from({ length: 14 }, (_, i) => `${seed} paragraph ${i + 1}: organised community programmes, coordinated volunteers and kept the committee informed.`).join(" ").slice(0, 1400);

const SCENARIOS = {
  A: { name: "smallest valid", photo: "photo-tiny.png", cv: null, full: false },
  B: { name: "phone photo", photo: bigPhoto, cv: null, full: true },
  C: { name: "phone photo + CV", photo: bigPhoto, cv: "cv.pdf", full: true },
  D: { name: "CV omitted", photo: "photo-mid.jpg", cv: null, full: true },
  E1: { name: "3 sync clicks", photo: "photo-tiny.png", cv: null, full: false, double: "clicks" },
  E2: { name: "real double-click", photo: "photo-tiny.png", cv: null, full: false, double: "dblclick" },
  E3: { name: "Enter twice", photo: "photo-tiny.png", cv: null, full: false, double: "enter" },
  F: { name: "error recovery", photo: "photo-mid.jpg", cv: "cv.pdf", full: true, badPhone: true },
};

/** Sets a field the way a user's typing would, through React's own value tracking. */
const fillForm = (v) => {
  const set = (id, value) => {
    const el = document.getElementById(id);
    if (!el) return;
    const proto = el.tagName === "TEXTAREA" ? HTMLTextAreaElement.prototype : el.tagName === "SELECT" ? HTMLSelectElement.prototype : HTMLInputElement.prototype;
    Object.getOwnPropertyDescriptor(proto, "value").set.call(el, value);
    el.dispatchEvent(new Event("input", { bubbles: true }));
    el.dispatchEvent(new Event("change", { bubbles: true }));
  };
  for (const [id, value] of Object.entries(v.fields)) set(id, value);
  for (const id of v.checks) { const el = document.getElementById(id); if (el && !el.checked) el.click(); }
  const skill = document.querySelector('input[name="skills[]"]'); if (skill && !skill.checked) skill.click();
};

function fieldsFor(sc, tag) {
  const fields = {
    applicant_name: `QA TIMING TEST ${tag}`,
    applicant_phone: sc.badPhone ? "abc" : "01712345678",
    applicant_email: emailBase.replace("@", `+pft-${tag.toLowerCase()}@`),
    district: "Dhaka",
    current_location: "Mirpur",
    profession: "QA timing test",
    experience: sc.full ? longText("Experience") : "QA timing test.",
    contribution: sc.full ? longText("Contribution") : "QA timing test.",
  };
  if (sc.full) {
    Object.assign(fields, {
      other_skills: "Event photography, Bangla typing.",
      availability: "5–8 hours per week",
      preferred_contact: "whatsapp",
      linkedin_url: "https://www.linkedin.com/in/qa-timing-test",
      facebook_url: "https://www.facebook.com/qa.timing.test",
      portfolio_url: "https://example.com/qa-timing-test",
    });
  }
  return fields;
}

async function openForm(browser, withTimingHeader) {
  const context = await browser.createBrowserContext();
  const page = await context.newPage();
  await page.setViewport({ width: 1440, height: 900 });
  if (withTimingHeader) await page.setExtraHTTPHeaders({ "x-pf-timing": "1" });
  if (noShrink) await page.evaluateOnNewDocument(() => { window.createImageBitmap = undefined; });
  const cdp = await page.createCDPSession();
  await cdp.send("Network.enable");

  const net = { posts: [] };
  cdp.on("Network.requestWillBeSent", (e) => {
    // The form posts to its own route handler (app/api/recruitment/<slug>/apply); a Server Action post (next-action header) is also counted.
    const submit = e.request.method === "POST" && (e.request.headers["next-action"] !== undefined || e.request.url.includes("/api/recruitment/"));
    if (submit) net.posts.push({ id: e.requestId, ts: e.timestamp, wall: e.wallTime });
  });
  cdp.on("Network.responseReceived", (e) => { const p = net.posts.find((x) => x.id === e.requestId); if (p) p.response = e.response; });
  // A refused action POST (a 4xx/5xx from an upstream layer, not the app): keep who said it and what it said.
  page.on("response", (res) => {
    if (res.request().method() !== "POST" || res.status() < 400) return;
    net.refused = { status: res.status(), headers: res.headers(), body: null };
    Promise.race([res.text(), new Promise((r) => setTimeout(() => r("(body not delivered)"), 4000))]).then((t) => { net.refused.body = String(t).slice(0, 1500); }, () => { net.refused.body = "(unreadable)"; });
  });
  cdp.on("Network.loadingFinished", (e) => { const p = net.posts.find((x) => x.id === e.requestId); if (p) p.finished = e.timestamp; });

  const url = `${base}${locale === "en" ? "/en" : ""}/recruitment/${slug}/apply`;
  await page.goto(url, { waitUntil: "networkidle0", timeout: 60000 });
  await page.waitForFunction(() => {
    const f = document.querySelector("form.volunteer-form");
    return f && Object.keys(f).some((k) => k.startsWith("__reactProps$") || k.startsWith("__reactFiber$"));
  }, { timeout: 30000 });
  return { context, page, cdp, net, url };
}

/** Browser-side probes, installed before the click so nothing is measured after the fact. */
const installProbes = () => {
  const pf = (window.__pf = { origin: performance.timeOrigin, clicks: [], submits: [] });
  const form = document.querySelector("form.volunteer-form");
  const btn = form.querySelector('button[type="submit"]');
  btn.addEventListener("click", (e) => pf.clicks.push({ ts: e.timeStamp, now: performance.now() }), true);
  form.addEventListener("submit", (e) => pf.submits.push({ ts: e.timeStamp, now: performance.now(), prevented: e.defaultPrevented }), true);
  const painted = (key) => requestAnimationFrame(() => requestAnimationFrame(() => { pf[key] = performance.now(); }));
  new MutationObserver(() => {
    if (!pf.feedbackDom && btn.disabled && btn.getAttribute("aria-busy") === "true") { pf.feedbackDom = performance.now(); painted("feedbackPaint"); }
  }).observe(btn, { attributes: true, childList: true, subtree: true, characterData: true });
  new MutationObserver(() => {
    if (!pf.successDom && document.querySelector(".apply-success")) { pf.successDom = performance.now(); painted("successPaint"); }
  }).observe(document.documentElement, { childList: true, subtree: true });
};

const readPendingState = () => {
  const form = document.querySelector("form.volunteer-form");
  const btn = form.querySelector('button[type="submit"]');
  const status = form.querySelector(".form-submit-status");
  const controls = [...form.querySelectorAll("input, select, textarea, button")].filter((c) => c.type !== "hidden");
  return {
    buttonDisabled: btn.disabled,
    buttonAriaBusy: btn.getAttribute("aria-busy"),
    buttonText: btn.innerText.trim(),
    spinnerInButton: !!btn.querySelector("svg"),
    formAriaBusy: form.getAttribute("aria-busy"),
    statusText: status ? status.innerText.trim() : null,
    statusIsLive: status ? status.getAttribute("aria-live") : null,
    enabledControls: controls.filter((c) => !c.disabled).length,
    totalControls: controls.length,
    activeElement: document.activeElement ? document.activeElement.id || document.activeElement.tagName.toLowerCase() : null,
  };
};

function decodeTimingCookie(cookies) {
  const c = cookies.find((x) => x.name === "pf_timing");
  if (!c) return null;
  let v = c.value;
  for (let i = 0; i < 3 && !v.trim().startsWith("{"); i++) {
    try { v = decodeURIComponent(v); } catch { break; }
  }
  try { return JSON.parse(v); } catch { return null; }
}

function parseServerTiming(header) {
  const out = {};
  for (const part of (header ?? "").split(",")) {
    const m = part.trim().match(/^([\w-]+)(?:;desc="?[^";]*"?)?;dur=([\d.]+)/) ?? part.trim().match(/^([\w-]+);dur=([\d.]+)/);
    if (m) out[m[1]] = Number(m[2]);
  }
  return out;
}

async function submitOnce(browser, id, run, withTimingHeader = true) {
  const sc = SCENARIOS[id];
  const tag = `${id}${run}${suffix}`;
  const { context, page, cdp, net } = await openForm(browser, withTimingHeader);
  const row = { scenario: id, name: sc.name, run, tag };
  const diag = { navigations: [], consoleErrors: [], failedRequests: [] };
  page.on("framenavigated", (f) => { if (f === page.mainFrame()) diag.navigations.push(f.url()); });
  page.on("console", (m) => { if (m.type() === "error") diag.consoleErrors.push(m.text().slice(0, 160)); });
  page.on("pageerror", (e) => diag.consoleErrors.push(String(e).slice(0, 160)));
  page.on("requestfailed", (r) => diag.failedRequests.push(`${r.method()} ${r.url().slice(0, 100)} ${r.failure()?.errorText ?? ""}`));
  try {
    await page.evaluate(fillForm, { fields: fieldsFor(sc, tag), checks: ["accuracy_declaration", "privacy_consent", "contact_consent"] });
    await (await page.$("#photo")).uploadFile(fx(sc.photo));
    if (sc.cv) await (await page.$("#cv")).uploadFile(fx(sc.cv));
    // A real visitor picks the photo well before pressing Submit, so the form's background resize
    // (lib/shrink-photo.ts) is done by then; wait for it here and record what will actually be sent.
    const chosenAt = Date.now();
    await page.waitForFunction(() => !!document.querySelector("#photo")?.closest(".form-field")?.querySelector('[role="status"]'), { timeout: 4000, polling: 25 }).catch(() => {});
    row.shrinkMs = Date.now() - chosenAt;
    row.photoSentBytes = await page.evaluate(() => document.querySelector("#photo").files[0]?.size ?? null);
    row.photoNote = await page.evaluate(() => document.querySelector("#photo")?.closest(".form-field")?.querySelector('[role="status"]')?.innerText ?? null);
    await page.evaluate(installProbes);
    await sleep(250); // let the layout settle so the click measures the form, not the fill

    const btnSel = 'form.volunteer-form button[type="submit"]';
    if (sc.double === "clicks") await page.evaluate((s) => { const b = document.querySelector(s); b.click(); b.click(); b.click(); }, btnSel);
    else if (sc.double === "dblclick") await page.click(btnSel, { count: 2, delay: 0 });
    else if (sc.double === "enter") { await page.focus("#applicant_name"); await Promise.all([page.keyboard.press("Enter"), page.keyboard.press("Enter")]); }
    else await page.click(btnSel);

    // What the visitor sees while it is in flight (read before any navigation can replace the document).
    await sleep(350);
    const early = await page.evaluate(() => {
      const pf = window.__pf;
      return pf ? { origin: pf.origin, clicks: pf.clicks, submits: pf.submits, feedbackPaint: pf.feedbackPaint ?? null } : null;
    }).catch(() => null);
    row.pendingState = await page.evaluate(readPendingState).catch(() => null);
    if (shotsDir) { await mkdir(shotsDir, { recursive: true }); await page.screenshot({ path: resolve(shotsDir, `${label}-${tag}-pending.png`) }).catch(() => {}); }

    // Either the success page (soft client navigation OR a hard page load — both are detected) or the form reporting a problem.
    const end = await page.waitForFunction(() => {
      if (location.pathname.endsWith("/apply/success") && document.querySelector(".apply-success")) return { kind: "success", epoch: performance.timeOrigin + performance.now(), soft: !!window.__pf };
      if (document.querySelector("form.volunteer-form .form-summary")) return { kind: "error", epoch: performance.timeOrigin + performance.now(), soft: true };
      return null;
    }, { timeout: 60000, polling: 20 }).then((h) => h.jsonValue()).catch(() => null);
    row.outcome = end?.kind ?? "timeout";
    row.navKind = end ? (end.soft ? "soft" : "hard") : null;
    if (sc.double) await sleep(1500); // catch a late second request

    const clickEpoch = early?.clicks?.[0] ? early.origin + early.clicks[0].ts : null;
    row.clicksSeen = early?.clicks?.length ?? null;
    row.submitEventsSeen = early?.submits?.length ?? null;
    row.requestsSent = net.posts.length;
    if (clickEpoch !== null) {
      row.feedbackMs = early.feedbackPaint ? ms(early.origin + early.feedbackPaint - clickEpoch) : null;
      row.totalMs = end ? ms(end.epoch - clickEpoch) : null;
    }
    const post = net.posts.find((p) => p.response?.timing) ?? net.posts[0];
    const t = post?.response?.timing;
    if (t && clickEpoch !== null) {
      const epochOf = (mono) => post.wall * 1000 + (mono - post.ts) * 1000;
      const baseEpoch = epochOf(t.requestTime);
      row.postStartMs = ms(post.wall * 1000 - clickEpoch);
      row.network = {
        connectMs: t.connectStart >= 0 ? ms(t.connectEnd - Math.max(t.dnsStart, 0)) : 0,
        uploadMs: ms(t.sendEnd - t.sendStart),
        serverWaitMs: ms((t.receiveHeadersStart ?? t.receiveHeadersEnd) - t.sendEnd),
      };
      row.responseHeadersMs = ms(baseEpoch + t.receiveHeadersEnd - clickEpoch);
      row.responseEndMs = post.finished ? ms(epochOf(post.finished) - clickEpoch) : null;
      row.navigationMs = end && post.finished ? ms(end.epoch - epochOf(post.finished)) : null;
    }
    if (net.refused) row.refused = debug ? net.refused : { status: net.refused.status, server: net.refused.headers.server ?? null, cfRay: net.refused.headers["cf-ray"] ?? null, cfMitigated: net.refused.headers["cf-mitigated"] ?? null };
    if (debug && post) {
      const h = post.response?.headers ?? {};
      const body = await cdp.send("Network.getResponseBody", { requestId: post.id }).then((b) => (b.base64Encoded ? "(base64)" : b.body.slice(0, 400))).catch((e) => "(no body: " + e.message + ")");
      row.actionResponse = { status: post.response?.status, headers: Object.fromEntries(Object.entries(h).filter(([k]) => /content-type|x-action|x-nextjs|location|set-cookie|cache-control|vary|content-encoding|cf-cache/i.test(k))), bodyStart: body };
    }
    if (row.outcome === "success") {
      // Where the visitor lands: a soft navigation must open the confirmation at the very top, header included.
      await sleep(250);
      row.landing = await page.evaluate(() => ({ scrollY: Math.round(window.scrollY), headerTop: Math.round(document.querySelector("header")?.getBoundingClientRect().top ?? NaN), badgeTop: Math.round(document.querySelector(".apply-success-badge")?.getBoundingClientRect().top ?? NaN) }));
    }
    const timing = decodeTimingCookie(await page.cookies());
    if (timing) { row.server = timing; row.laravel = parseServerTiming(timing.laravel); }

    if (row.outcome === "timeout") {
      row.diag = { url: page.url(), title: await page.title().catch(() => null), text: await page.evaluate(() => document.body.innerText.slice(0, 200)).catch(() => null), ...diag };
    } else if (row.outcome === "error") {
      row.errorSummary = await page.evaluate(() => document.querySelector("form.volunteer-form .form-summary")?.innerText ?? null);
      row.afterError = await page.evaluate(() => {
        const f = document.querySelector("form.volunteer-form");
        const btn = f.querySelector('button[type="submit"]');
        return {
          buttonDisabled: btn.disabled, buttonAriaBusy: btn.getAttribute("aria-busy"), buttonText: btn.innerText.trim(), spinnerInButton: !!btn.querySelector("svg"),
          nameKept: f.querySelector("#applicant_name").value, emailKept: f.querySelector("#applicant_email").value,
          experienceChars: f.querySelector("#experience").value.length, contributionChars: f.querySelector("#contribution").value.length,
          skillsKept: [...f.querySelectorAll('input[name="skills[]"]:checked')].length,
          consentsKept: ["accuracy_declaration", "privacy_consent", "contact_consent"].filter((i) => f.querySelector("#" + i).checked).length,
          photoFile: f.querySelector("#photo").files[0]?.name ?? null, cvFile: f.querySelector("#cv").files[0]?.name ?? null,
          focusedId: document.activeElement?.id ?? null, invalidFields: [...f.querySelectorAll('[aria-invalid="true"]')].map((e) => e.id || e.name),
          statusText: f.querySelector(".form-submit-status")?.innerText.trim() ?? null,
        };
      });
      if (shotsDir) await page.screenshot({ path: resolve(shotsDir, `${label}-${tag}-after-error.png`) }).catch(() => {});
    } else if (shotsDir) {
      await page.screenshot({ path: resolve(shotsDir, `${label}-${tag}-success.png`) }).catch(() => {});
    }
    row.consoleErrors = diag.consoleErrors.length ? diag.consoleErrors : undefined;
  } catch (err) {
    row.outcome = "harness-error";
    row.error = String(err?.message ?? err).slice(0, 300);
  } finally {
    await cdp.detach().catch(() => {});
    await context.close().catch(() => {});
  }
  return row;
}

const median = (xs) => { const a = xs.filter((x) => typeof x === "number").sort((p, q) => p - q); return a.length ? a[Math.floor(a.length / 2)] : null; };

const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox", "--disable-dev-shm-usage"] });
const rows = [];
let first = true;
for (const id of scenarioIds) {
  for (let run = 1; run <= (id.startsWith("E") || id === "F" ? 1 : runs); run++) {
    if (!first) await sleep(spacingMs);
    first = false;
    const row = await submitOnce(browser, id, run);
    rows.push(row);
    const L = row.laravel ?? {};
    console.log(
      `${(row.outcome ?? "?").padEnd(8)} ${(row.navKind ?? "-").padEnd(4)} ${row.tag.padEnd(3)} ${row.name.padEnd(18)} total ${String(row.totalMs ?? "-").padStart(5)} ms | feedback ${String(row.feedbackMs ?? "-").padStart(4)} | upload ${String(row.network?.uploadMs ?? "-").padStart(5)} | wait ${String(row.network?.serverWaitMs ?? "-").padStart(5)} | nav ${String(row.navigationMs ?? "-").padStart(4)} | next action ${String(row.server?.actionMs ?? "-").padStart(6)} | laravel hop ${String(row.server?.laravelHeadersMs ?? "-").padStart(6)} | laravel total ${String(L.total ?? "-").padStart(6)} | requests ${row.requestsSent} | photo sent ${row.photoSentBytes === null || row.photoSentBytes === undefined ? "-" : Math.round(row.photoSentBytes / 1024) + " KB"}`,
    );
  }
}
await browser.close();

console.log("\nmedian total ms by scenario:");
for (const id of scenarioIds.filter((s) => !s.startsWith("E") && s !== "F")) {
  const r = rows.filter((x) => x.scenario === id && x.outcome === "success");
  console.log(`  ${id} ${SCENARIOS[id].name.padEnd(18)} n=${r.length}  total ${median(r.map((x) => x.totalMs))}  feedback ${median(r.map((x) => x.feedbackMs))}  upload ${median(r.map((x) => x.network?.uploadMs))}  wait ${median(r.map((x) => x.network?.serverWaitMs))}`);
}
if (jsonOut) await writeFile(jsonOut, JSON.stringify({ label, base, slug, locale, at: new Date().toISOString(), rows }, null, 2));
process.exit(rows.some((r) => ["harness-error", "timeout"].includes(r.outcome)) ? 1 : 0);
