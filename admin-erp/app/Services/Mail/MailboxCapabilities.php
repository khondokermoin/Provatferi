<?php

namespace App\Services\Mail;

use App\Models\MailboxGrant;

/**
 * What one account may do with one mailbox, after every grant that applies to it is combined (docs/MAIL_CENTER.md,
 * "Permission model"). Built by MailboxAccess; the controllers ask it, nothing else.
 */
final readonly class MailboxCapabilities
{
    public function __construct(
        public bool $read = false,
        public bool $send = false,
        public bool $reply = false,
        public bool $forward = false,
        public bool $manageState = false,
        /** True when the access comes from mail.manage_all (or Super Admin), not from a grant. */
        public bool $viaManageAll = false,
    ) {}

    public static function all(): self
    {
        return new self(true, true, true, true, true, true);
    }

    public static function none(): self
    {
        return new self();
    }

    /** The union of several grants. Replying, forwarding and message state are only ever effective together with reading. */
    public static function fromGrants(iterable $grants): self
    {
        $read = $send = $reply = $forward = $state = false;
        foreach ($grants as $grant) {
            /** @var MailboxGrant $grant */
            $read = $read || $grant->can_read;
            $send = $send || $grant->can_send;
            $reply = $reply || $grant->can_reply;
            $forward = $forward || $grant->can_forward;
            $state = $state || $grant->can_manage_state;
        }

        return new self($read, $send, $reply && $read, $forward && $read, $state && $read);
    }

    public function any(): bool
    {
        return $this->read || $this->send || $this->reply || $this->forward || $this->manageState;
    }

    /** @param string $capability one of MailboxGrant::CAPABILITIES */
    public function allows(string $capability): bool
    {
        return match ($capability) {
            'read' => $this->read,
            'send' => $this->send,
            'reply' => $this->reply,
            'forward' => $this->forward,
            'manage_state' => $this->manageState,
            default => false,
        };
    }

    /** @return array<string, bool> */
    public function toArray(): array
    {
        return ['read' => $this->read, 'send' => $this->send, 'reply' => $this->reply, 'forward' => $this->forward, 'manage_state' => $this->manageState];
    }
}
