/**
 * Notice bodies and recruitment descriptions are stored as plain text, never
 * HTML — nothing an admin types is ever injected as markup. This turns that
 * text into structure React renders as ordinary elements:
 *
 * - a blank line separates paragraphs; single line breaks inside one are kept
 * - lines starting with "•", "-" or "–" become list items, and an indented
 *   line right after a bullet continues that item (hard-wrapped bullets)
 * - http(s) URLs become links with a readable, shortened label
 */

export type TextBlock =
  | { kind: "paragraph"; lines: string[] }
  | { kind: "list"; items: string[] };

export type TextPart =
  | { kind: "text"; value: string }
  | { kind: "link"; href: string; label: string };

const BULLET = /^\s*[•\-–]\s+/;
const URL_PATTERN = /https?:\/\/[^\s<>"'()]+/g;
const TRAILING_PUNCTUATION = /[.,;:!?।]+$/;
const LINK_LABEL_MAX = 40;

export function parseTextBlocks(text: string): TextBlock[] {
  const blocks: TextBlock[] = [];

  for (const chunk of text.replace(/\r\n?/g, "\n").split(/\n[ \t]*\n/)) {
    let paragraph: string[] = [];
    let list: string[] | null = null;

    const flushParagraph = () => {
      if (paragraph.length > 0) blocks.push({ kind: "paragraph", lines: paragraph });
      paragraph = [];
    };
    const flushList = () => {
      if (list && list.length > 0) {
        // Bullets separated only by blank lines are still one list.
        const previous = blocks[blocks.length - 1];
        if (previous?.kind === "list") previous.items.push(...list);
        else blocks.push({ kind: "list", items: list });
      }
      list = null;
    };

    for (const raw of chunk.split("\n")) {
      const line = raw.trim();
      if (line === "") continue;

      if (BULLET.test(raw)) {
        flushParagraph();
        list ??= [];
        list.push(line.replace(BULLET, ""));
      } else if (list && list.length > 0 && /^\s/.test(raw)) {
        list[list.length - 1] += ` ${line}`;
      } else {
        flushList();
        paragraph.push(line);
      }
    }

    flushParagraph();
    flushList();
  }

  return blocks;
}

export function shortenUrl(url: string): string {
  const label = url.replace(/^https?:\/\//, "").replace(/\/$/, "");
  return label.length > LINK_LABEL_MAX ? `${label.slice(0, LINK_LABEL_MAX - 1)}…` : label;
}

export function splitLinks(text: string): TextPart[] {
  const parts: TextPart[] = [];
  let cursor = 0;

  for (const match of text.matchAll(URL_PATTERN)) {
    const start = match.index ?? 0;
    const href = match[0].replace(TRAILING_PUNCTUATION, "");
    if (start > cursor) parts.push({ kind: "text", value: text.slice(cursor, start) });
    parts.push({ kind: "link", href, label: shortenUrl(href) });
    cursor = start + href.length;
  }

  if (cursor < text.length) parts.push({ kind: "text", value: text.slice(cursor) });
  return parts;
}

/** A plain one-line description for metadata when a notice has no summary. */
export function excerpt(text: string, max = 160): string {
  const flat = text.replace(BULLET, "").replace(/\s*\n\s*[•\-–]?\s*/g, " ").replace(/\s+/g, " ").trim();
  return flat.length > max ? `${flat.slice(0, max - 1).trimEnd()}…` : flat;
}
