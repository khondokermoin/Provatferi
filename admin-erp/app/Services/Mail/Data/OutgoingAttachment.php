<?php

namespace App\Services\Mail\Data;

/** A file attached to an outgoing message: raw bytes (the provider client encodes them). */
final readonly class OutgoingAttachment
{
    public function __construct(
        public string $filename,
        public string $contentType,
        public string $content,
    ) {}

    public function size(): int
    {
        return strlen($this->content);
    }
}
