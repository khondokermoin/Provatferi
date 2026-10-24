<?php

namespace App\Services\Mail\Data;

/** One page of a folder or a search, paged by the provider. */
final readonly class MailMessagePage
{
    /** @param list<MailMessage> $messages */
    public function __construct(
        public array $messages,
        public int $page,
        public int $perPage,
        public int $total,
        public int $totalPages,
    ) {}

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->totalPages;
    }
}
