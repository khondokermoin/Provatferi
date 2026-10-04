// The second half of the upload-forms acceptance run: what the browser run (scripts/upload-forms-qa.mjs --report) says it
// submitted, against what the SERVER holds (admin-erp/deploy/qa/upload-forms-qa.php inspect), and against the admin screens.
//
//   node scripts/upload-forms-qa-verify.mjs --state qa-state.json --run run.json --inspect inspect.json \
//        [--admin-base https://admin.provatferi.org --admin-email a@b.c --admin-password … --shots <dir>]
//
// For every scenario that succeeded: exactly ONE record carries its unique marker; the photo on file exists and its sha256 is the
// fixture's (a file that "persisted" but changed on the way would fail here); a photo left untouched is the photo that was on
// file; a refused attempt created nothing (the totals say so). With admin credentials (command line only — never stored) it also
// signs in to the admin screens and checks each record is listed and opens. Exit code 1 on any difference.

import { mkdir, readFile, writeFile } from "node:fs/promises";
import { existsSync } from "node:fs";
import { resolve } from "node:path";
import puppeteer from "puppeteer-core";

const arg = (n, d = null) => {
  const i = process.argv.indexOf(`--${n}`);
  return i === -1 ? d : process.argv[i + 1];
};
const readJson = async (file) => {
  const text = await readFile(file, "utf8");
  return JSON.parse(text.slice(text.indexOf("{"))); // PHP may print a startup warning before the JSON
};
const state = await readJson(arg("state"));
const run = await readJson(arg("run"));
const inspect = await readJson(arg("inspect"));
const adminBase = (arg("admin-base", "") ?? "").replace(/\/+$/, "");
const adminEmail = arg("admin-email");
const adminPassword = arg("admin-password");
const shotsDir = arg("shots");
const outFile = arg("out");

const TINY_SHA = state.corrections.A.photoSha256; // the 1x1 PNG every correction submission started with
const rows = [];
function expect(group, name, ok, detail = "") {
  rows.push({ group, name, ok: Boolean(ok), detail: ok ? "" : String(detail) });
}
const scenario = (form, id) => run.results.find((r) => r.form === form && r.id === id);
const photoOk = (photo, sc, label) => {
  if (!sc.fixtureSha256) return;
  expect(label, "photo exists on the private disk", photo?.exists === true, JSON.stringify(photo));
  expect(label, "photo sha256 is the fixture's (unchanged on the way)", photo?.sha256 === sc.fixtureSha256, `${photo?.sha256} vs ${sc.fixtureSha256} (${sc.fixtureName})`);
};

// --- committee registrations -----------------------------------------------------------------------------------
{
  const scs = run.results.filter((r) => r.form === "registration");
  for (const sc of scs) {
    const label = `registration ${sc.id}`;
    const matches = inspect.registrations.filter((r) => r.fullName === sc.marker);
    expect(label, "exactly ONE record carries this scenario's marker", matches.length === 1, `${matches.length} records`);
    const row = matches[0];
    if (!row) continue;
    expect(label, "status pending, chosen position, e-mail and phone as entered", row.status === "pending" && row.positionId === state.positions[1].id && /@gmail\.com$/.test(row.email) && row.phone === "01712345678", JSON.stringify({ status: row.status, positionId: row.positionId, email: row.email, phone: row.phone }));
    expect(label, "the comment text arrived", String(row.comment).includes(sc.marker), row.comment);
    photoOk(row.photo, sc, label);
  }
  expect("registration", "no stray records: one per scenario, none from the refused attempts", inspect.registrations.length === scs.length, `${inspect.registrations.length} records for ${scs.length} scenarios`);
  expect("registration", "the registration link was used", Boolean(inspect.link.lastUsedAt), JSON.stringify(inspect.link));
}

// --- committee corrections -------------------------------------------------------------------------------------
{
  const scs = run.results.filter((r) => r.form === "correction");
  for (const sc of scs) {
    const label = `correction ${sc.id}`;
    const seeded = state.corrections[sc.token];
    const row = inspect.corrections.find((r) => r.id === seeded.id);
    expect(label, "the seeded submission still exists, exactly once", inspect.corrections.filter((r) => r.id === seeded.id).length === 1);
    if (!row) continue;
    expect(label, "carries the corrected name", row.fullName === sc.marker, row.fullName);
    expect(label, "back to pending, the admin note cleared, the single-use token spent", row.status === "pending" && row.adminNote === null && row.correctionUsedAt !== null, JSON.stringify({ status: row.status, adminNote: row.adminNote, used: row.correctionUsedAt }));
    expect(label, "exactly ONE 'resubmitted' entry in its trail", row.history.filter((h) => h === "resubmitted").length === 1, JSON.stringify(row.history));
    if (sc.fixtureSha256) {
      photoOk(row.photo, sc, label);
      expect(label, "the new photo replaced the old file (the old one is gone)", row.photo?.path !== seeded.photoPath && row.originalPhoto?.exists === false, JSON.stringify({ now: row.photo?.path, original: row.originalPhoto }));
    } else {
      expect(label, "photo left untouched: the photo on file is still the same file, same bytes", row.photo?.path === seeded.photoPath && row.photo?.exists === true && row.photo?.sha256 === TINY_SHA, JSON.stringify(row.photo));
    }
  }
  expect("correction", "no extra submissions were created", inspect.corrections.length === Object.keys(state.corrections).length, `${inspect.corrections.length}`);
}

// --- membership applications -----------------------------------------------------------------------------------
{
  const scs = run.results.filter((r) => r.form === "membership");
  for (const sc of scs) {
    const label = `membership ${sc.id}`;
    const matches = inspect.applications.filter((a) => a.name === sc.marker);
    expect(label, "exactly ONE application carries this scenario's marker", matches.length === 1, `${matches.length} applications`);
    const row = matches[0];
    if (!row) continue;
    expect(label, "status pending", row.status === "pending", row.status);
    if (sc.applicationNo) expect(label, "the application number shown to the applicant is the one stored", row.applicationNo === sc.applicationNo, `${row.applicationNo} vs ${sc.applicationNo}`);
    if (sc.fixtureSha256) photoOk(row.photo, sc, label);
    else expect(label, "no photo was sent, none is stored", row.photo === null, JSON.stringify(row.photo));
  }
  expect("membership", "no stray applications", inspect.applications.length === scs.length, `${inspect.applications.length} applications for ${scs.length} scenarios`);
}

// --- member profile --------------------------------------------------------------------------------------------
{
  const scs = run.results.filter((r) => r.form === "profile" && r.id !== "U");
  for (const sc of scs) {
    const label = `profile ${sc.id}`;
    const matches = inspect.profileVersions.filter((v) => v.profession === sc.marker);
    expect(label, "exactly ONE pending revision carries this scenario's marker", matches.length === 1, `${matches.length} revisions`);
    const row = matches[0];
    if (!row) continue;
    expect(label, "status pending, the bio arrived", row.status === "pending" && String(row.bio).includes(sc.marker), JSON.stringify({ status: row.status, bio: row.bio }));
    if (sc.fixtureSha256) photoOk(row.photo, sc, label);
    else expect(label, "no photo was sent, none is stored", row.photo === null, JSON.stringify(row.photo));
  }
  expect("profile", "no stray revisions (the session-less attempt created none)", inspect.profileVersions.length === scs.length, `${inspect.profileVersions.length} revisions for ${scs.length} scenarios`);
  expect("profile", "the visibility switch was not touched by the QA (still off, still unapproved)", inspect.member && inspect.member.publicProfileEnabled === false && inspect.member.publicProfileApproved === false, JSON.stringify(inspect.member));
}

// --- the admin screens -----------------------------------------------------------------------------------------
if (adminBase && adminEmail && adminPassword) {
  const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find(existsSync);
  const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox", "--disable-dev-shm-usage"] });
  try {
    const ctx = await browser.createBrowserContext();
    const page = await ctx.newPage();
    await page.setViewport({ width: 1366, height: 900 });
    const errors = [];
    page.on("console", (m) => { if (m.type() === "error") errors.push(m.text().slice(0, 160)); });
    page.on("pageerror", (e) => errors.push(String(e).slice(0, 160)));

    await page.goto(`${adminBase}/login`, { waitUntil: "networkidle0", timeout: 90000 });
    await page.type("#email", adminEmail, { delay: 4 });
    await page.type("#password", adminPassword, { delay: 4 });
    await Promise.all([page.waitForNavigation({ waitUntil: "networkidle0", timeout: 90000 }), page.click('button[type="submit"]')]);
    expect("admin", "signed in (not left on the login page)", !page.url().includes("/login"), page.url());

    const text = async (path, shotName) => {
      const res = await page.goto(`${adminBase}/admin${path}`, { waitUntil: "networkidle0", timeout: 90000 });
      const body = await page.evaluate(() => document.body.innerText);
      if (shotsDir && shotName) {
        await mkdir(shotsDir, { recursive: true });
        await page.screenshot({ path: resolve(shotsDir, `admin-${shotName}.png`), fullPage: false }).catch(() => {});
      }
      return { status: res?.status() ?? 0, body };
    };

    const committeeId = state.committee.id;
    const list = await text(`/organization/committees/${committeeId}/submissions`, "committee-submissions");
    expect("admin", "committee submissions list opens (200)", list.status === 200, list.status);
    for (const sc of run.results.filter((r) => r.form === "registration")) expect("admin", `committee list shows registration ${sc.id}`, list.body.includes(sc.marker), `marker ${sc.marker} not found`);
    for (const sc of run.results.filter((r) => r.form === "correction")) expect("admin", `committee list shows corrected submission ${sc.id}`, list.body.includes(sc.marker), `marker ${sc.marker} not found`);

    const regA = inspect.registrations.find((r) => r.fullName === scenario("registration", "D")?.marker);
    if (regA) {
      const detail = await text(`/organization/committees/${committeeId}/submissions/${regA.id}`, "committee-submission-detail");
      expect("admin", "a registration's detail page opens and shows the nominee", detail.status === 200 && detail.body.includes(regA.fullName), `${detail.status}`);
    }
    const corrA = inspect.corrections.find((r) => r.fullName === scenario("correction", "A")?.marker);
    if (corrA) {
      const detail = await text(`/organization/committees/${committeeId}/submissions/${corrA.id}`, "committee-correction-detail");
      expect("admin", "a corrected submission's detail page opens, back in the review queue with its 'resubmitted' trail", detail.status === 200 && detail.body.includes(corrA.fullName) && /পুনরায় জমা|সংশোধিত/.test(detail.body), `${detail.status}`);
    }

    const apps = await text("/membership", "membership-applications");
    expect("admin", "membership applications list opens (200)", apps.status === 200, apps.status);
    for (const sc of run.results.filter((r) => r.form === "membership")) {
      const row = inspect.applications.find((a) => a.name === sc.marker);
      expect("admin", `applications list shows membership ${sc.id} (${row?.applicationNo})`, row && (apps.body.includes(row.applicationNo) || apps.body.includes(sc.marker)), `application ${row?.applicationNo}`);
    }
    const appD = inspect.applications.find((a) => a.name === scenario("membership", "D")?.marker);
    if (appD) {
      const detail = await text(`/membership/${appD.id}`, "membership-application-detail");
      expect("admin", "an application's detail page opens and shows the applicant", detail.status === 200 && detail.body.includes(appD.name), `${detail.status}`);
    }

    const member = await text(`/membership/members/${state.member.registryId}`, "member-pending-profile");
    const latest = [...inspect.profileVersions].sort((a, b) => b.id - a.id)[0];
    expect("admin", "the member's page opens and shows the pending profile revision awaiting review", member.status === 200 && latest && member.body.includes(latest.profession), `${member.status} / ${latest?.profession}`);
    expect("admin", "no console error on any admin screen", errors.length === 0, errors.join(" | "));
  } finally {
    await browser.close();
  }
} else {
  expect("admin", "admin screens checked", false, "skipped: no --admin-base/--admin-email/--admin-password given");
}

// ---------------------------------------------------------------------------------------------------------------
const failed = rows.filter((r) => !r.ok);
for (const group of [...new Set(rows.map((r) => r.group))]) {
  const g = rows.filter((r) => r.group === group);
  console.log(`${g.every((r) => r.ok) ? "PASS" : "FAIL"}  ${group.padEnd(16)} ${g.filter((r) => r.ok).length}/${g.length}`);
  for (const r of g.filter((x) => !x.ok)) console.log(`        x ${r.name}${r.detail ? ` — ${r.detail}` : ""}`);
}
console.log(`\n${rows.length} server-side / admin checks, ${failed.length} failed`);
if (outFile) await writeFile(outFile, JSON.stringify({ at: new Date().toISOString(), suffix: state.suffix, rows }, null, 2));
process.exit(failed.length ? 1 : 0);
