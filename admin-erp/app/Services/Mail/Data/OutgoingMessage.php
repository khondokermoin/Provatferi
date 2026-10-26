<?php

namespace App\Services\Mail\Data;

/**
 * A message the Mail Center hands a transport to send. It carries NO sender address on purpose: which institutional
 * identity it goes out as is decided by the server from the registry (App\Services\Mail\MailSender), never by a field a
 * request could set. The display name that goes with that identity travels here.
 *
 * Two transports read this object, because the provider supports two different things:
 *
 *   the Mail API   sends as the mailbox itself. A reply or forward names its source message ({uid, folder} of the same
 *                  mailbox) in `inReplyTo` / `forwardOf`, and the provider writes the threading headers and flags the
 *                  source answered or forwarded.
 *   SMTP           sends as any identity of that mailbox, which the API cannot do. It has no notion of the source
 *                  message, so threading is carried here as the original's own RFC identifiers — `inReplyToMessageId`
 *                  and `references`, taken from the message being answered, never assembled by hand.
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
     * @param  string|null  $inReplyToMessageId  the answered/forwarded message's own Message-ID (SMTP threading)
     * @param  list<string>  $references  its References chain, oldest first (SMTP threading)
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
        public ?string $inReplyToMessageId = null,
        public array $references = [],
    ) {}

    public function recipientCount(): int
    {
        return count($this->to) + count($this->cc) + count($this->bcc);
    }
}
