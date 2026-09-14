import { Fragment } from "react";
import { parseTextBlocks, splitLinks } from "@/lib/text-blocks";

function LinkedText({ text }: { text: string }) {
  return (
    <>
      {splitLinks(text).map((part, index) =>
        part.kind === "link" ? (
          <a key={index} href={part.href} target="_blank" rel="noopener noreferrer">
            {part.label}
          </a>
        ) : (
          <Fragment key={index}>{part.value}</Fragment>
        ),
      )}
    </>
  );
}

/** Plain stored text rendered as paragraphs, lists and links — never as HTML. */
export default function TextBlocks({ text, className = "" }: { text: string; className?: string }) {
  return (
    <div className={`text-blocks ${className}`.trim()}>
      {parseTextBlocks(text).map((block, index) =>
        block.kind === "list" ? (
          <ul key={index}>
            {block.items.map((item, itemIndex) => (
              <li key={itemIndex}>
                <LinkedText text={item} />
              </li>
            ))}
          </ul>
        ) : (
          <p key={index}>
            {block.lines.map((line, lineIndex) => (
              <Fragment key={lineIndex}>
                {lineIndex > 0 && <br />}
                <LinkedText text={line} />
              </Fragment>
            ))}
          </p>
        ),
      )}
    </div>
  );
}
