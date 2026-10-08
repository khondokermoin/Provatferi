// Looks at PDFs the way a reader would: renders every page to a PNG and pulls the text back out (Membership task 5,
// 2026-10-08 — the receipt PDFs saved by membership-receipts-qa.mjs). No second PDF engine is added to the product: this is a
// QA tool, run on a developer machine with Chrome and pdf.js (`npm i pdfjs-dist --no-save` in a scratch directory, passed as
// --pdfjs). Text is extracted by pdf.js; the screenshots are the proof of the glyphs (Bengali conjuncts, the logo, spacing).
//
//   node scripts/pdf-rasterize.mjs --pdfjs <dir with node_modules/pdfjs-dist> --dir <the QA output dir> [--scale 2]
//
// Reads <dir>/pdf-manifest.json ([{name, file, expect: [strings]}]), writes <dir>/png/<name>-p<n>.png and <dir>/txt/<name>.txt,
// and checks that every expected string is in the extracted text (compared without whitespace, NFC-normalised).

import { createServer } from "node:http";
import { existsSync, readFileSync } from "node:fs";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { extname, join, resolve } from "node:path";
import puppeteer from "puppeteer-core";

const arg = (n, d = null) => {
  const i = process.argv.indexOf(`--${n}`);
  return i === -1 ? d : process.argv[i + 1];
};
const dir = resolve(arg("dir", "."));
const pdfjsRoot = resolve(arg("pdfjs"), "node_modules", "pdfjs-dist", "build");
const scale = Number(arg("scale", "2"));
const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find(existsSync);
if (!chrome) throw new Error("No Chrome found; set CHROME_PATH");
if (!existsSync(join(pdfjsRoot, "pdf.mjs"))) throw new Error(`pdf.js not found in ${pdfjsRoot}`);

const manifest = JSON.parse(await readFile(join(dir, "pdf-manifest.json"), "utf8"));
await mkdir(join(dir, "png"), { recursive: true });
await mkdir(join(dir, "txt"), { recursive: true });

const TYPES = { ".mjs": "text/javascript", ".js": "text/javascript", ".pdf": "application/pdf", ".html": "text/html; charset=utf-8" };
const PAGE = `<!doctype html><meta charset="utf-8"><body style="margin:0;background:#888"><canvas id="c"></canvas>
<script type="module">
import * as pdfjs from "/pdfjs/pdf.mjs";
pdfjs.GlobalWorkerOptions.workerSrc = "/pdfjs/pdf.worker.mjs";
window.render = async (b64, scale) => {
  // The bytes come in from Node, not over HTTP: a download manager on the machine (IDM) would answer a PDF request with its own stub.
  const data = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
  const doc = await pdfjs.getDocument({ data, useSystemFonts: false }).promise;
  const pages = [];
  for (let n = 1; n <= doc.numPages; n++) {
    const page = await doc.getPage(n);
    const viewport = page.getViewport({ scale });
    const canvas = document.getElementById("c");
    canvas.width = Math.ceil(viewport.width); canvas.height = Math.ceil(viewport.height);
    const ctx = canvas.getContext("2d");
    ctx.fillStyle = "#fff"; ctx.fillRect(0, 0, canvas.width, canvas.height);
    await page.render({ canvasContext: ctx, viewport }).promise;
    const text = (await page.getTextContent()).items.map((i) => i.str + (i.hasEOL ? "\\n" : " ")).join("");
    pages.push({ n, width: canvas.width, height: canvas.height, png: canvas.toDataURL("image/png").split(",")[1], text });
  }
  return { pages, numPages: doc.numPages };
};
window.ready = true;
</script>`;

const server = createServer(async (req, res) => {
  const url = new URL(req.url, "http://x");
  try {
    let file;
    if (url.pathname === "/") { res.writeHead(200, { "Content-Type": TYPES[".html"] }); res.end(PAGE); return; }
    if (url.pathname.startsWith("/pdfjs/")) file = join(pdfjsRoot, url.pathname.slice(7));
    else { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { "Content-Type": TYPES[extname(file)] ?? "application/octet-stream" });
    res.end(readFileSync(file));
  } catch {
    res.writeHead(404);
    res.end();
  }
});
await new Promise((r) => server.listen(0, "127.0.0.1", r));
const origin = `http://127.0.0.1:${server.address().port}`;

const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox", "--disable-dev-shm-usage"] });
const page = await browser.newPage();
const errors = [];
page.on("pageerror", (e) => errors.push(String(e).slice(0, 200)));
await page.goto(`${origin}/`);
await page.waitForFunction(() => window.ready === true, { timeout: 30000 });

const norm = (s) => s.normalize("NFC").replace(/\s+/g, "");
let failures = 0;
for (const entry of manifest) {
  const result = await page.evaluate((b64, s) => window.render(b64, s), (await readFile(join(dir, entry.file))).toString("base64"), scale);
  const text = result.pages.map((p) => p.text).join("\n");
  await writeFile(join(dir, "txt", `${entry.name}.txt`), text);
  for (const p of result.pages) await writeFile(join(dir, "png", `${entry.name}-p${p.n}.png`), Buffer.from(p.png, "base64"));
  const haystack = norm(text);
  const missing = (entry.expect ?? []).filter((s) => !haystack.includes(norm(s)));
  const ok = missing.length === 0;
  if (!ok) failures++;
  console.log(`${ok ? "  ok  " : "  FAIL"} ${entry.name}: ${result.numPages} page(s) ${result.pages.map((p) => `${p.width}x${p.height}`).join(", ")}${ok ? "" : ` — missing from the text: ${JSON.stringify(missing)}`}`);
}
if (errors.length) { failures++; console.log("  FAIL page errors:", errors); }
await browser.close();
server.close();
console.log(`\n${manifest.length - failures}/${manifest.length} PDFs rendered and carry the expected text. PNGs in ${join(dir, "png")}`);
process.exit(failures ? 1 : 0);
