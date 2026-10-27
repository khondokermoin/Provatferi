<?php

namespace Tests\Support;

use App\Models\Mailbox;
use App\Services\Mail\Data\MailAddress;
use App\Services\Mail\Data\MailAttachment;
use App\Services\Mail\Data\MailFolder;
use App\Services\Mail\Data\MailMessage;
use App\Services\Mail\Data\MailMessagePage;
use App\Services\Mail\Data\MailMessageText;
use App\Services\Mail\Data\MailSearch;
use App\Services\Mail\Data\OutgoingMessage;
use App\Services\Mail\MailProvider;
use App\Services\Mail\MailProviderException;
use Carbon\CarbonImmutable;

/**
 * An in-memory mail provider for the Mail Center tests: mailboxes, folders, messages and attachments that behave like the
 * Hostinger Mail API (opening a message's text marks it \Seen; a send saves a copy in Sent and flags the source of a reply
 * or forward). It records every call with the ADDRESS it was made for, so a test can prove which mailbox was — and was
 * not — touched.
 */
final class FakeMailProvider implements MailProvider
{
    public bool $configured = true;

    /** @var array<string, array<string, array{special: ?string, messages: array<int, array{m: array, text: string, html: string}>}>> */
    public array $boxes = [];

    /** @var array<string, string> attachment id => bytes */
    public array $attachmentBytes = [];

    /** @var list<array{address: string, message: OutgoingMessage}> */
    public array $sent = [];

    /** @var list<string> "method address [folder] [uid]" */
    public array $calls = [];

    /** @var array<string, MailProviderException> method => what it throws (every time) */
    public array $failOn = [];

    public function addMailbox(string $address, ?array $folders = null): void
    {
        $folders ??= ['INBOX' => MailFolder::INBOX, 'INBOX.Sent' => MailFolder::SENT, 'INBOX.Drafts' => MailFolder::DRAFTS, 'INBOX.Junk' => MailFolder::JUNK, 'INBOX.Trash' => MailFolder::TRASH, 'INBOX.Archive' => MailFolder::ARCHIVE];
        foreach ($folders as $path => $special) {
            $this->boxes[strtolower($address)][$path] = ['special' => $special, 'messages' => []];
        }
    }

    /**
     * @param  array<string, mixed>  $m  overrides: subject, from [address, name], to, cc, flags, unseen, date
     * @param  list<array{filename: string, contentType: string, content: string, inline?: bool, contentId?: string}>  $attachments
     */
    public function addMessage(string $address, string $folder, int $uid, array $m = [], string $text = 'Hello from a test.', string $html = '', array $attachments = []): void
    {
        $list = [];
        foreach ($attachments as $i => $a) {
            $id = "att-{$uid}-{$i}-".substr(md5($address.$folder), 0, 6);
            $this->attachmentBytes[$id] = $a['content'];
            $list[] = ['id' => $id, 'filename' => $a['filename'], 'contentType' => $a['contentType'], 'size' => strlen($a['content']), 'inline' => $a['inline'] ?? false, 'contentId' => $a['contentId'] ?? null];
        }
        $this->boxes[strtolower($address)][$folder]['messages'][$uid] = [
            'm' => $m + ['subject' => "Message {$uid}", 'from' => ['sender@example.org', 'A Sender'], 'to' => [[$address, null]], 'cc' => [], 'flags' => [], 'unseen' => true, 'date' => '2026-10-20 08:00:00', 'attachments' => $list],
            'text' => $text,
            'html' => $html,
        ];
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function reachableAddresses(): array
    {
        $this->guard('reachableAddresses', '');

        return array_keys($this->boxes);
    }

    public function mailboxId(string $address): ?string
    {
        $this->guard('mailboxId', strtolower($address));

        return isset($this->boxes[strtolower($address)]) ? 'res-'.md5(strtolower($address)) : null;
    }

    public function folders(Mailbox $mailbox): array
    {
        $box = $this->box($mailbox, 'folders');
        $out = [];
        foreach ($box as $path => $folder) {
            $unread = count(array_filter($folder['messages'], fn ($row) => in_array('\\Seen', $row['m']['flags'], true) === false && $row['m']['unseen']));
            $out[] = new MailFolder($path, $folder['special'], count($folder['messages']), $unread);
        }

        return $out;
    }

    public function messages(Mailbox $mailbox, string $folder, int $page, int $perPage): MailMessagePage
    {
        $rows = $this->folderRows($mailbox, $folder, "messages {$folder}");
        krsort($rows);

        return $this->page($rows, $folder, $page, $perPage);
    }

    public function search(Mailbox $mailbox, string $folder, MailSearch $search, int $page, int $perPage): MailMessagePage
    {
        $rows = array_filter($this->folderRows($mailbox, $folder, "search {$folder} {$search->field}={$search->value}"), function ($row) use ($search) {
            $haystack = match ($search->field) {
                MailSearch::IN_FROM => implode(' ', (array) $row['m']['from']),
                MailSearch::IN_SUBJECT => $row['m']['subject'],
                default => $row['m']['subject'].' '.$row['text'].' '.implode(' ', (array) $row['m']['from']),
            };

            return stripos($haystack, $search->value) !== false;
        });
        krsort($rows);

        return $this->page($rows, $folder, $page, $perPage);
    }

    public function message(Mailbox $mailbox, string $folder, int $uid): MailMessage
    {
        return $this->map($this->row($mailbox, $folder, $uid, "message {$folder} {$uid}"), $folder, $uid);
    }

    public function messageText(Mailbox $mailbox, string $folder, int $uid): MailMessageText
    {
        $row = $this->row($mailbox, $folder, $uid, "text {$folder} {$uid}");
        // As the real API: fetching the text marks the message read.
        $this->boxes[strtolower($mailbox->address)][$folder]['messages'][$uid]['m']['unseen'] = false;
        $this->boxes[strtolower($mailbox->address)][$folder]['messages'][$uid]['m']['flags'] = array_values(array_unique([...$row['m']['flags'], '\\Seen']));

        return new MailMessageText($row['text'], $row['html']);
    }

    /**
     * The raw source, assembled from what the message was given. Only the envelope headers matter to the Mail Center,
     * so those are what this reproduces: a message seeded with `delivered_to` presents it exactly as a server would.
     */
    public function messageSource(Mailbox $mailbox, string $folder, int $uid): string
    {
        $row = $this->row($mailbox, $folder, $uid, "source {$folder} {$uid}");
        $m = $row['m'];
        $head = [];
        foreach ((array) ($m['delivered_to'] ?? []) as $address) {
            $head[] = 'Delivered-To: '.$address;
        }
        $head[] = 'Subject: '.($m['subject'] ?? '');
        $head[] = 'From: '.($m['from'][0] ?? 'sender@example.org');
        foreach ((array) ($m['to'] ?? []) as $a) {
            $head[] = 'To: '.(is_array($a) ? ($a[0] ?? '') : $a);
        }

        return implode("\r\n", $head)."\r\n\r\n".($row['text'] ?? '');
    }

    public function attachmentContent(Mailbox $mailbox, string $folder, int $uid, string $attachmentId): string
    {
        $row = $this->row($mailbox, $folder, $uid, "attachment {$folder} {$uid} {$attachmentId}");
        foreach ($row['m']['attachments'] as $a) {
            if ($a['id'] === $attachmentId) {
                return $this->attachmentBytes[$attachmentId];
            }
        }

        throw MailProviderException::of(MailProviderException::NOT_FOUND, 404);
    }

    public function setFlags(Mailbox $mailbox, string $folder, int $uid, array $add, array $remove): void
    {
        $row = $this->row($mailbox, $folder, $uid, "flags {$folder} {$uid} +".implode(',', $add).' -'.implode(',', $remove));
        $flags = array_values(array_diff(array_unique([...$row['m']['flags'], ...$add]), $remove));
        $this->boxes[strtolower($mailbox->address)][$folder]['messages'][$uid]['m']['flags'] = $flags;
        $this->boxes[strtolower($mailbox->address)][$folder]['messages'][$uid]['m']['unseen'] = ! in_array('\\Seen', $flags, true);
    }

    public function move(Mailbox $mailbox, string $folder, int $uid, string $targetFolder): void
    {
        $row = $this->row($mailbox, $folder, $uid, "move {$folder} {$uid} {$targetFolder}");
        $address = strtolower($mailbox->address);
        if (! isset($this->boxes[$address][$targetFolder])) {
            throw MailProviderException::of(MailProviderException::NOT_FOUND, 404);
        }
        unset($this->boxes[$address][$folder]['messages'][$uid]);
        $newUid = (max(array_keys($this->boxes[$address][$targetFolder]['messages']) ?: [0]) + 1);
        $this->boxes[$address][$targetFolder]['messages'][$newUid] = $row;
    }

    public function createFolder(Mailbox $mailbox, string $name): void
    {
        $this->box($mailbox, "createFolder {$name}");
        $this->boxes[strtolower($mailbox->address)]['INBOX.'.$name] = ['special' => null, 'messages' => []];
    }

    public function send(Mailbox $mailbox, OutgoingMessage $message): void
    {
        $this->box($mailbox, 'send');
        $this->sent[] = ['address' => strtolower($mailbox->address), 'message' => $message];
        $source = $message->inReplyTo ?? $message->forwardOf;
        if ($source !== null) {
            $flag = $message->inReplyTo !== null ? '\\Answered' : '$Forwarded';
            $path = &$this->boxes[strtolower($mailbox->address)][$source['folder']]['messages'][$source['uid']]['m']['flags'];
            $path = array_values(array_unique([...(array) $path, $flag]));
            unset($path);
        }
    }

    /** Every recorded call made for $address. @return list<string> */
    public function callsFor(string $address): array
    {
        return array_values(array_filter($this->calls, fn ($c) => str_contains($c, ' '.strtolower($address))));
    }

    private function guard(string $method, string $what): void
    {
        $this->calls[] = $method.' '.$what;
        if (isset($this->failOn[$method])) {
            throw $this->failOn[$method];
        }
        if (! $this->configured) {
            throw MailProviderException::of(MailProviderException::UNCONFIGURED);
        }
    }

    private function box(Mailbox $mailbox, string $call): array
    {
        $method = (string) strtok($call, ' ');
        $this->guard($method, rtrim(strtolower($mailbox->address).' '.substr($call, strlen($method) + 1)));
        $address = strtolower($mailbox->address);
        if (! isset($this->boxes[$address])) {
            throw MailProviderException::of(MailProviderException::NOT_IN_SCOPE);
        }

        return $this->boxes[$address];
    }

    private function folderRows(Mailbox $mailbox, string $folder, string $call): array
    {
        $box = $this->box($mailbox, $call);
        if (! isset($box[$folder])) {
            throw MailProviderException::of(MailProviderException::NOT_FOUND, 404);
        }

        return $box[$folder]['messages'];
    }

    private function row(Mailbox $mailbox, string $folder, int $uid, string $call): array
    {
        $rows = $this->folderRows($mailbox, $folder, $call);
        if (! isset($rows[$uid])) {
            throw MailProviderException::of(MailProviderException::NOT_FOUND, 404);
        }

        return $rows[$uid];
    }

    private function page(array $rows, string $folder, int $page, int $perPage): MailMessagePage
    {
        $total = count($rows);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage, true);

        return new MailMessagePage(array_values(array_map(fn ($uid) => $this->map($rows[$uid], $folder, $uid), array_keys($slice))), $page, $perPage, $total, max(1, (int) ceil($total / $perPage)));
    }

    private function map(array $row, string $folder, int $uid): MailMessage
    {
        $m = $row['m'];
        $attachments = [];
        foreach ($m['attachments'] as $i => $a) {
            $attachments[] = new MailAttachment($i, $a['id'], $a['filename'], $a['contentType'], $a['size'], (bool) $a['inline'], $a['contentId']);
        }

        return new MailMessage(
            $uid, $folder, CarbonImmutable::parse($m['date'], 'UTC'), (string) $m['subject'],
            $m['from'] ? new MailAddress($m['from'][0], $m['from'][1] ?? null) : null,
            array_map(fn ($a) => new MailAddress($a[0], $a[1] ?? null), $m['to']),
            array_map(fn ($a) => new MailAddress($a[0], $a[1] ?? null), $m['cc']),
            [], $m['flags'], (bool) $m['unseen'], 1234, '<'.$uid.'@example.org>', $attachments,
        );
    }
}
