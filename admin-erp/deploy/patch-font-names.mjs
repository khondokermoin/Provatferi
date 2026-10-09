/**
 * Build-time only (never shipped as a script — only its output is): gives a TrueType font new internal NAMES and changes
 * nothing else.
 *
 * Why: mPDF writes every font it embeds into the PDF as `MPDFAA+<PostScript name>` — the same subset tag for every font — so
 * two DIFFERENT font programs with the same PostScript name end up as two objects with the SAME name in one document,
 * which the PDF specification forbids and which a viewer that identifies fonts by name (a phone previewer, an older
 * reader) can draw wrongly: glyph ids of one program looked up in the other. A receipt carries the Bengali from Noto Sans
 * Bengali 2.001 and the Latin letters from 3.011 — both are called "NotoSansBengali-Regular". The 2.001 files in
 * resources/fonts (NotoSansBengali-Shaping-*.ttf) are therefore stored with the PostScript/full/unique names
 * "NotoSansBengaliShaping-Regular|Bold"; every other table is byte-identical to the upstream release.
 *
 * What it rewrites: the `name` table (IDs 3, 4, 6), its table-directory entry and checksum, `head.checkSumAdjustment`, and
 * the table offsets that moved because the name table's length changed. Glyphs, cmap, GSUB, GPOS, GDEF, metrics: untouched
 * (deploy/verify-pdf-fonts.mjs and tests/Unit/Pdf/PdfFontFilesTest.php prove it against recorded hashes).
 *
 * Run: node deploy/patch-font-names.mjs <in.ttf> <out.ttf> <PostScriptName> "<Full name>"
 *   e.g. node deploy/patch-font-names.mjs upstream/NotoSansBengali-Regular-2.001.ttf resources/fonts/NotoSansBengali-Shaping-Regular.ttf NotoSansBengaliShaping-Regular "Noto Sans Bengali Shaping Regular"
 */
import fs from "node:fs";

export function checksum(buf) {
  let sum = 0;
  const padded = Buffer.concat([buf, Buffer.alloc((4 - (buf.length % 4)) % 4)]);
  for (let i = 0; i < padded.length; i += 4) sum = (sum + padded.readUInt32BE(i)) >>> 0;
  return sum;
}

export function readTables(font) {
  const numTables = font.readUInt16BE(4);
  const tables = [];
  for (let i = 0; i < numTables; i++) {
    const o = 12 + i * 16;
    const tag = font.toString("latin1", o, o + 4);
    const offset = font.readUInt32BE(o + 8);
    const length = font.readUInt32BE(o + 12);
    tables.push({ tag, offset, length, data: font.subarray(offset, offset + length) });
  }
  return { header: font.subarray(0, 12), tables };
}

/** The `name` table with the given Windows/Unicode (platform 3, language 0x409) strings replaced; all other records kept. */
export function patchNameTable(name, replacements) {
  const count = name.readUInt16BE(2);
  const stringOffset = name.readUInt16BE(4);
  const records = [];
  for (let i = 0; i < count; i++) {
    const r = 6 + i * 12;
    const rec = { platform: name.readUInt16BE(r), encoding: name.readUInt16BE(r + 2), language: name.readUInt16BE(r + 4), id: name.readUInt16BE(r + 6) };
    const len = name.readUInt16BE(r + 8);
    const off = name.readUInt16BE(r + 10);
    rec.raw = name.subarray(stringOffset + off, stringOffset + off + len);
    if (rec.platform === 3 && rec.language === 0x409 && replacements[rec.id] !== undefined) {
      rec.raw = Buffer.from(replacements[rec.id], "utf16le").swap16();
    }
    records.push(rec);
  }
  if (name.readUInt16BE(0) !== 0) throw new Error("only name table format 0 is supported");
  records.sort((a, b) => a.platform - b.platform || a.encoding - b.encoding || a.language - b.language || a.id - b.id);

  const header = Buffer.alloc(6 + records.length * 12);
  header.writeUInt16BE(0, 0);
  header.writeUInt16BE(records.length, 2);
  header.writeUInt16BE(header.length, 4);
  const strings = [];
  let offset = 0;
  records.forEach((rec, i) => {
    const r = 6 + i * 12;
    header.writeUInt16BE(rec.platform, r);
    header.writeUInt16BE(rec.encoding, r + 2);
    header.writeUInt16BE(rec.language, r + 4);
    header.writeUInt16BE(rec.id, r + 6);
    header.writeUInt16BE(rec.raw.length, r + 8);
    header.writeUInt16BE(offset, r + 10);
    strings.push(rec.raw);
    offset += rec.raw.length;
  });

  return Buffer.concat([header, ...strings]);
}

/** The Windows/English string of a name ID, or null. */
export function readName(name, id) {
  const count = name.readUInt16BE(2);
  const stringOffset = name.readUInt16BE(4);
  for (let i = 0; i < count; i++) {
    const r = 6 + i * 12;
    if (name.readUInt16BE(r) === 3 && name.readUInt16BE(r + 4) === 0x409 && name.readUInt16BE(r + 6) === id) {
      const len = name.readUInt16BE(r + 8);
      const off = name.readUInt16BE(r + 10);
      return Buffer.from(name.subarray(stringOffset + off, stringOffset + off + len)).swap16().toString("utf16le");
    }
  }

  return null;
}

export function patchFont(font, postScriptName, fullName) {
  const { header, tables } = readTables(font);
  const nameTable = tables.find((t) => t.tag === "name");
  const version = /(\d+\.\d+)/.exec(readName(nameTable.data, 5) ?? "")?.[1] ?? "0.000";
  nameTable.data = patchNameTable(nameTable.data, { 3: `${version};GOOG;${postScriptName}`, 4: fullName, 6: postScriptName });

  // Lay the tables out again in their original order, 4-byte aligned, with fresh checksums.
  const dirSize = 12 + tables.length * 16;
  const order = [...tables].sort((a, b) => a.offset - b.offset);
  let pos = dirSize;
  for (const t of order) {
    t.newOffset = pos;
    pos += Math.ceil(t.data.length / 4) * 4;
  }
  const out = Buffer.alloc(pos);
  header.copy(out, 0);
  for (const t of order) t.data.copy(out, t.newOffset);
  const headTable = tables.find((t) => t.tag === "head");
  out.writeUInt32BE(0, headTable.newOffset + 8); // checkSumAdjustment must be 0 while checksums are computed
  tables.forEach((t, i) => {
    const o = 12 + i * 16;
    out.write(t.tag, o, "latin1");
    out.writeUInt32BE(checksum(out.subarray(t.newOffset, t.newOffset + t.data.length)), o + 4);
    out.writeUInt32BE(t.newOffset, o + 8);
    out.writeUInt32BE(t.data.length, o + 12);
  });
  out.writeUInt32BE((0xb1b0afba - checksum(out)) >>> 0, headTable.newOffset + 8);

  return out;
}

if (import.meta.url === `file:///${process.argv[1].replace(/\\/g, "/")}` || import.meta.url.endsWith(process.argv[1].replace(/\\/g, "/"))) {
  const [input, output, postScriptName, fullName] = process.argv.slice(2);
  if (!input || !output || !postScriptName || !fullName) {
    console.error('usage: node deploy/patch-font-names.mjs <in.ttf> <out.ttf> <PostScriptName> "<Full name>"');
    process.exit(2);
  }
  const patched = patchFont(fs.readFileSync(input), postScriptName, fullName);
  fs.writeFileSync(output, patched);
  console.log(`${input} -> ${output} (${patched.length} bytes), PostScript name ${postScriptName}`);
}
