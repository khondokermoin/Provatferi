/**
 * Real-browser admin QA (2026-09-08). Drives a real Chrome against the local
 * MySQL-backed admin app across every Phase 1A/1B/1C screen.
 * Usage: php artisan serve --port=8080, then `node scripts/admin-qa.mjs`.
 */
import puppeteer from "puppeteer-core";
import { writeFileSync, mkdirSync } from "node:fs";

const CHROME = "C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe";
const BASE = "http://127.0.0.1:8080";
const OUT = "design-review/admin-qa-2026-09-08";
mkdirSync(OUT, { recursive: true });

const screens = [
  ["dashboard", "/admin"],
  ["users", "/admin/system/users"],
  ["roles", "/admin/system/roles"],
  ["permissions", "/admin/system/permissions"],
  ["org-units", "/admin/organization/units"],
  ["positions", "/admin/organization/positions"],
  ["committees", "/admin/organization/committees"],
  ["activity-types", "/admin/activity-types"],
  ["activities", "/admin/activities"],
  ["activity-create", "/admin/activities/create"],
  ["membership-types", "/admin/membership/types"],
  ["membership-applications", "/admin/membership"],
  ["members", "/admin/membership/members"],
  ["job-postings", "/admin/recruitment"],
  ["recruitment-applications", "/admin/recruitment-applications"],
  ["settings", "/admin/settings"],
  ["about", "/admin/content/about"],
  ["mission", "/admin/content/mission"],
  ["vision", "/admin/content/vision"],
  ["objectives", "/admin/content/objectives"],
];

const viewports = [360, 390, 768, 1366];
const themes = ["light", "dark"];
const results = { overflow: [], httpErrors: [], consoleErrors: [], checks: [] };

const browser = await puppeteer.launch({ executablePath: CHROME, headless: "new" });

async function login(page) {
  // AdminUserSeeder generates a random password even in local dev — never
  // hardcode one here. Pass it via env: QA_ADMIN_EMAIL / QA_ADMIN_PASSWORD.
  const email = process.env.QA_ADMIN_EMAIL;
  const password = process.env.QA_ADMIN_PASSWORD;
  if (!email || !password) {
    throw new Error("Set QA_ADMIN_EMAIL and QA_ADMIN_PASSWORD env vars before running this script.");
  }
  await page.goto(`${BASE}/login`, { waitUntil: "networkidle0" });
  await page.type('input[name="email"]', email);
  await page.type('input[name="password"]', password);
  await Promise.all([
    page.waitForNavigation({ waitUntil: "networkidle0" }),
    page.click('button[type="submit"]'),
  ]);
  return page.url();
}

const page = await browser.newPage();
page.on("console", (m) => { if (m.type() === "error") results.consoleErrors.push(m.text()); });
page.on("pageerror", (e) => results.consoleErrors.push(String(e)));

await page.setViewport({ width: 1366, height: 900 });
const afterLogin = await login(page);
results.checks.push({ check: "login", landedOn: afterLogin, ok: !afterLogin.includes("/login") });

for (const theme of themes) {
  // The admin persists theme in localStorage like the public site.
  await page.evaluateOnNewDocument((t) => {
    try { localStorage.setItem("provatferi-admin-theme", t); localStorage.setItem("theme", t); } catch {}
  }, theme);

  for (const width of viewports) {
    await page.setViewport({ width, height: 900 });
    for (const [name, path] of screens) {
      const res = await page.goto(BASE + path, { waitUntil: "networkidle0" });
      const status = res.status();
      if (status >= 400) results.httpErrors.push({ theme, width, name, path, status });

      const m = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
      }));
      if (m.scrollWidth > m.clientWidth + 1) {
        results.overflow.push({ theme, width, name, path, ...m });
      }
    }
  }
}

// --- Focused interaction checks ---

// Theme really applied (proves the dark passes above were genuinely dark).
for (const theme of ["light", "dark"]) {
  await page.evaluateOnNewDocument((t) => {
    try { localStorage.setItem("provatferi-admin-theme", t); } catch {}
  }, theme);
  await page.setViewport({ width: 1366, height: 900 });
  await page.goto(`${BASE}/admin`, { waitUntil: "networkidle0" });
  const applied = await page.evaluate(() => ({
    bsTheme: document.documentElement.getAttribute("data-bs-theme"),
    menuColor: document.documentElement.getAttribute("data-menu-color"),
    bodyBg: getComputedStyle(document.body).backgroundColor,
  }));
  results.checks.push({ check: `theme-applied-${theme}`, expected: theme, ...applied, ok: applied.bsTheme === theme });
}

// Official logo swaps per theme (light/dark marks are separate <span>s).
const logo = await page.evaluate(() => {
  const light = document.querySelector(".pf-logo-for-light");
  const dark = document.querySelector(".pf-logo-for-dark");
  const vis = (el) => el && getComputedStyle(el).display !== "none";
  return { hasLight: !!light, hasDark: !!dark, darkVisibleInDarkTheme: vis(dark), lightVisibleInDarkTheme: vis(light) };
});
results.checks.push({ check: "logo-switching", ...logo });

// Mobile sidebar drawer
await page.setViewport({ width: 390, height: 844 });
await page.goto(`${BASE}/admin`, { waitUntil: "networkidle0" });
// The drawer slides in from off-screen; data-sidenav-size does not change on
// mobile, so measure the sidebar's actual position instead.
const readDrawer = () => page.evaluate(() => {
  const nav = document.querySelector(".sidenav-menu, .app-sidebar, aside");
  const r = nav?.getBoundingClientRect();
  return { left: r ? Math.round(r.left) : null, onScreen: r ? r.left >= 0 : null, backdrop: !!document.querySelector(".offcanvas-backdrop, .sidenav-backdrop, .backdrop") };
});
const drawerBefore = await readDrawer();
const hasToggle = await page.$(".sidenav-toggle-button");
if (hasToggle) await hasToggle.click();
await new Promise((r) => setTimeout(r, 500));
const drawerAfter = await readDrawer();
results.checks.push({
  check: "mobile-sidebar-drawer",
  toggleFound: !!hasToggle,
  before: drawerBefore, after: drawerAfter,
  ok: !!hasToggle && drawerBefore.onScreen === false && drawerAfter.onScreen === true && drawerAfter.backdrop,
});

// Empty states on screens that genuinely have no records yet (no fake seed
// data). Pages override the component's default icon, so match the heading
// the component renders rather than a specific icon name.
for (const [name, path] of [["activities", "/admin/activities"], ["membership-applications", "/admin/membership"], ["recruitment-applications", "/admin/recruitment-applications"]]) {
  await page.goto(BASE + path, { waitUntil: "networkidle0" });
  const empty = await page.evaluate(() => {
    const heading = document.querySelector("h3.fs-15");
    const icon = document.querySelector("i.ti.fs-1");
    return { heading: heading ? heading.textContent.trim() : null, icon: icon ? icon.className.split(" ")[1] : null };
  });
  results.checks.push({ check: `empty-state-${name}`, ...empty, ok: !!empty.heading });
}

// Table → card conversion at mobile, and status badges where present.
await page.goto(`${BASE}/admin/system/users`, { waitUntil: "networkidle0" });
const responsiveTable = await page.evaluate(() => {
  const table = document.querySelector("table");
  const wrapper = table?.closest(".table-responsive, [class*='responsive']");
  const badge = document.querySelector(".badge, [class*='badge']");
  return {
    hasTable: !!table,
    hasResponsiveWrapper: !!wrapper,
    tableOverflowsWrapper: wrapper ? wrapper.scrollWidth > wrapper.clientWidth : null,
    hasStatusBadge: !!badge,
  };
});
results.checks.push({ check: "mobile-table-and-badges", ...responsiveTable });

// Keyboard focus visibility. getComputedStyle misreports outline-width as 0px
// for these elements even when the ring paints, so capture the pixels and
// judge from the screenshot instead of the computed value.
await page.setViewport({ width: 1366, height: 900 });
await page.goto(`${BASE}/admin/system/users`, { waitUntil: "networkidle0" });
let focusBox = null;
for (let i = 0; i < 12; i++) {
  await page.keyboard.press("Tab");
  focusBox = await page.evaluate(() => {
    const el = document.activeElement;
    if (!(el.className || "").toString().includes("side-nav-link")) return null;
    const r = el.getBoundingClientRect();
    return { x: Math.max(0, r.x - 12), y: Math.max(0, r.y - 12), width: r.width + 24, height: r.height + 24, text: el.textContent.trim().slice(0, 20) };
  });
  if (focusBox) break;
}
if (focusBox) {
  await new Promise((r) => setTimeout(r, 300));
  const { text, ...clip } = focusBox;
  await page.screenshot({ path: `${OUT}/focus-ring-sidebar-link.png`, clip });
}
results.checks.push({ check: "focus-ring-screenshot", captured: !!focusBox, element: focusBox?.text ?? null });

// Screenshots for the record
for (const [theme, width, name, path] of [
  ["light", 1366, "dashboard", "/admin"],
  ["light", 390, "dashboard", "/admin"],
  ["dark", 1366, "activities", "/admin/activities"],
  ["dark", 390, "membership-applications", "/admin/membership"],
]) {
  await page.evaluateOnNewDocument((t) => {
    try { localStorage.setItem("provatferi-admin-theme", t); localStorage.setItem("theme", t); } catch {}
  }, theme);
  await page.setViewport({ width, height: 900 });
  await page.goto(BASE + path, { waitUntil: "networkidle0" });
  await page.screenshot({ path: `${OUT}/${theme}-${width}-${name}.png` });
}

await browser.close();
writeFileSync(`${OUT}/results.json`, JSON.stringify(results, null, 2));
console.log(JSON.stringify({
  screensTested: screens.length,
  combinations: screens.length * viewports.length * themes.length,
  overflow: results.overflow.length,
  httpErrors: results.httpErrors.length,
  consoleErrors: results.consoleErrors.length,
  checks: results.checks,
  overflowDetail: results.overflow.slice(0, 10),
  httpErrorDetail: results.httpErrors.slice(0, 10),
}, null, 2));
