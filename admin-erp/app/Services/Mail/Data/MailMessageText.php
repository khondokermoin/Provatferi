<?php

namespace App\Services\Mail\Data;

/**
 * A message's body as the provider rendered it. `html` is UNTRUSTED: it reaches a page only through
 * App\Services\Mail\MailBodyRenderer (an allow-list sanitizer, then a sandboxed frame), never directly.
 */
final readonly class MailMessageText
{
    public function __construct(
        public string $text,
        public string $html,
    ) {}
}
