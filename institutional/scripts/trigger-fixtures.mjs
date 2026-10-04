// Fixtures for the Cloudflare "React - Leaking Server Functions" (CVE-2025-55183) false positive.
//
// The managed WAF rule refuses a request that carries a `Next-Action` header (every Server Action call) whenever
// the FIRST 1 MiB of its body contains the bytes `"$F` or `'$F`. A photo holds them by chance about once per 8 MB,
// so ~1 upload in 9 was refused with a bare 403 — always the same file. These helpers build files that hold the
// bytes ON PURPOSE, at known offsets, while staying genuinely valid images (the server decodes them), so a
// regression test or a production probe does not have to wait for a photo that happens to be unlucky:
//
//   - a valid PNG or JPEG with the trigger planted in a metadata segment (tEXt / COM) inside the first MiB;
//   - the same file with benign text of the same length (the control);
//   - a file whose trigger sits just INSIDE the window and one just OUTSIDE it (measured: seen up to byte
//     1,048,576, not beyond), which pins the "first 1 MiB" behaviour the whole design rests on.
//
// Pure functions, no I/O and no dependencies: scripts/make-trigger-fixtures.mjs writes the files,
// scripts/cloudflare-upload-probe.mjs sends them to the edge, and lib/api/__tests__/trigger-fixtures.test.mts
// pushes them through the route handlers.

import { createHash } from "node:crypto";
import { deflateSync } from "node:zlib";

/** How far into a request body the rule looks. */
export const WINDOW_BYTES = 1_048_576;

export const TRIGGERS = [
  { kind: "double-quote", bytes: Buffer.from('"$F') },
  { kind: "single-quote", bytes: Buffer.from("'$F") },
];

/** The first trigger inside the first `window` bytes, as { offset, kind } — or null when there is none. */
export function findTrigger(bytes, window = WINDOW_BYTES) {
  const head = Buffer.from(bytes.buffer, bytes.byteOffset, Math.min(bytes.length, window));
  let found = null;
  for (const trigger of TRIGGERS) {
    const offset = head.indexOf(trigger.bytes);
    if (offset !== -1 && (found === null || offset < found.offset)) found = { offset, kind: trigger.kind };
  }
  return found;
}

export const sha256 = (bytes) => createHash("sha256").update(bytes).digest("hex");

// ---------------------------------------------------------------------------
// Deterministic filler that cannot contain a trigger (no `$` at all)
// ---------------------------------------------------------------------------

function mulberry32(seed) {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

const FILLER_ALPHABET = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 .,;:-_/";

/** `length` bytes of readable filler, identical for the same seed, never containing `$`. */
export function filler(length, seed = 1) {
  const next = mulberry32(seed);
  const out = Buffer.alloc(length);
  for (let i = 0; i < length; i++) out[i] = FILLER_ALPHABET.charCodeAt(Math.floor(next() * FILLER_ALPHABET.length));
  return out;
}

// ---------------------------------------------------------------------------
// PNG
// ---------------------------------------------------------------------------

const CRC_TABLE = (() => {
  const table = new Uint32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    table[n] = c >>> 0;
  }
  return table;
})();

export function crc32(bytes) {
  let c = 0xffffffff;
  for (let i = 0; i < bytes.length; i++) c = CRC_TABLE[(c ^ bytes[i]) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
}

function pngChunk(type, data) {
  const length = Buffer.alloc(4);
  length.writeUInt32BE(data.length);
  const body = Buffer.concat([Buffer.from(type, "ascii"), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(body));
  return Buffer.concat([length, body, crc]);
}

const PNG_SIGNATURE = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);

/**
 * A valid RGB PNG of smooth colour plus noise (so it is the size of a real picture, not a few hundred bytes).
 * Retries seeds until the picture itself holds no trigger anywhere — a control must be clean by construction.
 */
export function buildNoisePng({ width = 900, height = 700, seed = 7 } = {}) {
  for (let attempt = 0; attempt < 50; attempt++) {
    const next = mulberry32(seed + attempt);
    const stride = 1 + width * 3;
    const raw = Buffer.alloc(stride * height);
    for (let y = 0; y < height; y++) {
      raw[y * stride] = 0; // filter: none
      for (let x = 0; x < width; x++) {
        const base = y * stride + 1 + x * 3;
        const noise = () => Math.floor((next() - 0.5) * 70);
        raw[base] = Math.max(0, Math.min(255, Math.round(120 + 100 * Math.sin(x / 90)) + noise()));
        raw[base + 1] = Math.max(0, Math.min(255, Math.round(110 + 90 * Math.cos(y / 70)) + noise()));
        raw[base + 2] = Math.max(0, Math.min(255, Math.round(100 + 80 * Math.sin((x + y) / 130)) + noise()));
      }
    }
    const header = Buffer.alloc(13);
    header.writeUInt32BE(width, 0);
    header.writeUInt32BE(height, 4);
    header[8] = 8; // bit depth
    header[9] = 2; // colour type: RGB
    const png = Buffer.concat([PNG_SIGNATURE, pngChunk("IHDR", header), pngChunk("IDAT", deflateSync(raw, { level: 6 })), pngChunk("IEND", Buffer.alloc(0))]);
    if (findTrigger(png, png.length) === null) return png;
  }
  throw new Error("could not build a trigger-free noise PNG");
}

/** Inserts a tEXt chunk (keyword + text) right after IHDR; the file stays a valid PNG. */
export function pngWithText(png, keyword, text) {
  const ihdrEnd = 8 + 4 + 4 + 13 + 4; // signature + length + type + IHDR data + crc
  const data = Buffer.concat([Buffer.from(keyword, "latin1"), Buffer.from([0]), Buffer.from(text, "latin1")]);
  return Buffer.concat([png.subarray(0, ihdrEnd), pngChunk("tEXt", data), png.subarray(ihdrEnd)]);
}

// ---------------------------------------------------------------------------
// JPEG
// ---------------------------------------------------------------------------

/** One COM segment (FF FE, 2-byte length, payload) — at most 65,533 payload bytes. */
function jpegComment(payload) {
  if (payload.length > 65_533) throw new Error("a JPEG comment segment holds at most 65,533 bytes");
  const head = Buffer.from([0xff, 0xfe, 0, 0]);
  head.writeUInt16BE(payload.length + 2, 2);
  return Buffer.concat([head, payload]);
}

/**
 * Inserts comment segments right after the leading APPn segments (JFIF / Exif stay first, as a decoder expects) and
 * before the tables and the scan. Any number of segments may sit there; the file stays a valid JPEG.
 */
export function jpegWithComments(jpeg, payloads) {
  if (jpeg[0] !== 0xff || jpeg[1] !== 0xd8) throw new Error("not a JPEG");
  let at = 2;
  while (at + 4 <= jpeg.length && jpeg[at] === 0xff && jpeg[at + 1] >= 0xe0 && jpeg[at + 1] <= 0xef) at += 2 + jpeg.readUInt16BE(at + 2);
  return Buffer.concat([jpeg.subarray(0, at), ...payloads.map(jpegComment), jpeg.subarray(at)]);
}

/** Filler split into comment-sized payloads. */
export function fillerPayloads(total, seed) {
  const payloads = [];
  for (let left = total, i = 0; left > 0; i++) {
    const size = Math.min(60_000, left);
    payloads.push(filler(size, seed + i));
    left -= size;
  }
  return payloads;
}

// ---------------------------------------------------------------------------
// The set
// ---------------------------------------------------------------------------

/**
 * Every fixture a QA run needs. Each entry: { name, bytes, mime, trigger, oldShape }.
 *  - `trigger` is where the rule would see the bytes ({ offset, kind }) or null;
 *  - `oldShape` is what the edge does to a Server-Action-shaped request carrying it: "blocked" (403 from
 *    Cloudflare) or "passes" — derived from the window, not asserted by hand.
 * `jpeg` is a real photograph (scripts/make-submit-fixtures.mjs makes realistic ones); it must itself be clean.
 */
export function buildTriggerSet({ jpeg, png = buildNoisePng() }) {
  const naturalJpeg = findTrigger(jpeg, jpeg.length);
  if (naturalJpeg) throw new Error(`the base JPEG already holds ${naturalJpeg.kind} trigger bytes at offset ${naturalJpeg.offset} — pick another photo so the control is clean`);

  // The same length on purpose: a control and its planted twin differ in nothing but the bytes the rule hunts for.
  const planted = (kind) => Buffer.from(`QA regression fixture, Cloudflare WAF rule 3114709a: ${kind === "double-quote" ? '"$F' : "'$F"} (planted)`);
  const benign = (kind) => Buffer.from(`QA regression fixture, Cloudflare WAF rule 3114709a: ${kind === "double-quote" ? '"$G' : "'$G"} (control)`);
  const deepPad = fillerPayloads(1_000_000, 100); // the trigger lands ~1,000,000 bytes in: inside the window
  const latePad = fillerPayloads(1_100_000, 200); // ...and ~1,100,000 bytes in: outside it

  const files = [
    { name: "trigger-dq.jpg", mime: "image/jpeg", bytes: jpegWithComments(jpeg, [planted("double-quote")]) },
    { name: "trigger-sq.jpg", mime: "image/jpeg", bytes: jpegWithComments(jpeg, [planted("single-quote")]) },
    { name: "trigger-deep.jpg", mime: "image/jpeg", bytes: jpegWithComments(jpeg, [...deepPad, planted("double-quote")]) },
    { name: "late-trigger.jpg", mime: "image/jpeg", bytes: jpegWithComments(jpeg, [...latePad, planted("double-quote")]) },
    { name: "control.jpg", mime: "image/jpeg", bytes: jpegWithComments(jpeg, [benign("double-quote")]) },
    { name: "trigger-dq.png", mime: "image/png", bytes: pngWithText(png, "Comment", planted("double-quote").toString("latin1")) },
    { name: "trigger-sq.png", mime: "image/png", bytes: pngWithText(png, "Comment", planted("single-quote").toString("latin1")) },
    { name: "control.png", mime: "image/png", bytes: pngWithText(png, "Comment", benign("double-quote").toString("latin1")) },
  ];

  return files.map((file) => {
    const trigger = findTrigger(file.bytes);
    const anywhere = findTrigger(file.bytes, file.bytes.length);
    return { ...file, trigger, anywhere, oldShape: trigger ? "blocked" : "passes", sha256: sha256(file.bytes) };
  });
}
