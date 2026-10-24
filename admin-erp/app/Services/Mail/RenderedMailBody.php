<?php

namespace App\Services\Mail;

/**
 * A message body ready for the page. Exactly one of the two is set:
 *   - `frameDocument`: a complete HTML document for a sandboxed iframe (srcdoc) — the sanitized HTML of an HTML message;
 *   - `textHtml`: the plain-text body, every character escaped, with only the ERP's own links added — safe to place in the
 *     page as it is.
 */
final readonly class RenderedMailBody
{
    public function __construct(
        public ?string $frameDocument,
        public ?string $textHtml,
        /** Remote images the message refers to. */
        public int $remoteImages = 0,
        /** Of those, how many were held back (remote images are shown only on request). */
        public int $blockedImages = 0,
        /** The HTML part was too large to render, so the text part is shown instead. */
        public bool $htmlTooLarge = false,
    ) {}

    public function isHtml(): bool
    {
        return $this->frameDocument !== null;
    }
}
