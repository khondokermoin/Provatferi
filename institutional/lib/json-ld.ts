/**
 * JSON for a <script type="application/ld+json"> element. A plain JSON.stringify is NOT safe there: a string value that
 * contains `</script>` ends the element and whatever follows it is parsed as HTML. Structured data now carries values an
 * admin typed (e-mail, phone, social addresses), so every character that could open or close a tag, an entity or a
 * script-data escape is written as a Unicode escape: still the same JSON to every parser, inert as HTML.
 */

// Built from code points, never written as a literal or an escape inside a regex: U+2028 and U+2029 are line
// terminators in JavaScript source, so a regex literal containing either one does not parse.
const LINE_SEPARATOR = String.fromCharCode(0x2028);
const PARAGRAPH_SEPARATOR = String.fromCharCode(0x2029);

export function safeJsonLd(value: unknown): string {
  return JSON.stringify(value)
    .replace(/</g, "\\u003c")
    .replace(/>/g, "\\u003e")
    .replace(/&/g, "\\u0026")
    .split(LINE_SEPARATOR)
    .join("\\u2028")
    .split(PARAGRAPH_SEPARATOR)
    .join("\\u2029");
}
