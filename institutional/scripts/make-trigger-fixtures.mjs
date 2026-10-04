// Writes the Cloudflare-trigger regression fixtures (see scripts/trigger-fixtures.mjs for what they are and why) — never
// committed (see --out).
//
//   node scripts/make-trigger-fixtures.mjs --from <dir holding photo-mid.jpg> --out <dir>
//
// `--from` is the directory scripts/make-submit-fixtures.mjs wrote: its photo-mid.jpg (a real ~1.2 MB phone-style JPEG,
// drawn in Chrome) is the photograph the planted files are made from, so they are genuinely decodable pictures and
// not stand-ins. Writes trigger-dq/-sq/-deep.jpg, late-trigger.jpg, control.jpg, trigger-dq/-sq.png, control.png and a
// manifest.json saying where each file's trigger sits and what the edge is expected to do with the old Server-Action
// shape ("blocked" / "passes").

import { mkdir, readFile, writeFile } from "node:fs/promises";
import { join, resolve } from "node:path";
import { buildTriggerSet, WINDOW_BYTES } from "./trigger-fixtures.mjs";

const arg = (n, d = null) => {
  const i = process.argv.indexOf(`--${n}`);
  return i === -1 ? d : process.argv[i + 1];
};
const from = arg("from");
const out = resolve(arg("out", "./trigger-fixtures"));
if (!from) {
  console.error("usage: node scripts/make-trigger-fixtures.mjs --from <dir holding photo-mid.jpg> --out <dir>\n(run scripts/make-submit-fixtures.mjs first to make the photo)");
  process.exit(2);
}

const jpeg = await readFile(join(resolve(from), "photo-mid.jpg"));
const set = buildTriggerSet({ jpeg });
await mkdir(out, { recursive: true });

const manifest = { windowBytes: WINDOW_BYTES, files: {} };
for (const file of set) {
  await writeFile(join(out, file.name), file.bytes);
  manifest.files[file.name] = { bytes: file.bytes.length, sha256: file.sha256, mime: file.mime, triggerInWindow: file.trigger, triggerAnywhere: file.anywhere, oldShape: file.oldShape };
  console.log(`${file.name.padEnd(18)} ${String(file.bytes.length).padStart(9)} bytes  trigger in first MiB: ${file.trigger ? `${file.trigger.kind} @ ${file.trigger.offset}` : "none"}  -> old Server Action shape: ${file.oldShape}`);
}
await writeFile(join(out, "manifest.json"), JSON.stringify(manifest, null, 2));
console.log(`\nwrote ${set.length} files + manifest.json to ${out}`);
