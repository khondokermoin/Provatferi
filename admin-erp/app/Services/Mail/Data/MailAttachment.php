<?php

namespace App\Services\Mail\Data;

/**
 * An attachment of a message, as listed by the provider. `index` is its position in that list: the only thing a URL ever
 * carries. The provider's own `id` stays on the server, so a request can only ever pick from the attachments of a message
 * the person is allowed to read.
 */
final readonly class MailAttachment
{
    public function __construct(
        public int $index,
        public string $id,
        public string $filename,
        public string $contentType,
        public int $size,
        public bool $inline,
        public ?string $contentId,
    ) {}
}
