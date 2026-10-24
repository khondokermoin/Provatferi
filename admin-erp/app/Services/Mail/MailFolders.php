<?php

namespace App\Services\Mail;

use App\Models\Mailbox;
use App\Services\Mail\Data\MailFolder;
use Illuminate\Support\Facades\Cache;

/**
 * A mailbox's folders and their counts, shared by everyone who uses the mailbox and kept for a short window
 * (config mail_center.cache.folders_seconds): the unread badge, the dashboard and the folder list together cost at most one
 * provider request per mailbox per window. Anything that changes counts (a message opened, moved, flagged) forgets them.
 *
 * It is also what a folder named in a URL is checked against: a folder that is not in this list does not exist.
 */
final class MailFolders
{
    /**
     * Once the provider has failed in a way that is not about one mailbox (no token, rejected token, no answer), later
     * calls in the SAME REQUEST fail at once with the same answer instead of waiting for another time-out per mailbox. The
     * mark lives on the request, so the next request always asks the provider again.
     */
    private const DOWN = 'mail-center.provider-down';

    public function __construct(private readonly MailProvider $provider) {}

    /** @return list<MailFolder> in display order */
    public function all(Mailbox $mailbox): array
    {
        $down = request()->attributes->get(self::DOWN);
        if ($down instanceof MailProviderException) {
            throw $down;
        }
        try {
            $rows = Cache::remember($this->key($mailbox), (int) config('mail_center.cache.folders_seconds', 60), function () use ($mailbox) {
                return array_map(fn (MailFolder $f) => [$f->path, $f->specialUse, $f->total, $f->unread], $this->provider->folders($mailbox));
            });
        } catch (MailProviderException $e) {
            if (in_array($e->kind, [MailProviderException::UNCONFIGURED, MailProviderException::AUTH, MailProviderException::UNAVAILABLE], true)) {
                request()->attributes->set(self::DOWN, $e);
            }

            throw $e;
        }
        $folders = array_map(fn (array $r) => new MailFolder($r[0], $r[1], (int) $r[2], (int) $r[3]), $rows);
        usort($folders, fn (MailFolder $a, MailFolder $b) => strcmp($a->sortKey(), $b->sortKey()));

        return $folders;
    }

    public function forget(Mailbox $mailbox): void
    {
        Cache::forget($this->key($mailbox));
    }

    /** The folder whose path is exactly $path, or null (an unknown folder is never passed on to the provider). */
    public function find(Mailbox $mailbox, ?string $path): ?MailFolder
    {
        if ($path === null || $path === '' || mb_strlen($path) > 191) {
            return null;
        }
        foreach ($this->all($mailbox) as $folder) {
            if ($folder->path === $path) {
                return $folder;
            }
        }

        return null;
    }

    /** @param string $role one of the MailFolder role constants (\Inbox, \Sent, \Archive, \Trash, …) */
    public function byRole(Mailbox $mailbox, string $role): ?MailFolder
    {
        foreach ($this->all($mailbox) as $folder) {
            if ($folder->role() === $role) {
                return $folder;
            }
        }

        return null;
    }

    public function inbox(Mailbox $mailbox): ?MailFolder
    {
        return $this->byRole($mailbox, MailFolder::INBOX);
    }

    private function key(Mailbox $mailbox): string
    {
        return 'mail-center:folders:'.$mailbox->getKey();
    }
}
