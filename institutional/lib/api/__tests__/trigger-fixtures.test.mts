/**
 * Run with: npm run test:api (see package.json)
 *
 * The byte sequences behind the Cloudflare "React - Leaking Server Functions" false positive, built on purpose
 * (scripts/trigger-fixtures.mjs), and what this app does with a file that holds them.
 *
 * What a unit test CAN show: the fixtures really hold `"$F` / `'$F` where they are meant to (inside the rule's first
 * MiB, or just outside it), stay structurally valid images, and travel through every upload route to Laravel byte for
 * byte. What it cannot show is the edge's verdict — that rule lives at Cloudflare — which is why
 * scripts/cloudflare-upload-probe.mjs sends the very same files to production, in the old Server-Action shape and in
 * the new route shape, and reports both.
 */
import { test, before, after, mock } from "node:test";
import assert from "node:assert/strict";
import { inflateSync } from "node:zlib";
import { buildNoisePng, buildTriggerSet, crc32, filler, findTrigger, jpegWithComments, pngWithText, TRIGGERS, WINDOW_BYTES } from "../../../scripts/trigger-fixtures.mjs";
import { handleCommitteeCorrectionPost, handleCommitteeRegistrationPost, handleMemberProfilePost, handleMembershipApplicationPost } from "../../upload-handlers.ts";
import { handleApplicationPost } from "../../volunteer-application-post.ts";

const ORIGINAL_ENV = process.env.LARAVEL_API_URL;
const ORIGINAL_FETCH = globalThis.fetch;
before(() => {
  process.env.LARAVEL_API_URL = "https://admin.example.test";
});
after(() => {
  process.env.LARAVEL_API_URL = ORIGINAL_ENV;
  globalThis.fetch = ORIGINAL_FETCH;
});

/** Just enough JPEG to be one structurally: SOI, a JFIF APP0, a stand-in table segment, EOI. (Decoding is Laravel's job.) */
const FAKE_JPEG = Buffer.concat([
  Buffer.from([0xff, 0xd8]),
  Buffer.from([0xff, 0xe0, 0x00, 0x10, 0x4a, 0x46, 0x49, 0x46, 0x00, 0x01, 0x01, 0x00, 0x00, 0x01, 0x00, 0x01, 0x00, 0x00]),
  Buffer.from([0xff, 0xdb, 0x00, 0x04, 0x00, 0x08]),
  filler(4096, 5),
  Buffer.from([0xff, 0xd9]),
]);
const PNG = buildNoisePng({ width: 96, height: 64, seed: 3 });
const SET = buildTriggerSet({ jpeg: FAKE_JPEG, png: PNG });
const byName = (name: string) => {
  const file = SET.find((f: { name: string }) => f.name === name);
  assert.ok(file, `fixture ${name} missing`);
  return file as (typeof SET)[number];
};

// ---------------------------------------------------------------------------
// The rule's own bytes
// ---------------------------------------------------------------------------

test("the trigger bytes are exactly `\"$F` and `'$F`, and findTrigger honours a 1 MiB window", () => {
  assert.deepEqual(TRIGGERS.map((t: { bytes: Buffer }) => t.bytes.toString()), ['"$F', "'$F"]);
  assert.equal(WINDOW_BYTES, 1_048_576);

  const body = Buffer.alloc(WINDOW_BYTES + 100, 0x41);
  body.set(Buffer.from('"$F'), WINDOW_BYTES - 3); // the last three bytes of the window
  assert.deepEqual(findTrigger(body), { offset: WINDOW_BYTES - 3, kind: "double-quote" });
  const outside = Buffer.alloc(WINDOW_BYTES + 100, 0x41);
  outside.set(Buffer.from("'$F"), WINDOW_BYTES - 2); // straddles the end of the window: not wholly inside it
  assert.equal(findTrigger(outside), null);
  assert.deepEqual(findTrigger(outside, outside.length), { offset: WINDOW_BYTES - 2, kind: "single-quote" });
  assert.equal(findTrigger(Buffer.from('$F "$ F "$H \'$K')), null, "near misses never match: only `\"$F` and `'$F` do");
});

test("filler never holds a trigger (no `$` at all) and is the same for the same seed", () => {
  const a = filler(200_000, 9);
  assert.equal(a.includes(0x24), false);
  assert.equal(findTrigger(a, a.length), null);
  assert.deepEqual(a, filler(200_000, 9));
  assert.notDeepEqual(a, filler(200_000, 10));
});

// ---------------------------------------------------------------------------
// The set
// ---------------------------------------------------------------------------

test("the set has the planted files, the clean controls and the window-edge pair — and each one's verdict follows from where its bytes are", () => {
  for (const name of ["trigger-dq.jpg", "trigger-sq.jpg", "trigger-deep.jpg", "trigger-dq.png", "trigger-sq.png"]) {
    const file = byName(name);
    assert.ok(file.trigger, `${name} must hold a trigger inside the window`);
    assert.equal(file.oldShape, "blocked", name);
  }
  assert.equal(byName("trigger-dq.jpg").trigger?.kind, "double-quote");
  assert.equal(byName("trigger-sq.jpg").trigger?.kind, "single-quote");
  assert.equal(byName("trigger-dq.png").trigger?.kind, "double-quote");
  assert.equal(byName("trigger-sq.png").trigger?.kind, "single-quote");

  const deepAt = byName("trigger-deep.jpg").trigger?.offset ?? -1;
  assert.ok(deepAt > 990_000 && deepAt < WINDOW_BYTES - 20_000, `deep trigger at ${deepAt}: it must sit well inside the window, near its far end`);

  const late = byName("late-trigger.jpg");
  assert.equal(late.trigger, null, "outside the window the rule does not look");
  assert.ok(late.anywhere && late.anywhere.offset > WINDOW_BYTES + 20_000, "...but the bytes ARE in the file, just past the window");
  assert.equal(late.oldShape, "passes");

  for (const name of ["control.jpg", "control.png"]) {
    const file = byName(name);
    assert.equal(file.anywhere, null, `${name} must hold no trigger anywhere`);
    assert.equal(file.oldShape, "passes");
  }
});

test("controls and triggers of the same kind are the same size, so size is not what separates them", () => {
  assert.equal(byName("control.jpg").bytes.length, byName("trigger-dq.jpg").bytes.length);
  assert.equal(byName("control.png").bytes.length, byName("trigger-dq.png").bytes.length);
});

test("a base photo that already holds the trigger bytes is rejected: a control must be clean by construction", () => {
  const tainted = Buffer.concat([FAKE_JPEG.subarray(0, FAKE_JPEG.length - 2), Buffer.from('"$F'), Buffer.from([0xff, 0xd9])]);
  assert.throws(() => buildTriggerSet({ jpeg: tainted, png: PNG }), /already holds double-quote trigger bytes/);
});

// ---------------------------------------------------------------------------
// They are still real images
// ---------------------------------------------------------------------------

function pngChunks(png: Buffer) {
  assert.deepEqual(png.subarray(0, 8), Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), "PNG signature");
  const chunks: Array<{ type: string; data: Buffer }> = [];
  for (let at = 8; at < png.length; ) {
    const length = png.readUInt32BE(at);
    const type = png.subarray(at + 4, at + 8).toString("ascii");
    const data = png.subarray(at + 8, at + 8 + length);
    assert.equal(png.readUInt32BE(at + 8 + length), crc32(png.subarray(at + 4, at + 8 + length)), `bad CRC in ${type}`);
    chunks.push({ type, data });
    at += 12 + length;
  }
  return chunks;
}

test("the PNG fixtures are structurally valid: every chunk's CRC checks, IDAT inflates to exactly the pixels, IEND closes it", () => {
  for (const name of ["trigger-dq.png", "trigger-sq.png", "control.png"]) {
    const chunks = pngChunks(byName(name).bytes);
    assert.deepEqual(chunks.map((c) => c.type), ["IHDR", "tEXt", "IDAT", "IEND"], name);
    const ihdr = chunks[0].data;
    assert.equal(ihdr.readUInt32BE(0), 96);
    assert.equal(ihdr.readUInt32BE(4), 64);
    assert.equal(inflateSync(chunks[2].data).length, (1 + 96 * 3) * 64, `${name}: the pixel data is the size of a 96x64 RGB image`);
  }
  assert.match(pngChunks(byName("trigger-dq.png").bytes)[1].data.toString("latin1"), /^Comment\0QA regression fixture.*"\$F/);
});

test("the JPEG fixtures keep SOI, the JFIF segment first, then the comment segment(s), then the rest untouched", () => {
  const out = byName("trigger-dq.jpg").bytes;
  assert.deepEqual(out.subarray(0, 4), Buffer.from([0xff, 0xd8, 0xff, 0xe0]));
  const afterApp0 = 2 + 2 + 16;
  assert.deepEqual(out.subarray(afterApp0, afterApp0 + 2), Buffer.from([0xff, 0xfe]), "the comment follows APP0");
  const commentLength = out.readUInt16BE(afterApp0 + 2);
  assert.deepEqual(out.subarray(afterApp0 + 2 + commentLength), FAKE_JPEG.subarray(afterApp0), "everything after the comment is the original, byte for byte");
  assert.deepEqual(out.subarray(0, afterApp0), FAKE_JPEG.subarray(0, afterApp0));
  assert.throws(() => jpegWithComments(Buffer.from("not a jpeg"), []), /not a JPEG/);
  assert.throws(() => jpegWithComments(FAKE_JPEG, [Buffer.alloc(65_534)]), /at most 65,533/);
});

test("pngWithText only adds a chunk; the picture is the one it was", () => {
  const base = buildNoisePng({ width: 40, height: 40, seed: 1 });
  const marked = pngWithText(base, "Comment", 'a "$F b');
  assert.equal(pngChunks(marked).find((c) => c.type === "IDAT")?.data.equals(pngChunks(base).find((c) => c.type === "IDAT")!.data), true);
});

// ---------------------------------------------------------------------------
// ...and every route forwards them untouched
// ---------------------------------------------------------------------------

const SITE = "https://provatferi.org";
const BROWSER = { origin: SITE, "sec-fetch-site": "same-origin" };
const ROUTES: Array<{ name: string; endpoint: string; handle: (request: Request) => Promise<Response> }> = [
  { name: "committee registration", endpoint: "/api/committee/register/tok", handle: (r) => handleCommitteeRegistrationPost(r, "tok") },
  { name: "committee correction", endpoint: "/api/committee/correct/tok", handle: (r) => handleCommitteeCorrectionPost(r, "tok") },
  { name: "membership application", endpoint: "/api/membership/apply", handle: (r) => handleMembershipApplicationPost(r) },
  { name: "member profile", endpoint: "/api/member/profile", handle: (r) => handleMemberProfilePost(r, "1|session") },
  { name: "volunteer application", endpoint: "/api/recruitment/posting/apply", handle: (r) => handleApplicationPost(r, "posting") },
];

for (const route of ROUTES) {
  test(`${route.name}: every fixture — planted, deep, late and control, JPEG and PNG — reaches Laravel byte for byte`, async () => {
    for (const fixture of SET) {
      let forwarded: File | null = null;
      globalThis.fetch = mock.fn(async (_url: string | URL | Request, init?: RequestInit) => {
        forwarded = (init?.body as FormData).get("photo") as File;
        return new Response(JSON.stringify({ data: { id: 1, application_no: "A-1" }, message: "ok" }), { status: 201, headers: { "Content-Type": "application/json" } });
      }) as typeof fetch;

      const form = new FormData();
      form.set("full_name", "QA");
      form.set("photo", new File([fixture.bytes], fixture.name, { type: fixture.mime }));
      const res = await route.handle(new Request(`${SITE}${route.endpoint}`, { method: "POST", body: form, headers: BROWSER }));

      assert.equal(res.status, 200, `${fixture.name}: ${await res.clone().text()}`);
      assert.equal((await res.json()).status, "success", fixture.name);
      assert.ok(forwarded, `${fixture.name}: nothing was forwarded`);
      const sent = new Uint8Array(await (forwarded as File).arrayBuffer());
      assert.equal(sent.length, fixture.bytes.length, `${fixture.name}: size changed on the way`);
      assert.equal(Buffer.from(sent).equals(fixture.bytes), true, `${fixture.name}: bytes changed on the way`);
    }
  });
}
