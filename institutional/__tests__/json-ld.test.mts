import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import test from "node:test";
import { safeJsonLd } from "../lib/json-ld.ts";

const ROOT = new URL("..", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1");

test("a value that tries to close the script element cannot", () => {
  const out = safeJsonLd({ email: "x</script><script>alert(1)</script>", name: "<b>&amp;</b>" });

  assert.equal(out.includes("<"), false, "no raw <");
  assert.equal(out.includes(">"), false, "no raw >");
  assert.equal(out.includes("&"), false, "no raw &");
  assert.ok(out.includes("\\u003c/script\\u003e"));
});

test("U+2028 and U+2029 are escaped (they end a line in JavaScript source)", () => {
  const out = safeJsonLd({ address: `a${String.fromCharCode(0x2028)}b${String.fromCharCode(0x2029)}c` });

  assert.equal(out.includes(String.fromCharCode(0x2028)), false);
  assert.equal(out.includes(String.fromCharCode(0x2029)), false);
});

test("the escaped text is the same JSON: parsing it gives the original value back", () => {
  const value = {
    "@type": "Organization",
    email: "info@provatferi.org",
    telephone: "+8801625050408",
    sameAs: ["https://www.facebook.com/provatfericenter?a=1&b=2"],
    note: `x</script>${String.fromCharCode(0x2028)}<!-- y -->`,
    bangla: "প্রভাতফেরী",
  };

  assert.deepEqual(JSON.parse(safeJsonLd(value)), value);
});

test("the root layout writes its structured data through safeJsonLd, never a bare JSON.stringify", () => {
  const source = readFileSync(join(ROOT, "app", "[locale]", "layout.tsx"), "utf8");

  assert.match(source, /safeJsonLd\(entry\)/);
  assert.doesNotMatch(source, /JSON\.stringify\(entry\)/);
});
