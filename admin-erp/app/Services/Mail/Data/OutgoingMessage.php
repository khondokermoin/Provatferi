<?php

namespace App\Services\Mail\Data;

/**
 * A message the Mail Center hands the provider to send. It has NO sender field on purpose: the provider sends from the
 * mailbox the call is made for, and the only thing the ERP chooses is the display name, from the registry. A reply or a
 * forward names its source message ({uid, folder} of the same mailbox), so the provider sets the threading headers and
 * flags the source answered or forwarded.
 */
final readonly class OutgoingMessage
{
    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     * @param  list<OutgoingAttachment>  $attachments
     * @param  array{uid: int, folder: string}|null  $inReplyTo
     * @param  array{uid: int, folder: string}|null  $forwardOf
     */
    public function __construct(
        public string $displayName,
        public array $to,
        public array $cc,
        public array $bcc,
        public string $subject,
        public string $text,
        public string $html,
        public array $attachments = [],
        public ?array $inReplyTo = null,
        public ?array $forwardOf = null,
    ) {}

    public function recipientCount(): int
    {
        return count($this->to) + count($this->cc) + count($this->bcc);
    }
}
