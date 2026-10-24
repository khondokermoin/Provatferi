<?php

namespace App\Services\Mail\Data;

use Carbon\CarbonImmutable;

/**
 * A message's envelope as the provider lists it: who, when, what, its flags and its attachments — never its body. Opening
 * the body is a separate call (MailProvider::messageText), because the provider marks a message read when it is opened.
 */
final readonly class MailMessage
{
    /**
     * @param  list<MailAddress>  $to
     * @param  list<MailAddress>  $cc
     * @param  list<MailAddress>  $bcc
     * @param  list<string>  $flags
     * @param  list<MailAttachment>  $attachments
     */
    public function __construct(
        public int $uid,
        public string $folder,
        public ?CarbonImmutable $date,
        public string $subject,
        public ?MailAddress $from,
        public array $to,
        public array $cc,
        public array $bcc,
        public array $flags,
        public bool $unseen,
        public int $size,
        public ?string $messageId,
        public array $attachments,
    ) {}

    public function isFlagged(): bool
    {
        return $this->hasFlag('\\Flagged');
    }

    public function isAnswered(): bool
    {
        return $this->hasFlag('\\Answered');
    }

    public function isForwarded(): bool
    {
        return $this->hasFlag('$Forwarded');
    }

    public function hasFlag(string $flag): bool
    {
        foreach ($this->flags as $own) {
            if (strcasecmp($own, $flag) === 0) {
                return true;
            }
        }

        return false;
    }

    /** Attachments a person would download (inline pictures of an HTML body are shown in it instead). */
    public function downloadableAttachments(): array
    {
        return array_values(array_filter($this->attachments, fn (MailAttachment $a) => ! $a->inline || $a->contentId === null));
    }

    public function attachment(int $index): ?MailAttachment
    {
        foreach ($this->attachments as $attachment) {
            if ($attachment->index === $index) {
                return $attachment;
            }
        }

        return null;
    }
}
