import { test } from "node:test";
import assert from "node:assert/strict";
import { excerpt, parseTextBlocks, shortenUrl, splitLinks } from "../../text-blocks.ts";

test("blank lines split paragraphs; single line breaks stay inside one paragraph", () => {
  assert.deepEqual(parseTextBlocks("প্রথম লাইন\nদ্বিতীয় লাইন\n\nনতুন অনুচ্ছেদ"), [
    { kind: "paragraph", lines: ["প্রথম লাইন", "দ্বিতীয় লাইন"] },
    { kind: "paragraph", lines: ["নতুন অনুচ্ছেদ"] },
  ]);
});

test("bullets separated by blank lines form one list, and an indented wrapped line continues its item", () => {
  const text = "বিশেষভাবে যেসব কাজে সহযোগী প্রয়োজন—\n\n• দেশি-বিদেশি NGO ও বিভিন্ন প্রতিষ্ঠানের\n  সঙ্গে যোগাযোগ\n\n• Fundraising";

  assert.deepEqual(parseTextBlocks(text), [
    { kind: "paragraph", lines: ["বিশেষভাবে যেসব কাজে সহযোগী প্রয়োজন—"] },
    { kind: "list", items: ["দেশি-বিদেশি NGO ও বিভিন্ন প্রতিষ্ঠানের সঙ্গে যোগাযোগ", "Fundraising"] },
  ]);
});

test("a paragraph after a list starts a new block, and a later list stays separate", () => {
  assert.deepEqual(parseTextBlocks("• এক\n• দুই\nশেষ কথা\n\n• তিন"), [
    { kind: "list", items: ["এক", "দুই"] },
    { kind: "paragraph", lines: ["শেষ কথা"] },
    { kind: "list", items: ["তিন"] },
  ]);
});

test("URLs become links without swallowing trailing punctuation, including the Bengali danda", () => {
  assert.deepEqual(splitLinks("গ্রুপ: https://chat.whatsapp.com/JRJpeNjFVzbFeJf1d9luEb।"), [
    { kind: "text", value: "গ্রুপ: " },
    { kind: "link", href: "https://chat.whatsapp.com/JRJpeNjFVzbFeJf1d9luEb", label: "chat.whatsapp.com/JRJpeNjFVzbFeJf1d9luEb" },
    { kind: "text", value: "।" },
  ]);
});

test("only http(s) is ever linked — a javascript: string stays plain text", () => {
  assert.deepEqual(splitLinks("javascript:alert(1) নয়"), [{ kind: "text", value: "javascript:alert(1) নয়" }]);
});

test("long link labels are shortened while the href stays complete", () => {
  const href = "https://example.org/a/very/long/path/that/keeps/going/on-and-on";
  const [part] = splitLinks(href);
  assert.equal(part?.kind === "link" && part.href, href);
  assert.equal(shortenUrl(href).length, 40);
  assert.ok(shortenUrl(href).endsWith("…"));
});

test("excerpt flattens bullets and line breaks into one line", () => {
  assert.equal(excerpt("প্রথম\n\n• দ্বিতীয়\n• তৃতীয়", 100), "প্রথম দ্বিতীয় তৃতীয়");
});
