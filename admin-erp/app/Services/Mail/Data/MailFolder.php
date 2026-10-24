<?php

namespace App\Services\Mail\Data;

/**
 * One folder of a mailbox. `specialUse` is the RFC 6154 role the server gives it (\Inbox, \Sent, \Drafts, \Junk, \Trash,
 * \Archive), or null for a folder someone made.
 */
final readonly class MailFolder
{
    public const INBOX = '\\Inbox';

    public const SENT = '\\Sent';

    public const DRAFTS = '\\Drafts';

    public const JUNK = '\\Junk';

    public const TRASH = '\\Trash';

    public const ARCHIVE = '\\Archive';

    /** The order folders are listed in: the server's own roles first, then everything else by name. */
    private const ORDER = [self::INBOX, self::DRAFTS, self::SENT, self::ARCHIVE, self::JUNK, self::TRASH];

    public function __construct(
        public string $path,
        public ?string $specialUse,
        public int $total,
        public int $unread,
    ) {}

    /** The last segment of the path ("INBOX.Sent" → "Sent"). */
    public function name(): string
    {
        $parts = preg_split('/[.\/]/', $this->path) ?: [$this->path];

        return (string) end($parts);
    }

    public function isInbox(): bool
    {
        return $this->specialUse === self::INBOX || strcasecmp($this->path, 'INBOX') === 0;
    }

    /** The role this folder plays: one of the RFC 6154 roles, recognised by flag or (for Archive) by name. */
    public function role(): ?string
    {
        if ($this->isInbox()) {
            return self::INBOX;
        }
        if ($this->specialUse !== null && in_array($this->specialUse, self::ORDER, true)) {
            return $this->specialUse;
        }

        return strcasecmp($this->name(), 'Archive') === 0 ? self::ARCHIVE : null;
    }

    public function sortKey(): string
    {
        $position = array_search($this->role(), self::ORDER, true);

        return sprintf('%02d-%s', $position === false ? 99 : $position, mb_strtolower($this->path));
    }
}
