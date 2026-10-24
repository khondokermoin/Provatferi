<?php

namespace App\Services\Mail;

use App\Models\Mailbox;
use App\Services\Mail\Data\MailAddress;
use App\Services\Mail\Data\MailAttachment;
use App\Services\Mail\Data\MailFolder;
use App\Services\Mail\Data\MailMessage;
use App\Services\Mail\Data\MailMessagePage;
use App\Services\Mail\Data\MailMessageText;
use App\Services\Mail\Data\MailSearch;
use App\Services\Mail\Data\OutgoingAttachment;
use App\Services\Mail\Data\OutgoingMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The Hostinger Mail API behind the MailProvider contract, through HostingerMailApiClient (see its docblock for why not the
 * SDK). Request bodies use the API reference's own field names; responses are decoded defensively — a field the provider
 * leaves out or sends as null becomes an empty value, never a broken page.
 *
 * The token's mailbox list (GET /v1/me) is cached for five minutes under a keyed digest of the token, so a page costs one
 * provider request rather than two, and a changed token is picked up at once.
 */
final class HostingerMailProvider implements MailProvider
{
    private const MAILBOXES_CACHE_SECONDS = 300;

    /** Attachments larger than this are not fetched through the ERP (the provider's limit for a whole message is lower). */
    private const MAX_ATTACHMENT_BYTES = 30 * 1024 * 1024;

    public function __construct(private readonly HostingerMailApiClient $api) {}

    public function isConfigured(): bool
    {
        return filled(config('services.hostinger_mail.token'));
    }

    public function reachableAddresses(): array
    {
        $addresses = array_keys($this->mailboxMap());
        sort($addresses);

        return $addresses;
    }

    public function mailboxId(string $address): ?string
    {
        return $this->mailboxMap()[strtolower($address)] ?? null;
    }

    public function folders(Mailbox $mailbox): array
    {
        $rows = $this->api->get(HostingerMailApiClient::path('mailboxes', $this->id($mailbox), 'folders'), ['page' => 1, 'perPage' => 100])['data'] ?? [];

        return array_values(array_map(fn (array $f) => new MailFolder(
            (string) ($f['path'] ?? ''),
            is_string($f['specialUse'] ?? null) && $f['specialUse'] !== '' ? $f['specialUse'] : null,
            (int) ($f['messageCount'] ?? 0),
            (int) ($f['unreadCount'] ?? 0),
        ), array_filter((array) $rows, 'is_array')));
    }

    public function messages(Mailbox $mailbox, string $folder, int $page, int $perPage): MailMessagePage
    {
        $response = $this->api->get(HostingerMailApiClient::path('mailboxes', $this->id($mailbox), 'folders', $folder, 'messages'), ['page' => $page, 'perPage' => $perPage, 'sort' => '-uid']);

        return $this->page($response, $folder, $page, $perPage);
    }

    public function search(Mailbox $mailbox, string $folder, MailSearch $search, int $page, int $perPage): MailMessagePage
    {
        // One typed criterion, as a JSON value: never query syntax.
        $response = $this->api->post(HostingerMailApiClient::path('mailboxes', $this->id($mailbox), 'folders', $folder, 'messages', 'search'), [$search->field => $search->value], ['page' => $page, 'perPage' => $perPage, 'sort' => '-uid']);

        return $this->page($response, $folder, $page, $perPage);
    }

    public function message(Mailbox $mailbox, string $folder, int $uid): MailMessage
    {
        $data = $this->api->get(HostingerMailApiClient::path('mailboxes', $this->id($mailbox), 'folders', $folder, 'messages', (string) $uid))['data'] ?? null;
        if (! is_array($data)) {
            throw MailProviderException::of(MailProviderException::NOT_FOUND, 404);
        }

        return $this->mapMessage($data, $folder);
    }

    public function messageText(Mailbox $mailbox, string $folder, int $uid): MailMessageText
    {
        $data = $this->api->get(HostingerMailApiClient::path('mailboxes', $this->id($mailbox), 'folders', $folder, 'messages', (string) $uid, 'text'))['data'] ?? [];

        return new MailMessageText(is_string($data['text'] ?? null) ? $data['text'] : '', is_string($data['html'] ?? null) ? $data['html'] : '');
    }

    public function attachmentContent(Mailbox $mailbox, string $folder, int $uid, string $attachmentId): string
    {
        return $this->api->download(HostingerMailApiClient::path('mailboxes', $this->id($mailbox), 'folders', $folder, 'messages', (string) $uid, 'attachments', $attachmentId), self::MAX_ATTACHMENT_BYTES);
    }

    public function setFlags(Mailbox $mailbox, string $folder, int $uid, array $add, array $remove): void
    {
        $body = array_filter(['addFlags' => array_values($add), 'removeFlags' => array_values($remove)], fn ($v) => $v !== []);
        $this->api->patch(HostingerMailApiClient::path('mailboxes', $this->id($mailbox), 'folders', $folder, 'messages', (string) $uid), $body);
    }

    public function move(Mailbox $mailbox, string $folder, int $uid, string $targetFolder): void
    {
        $this->api->post(HostingerMailApiClient::path('mailboxes', $this->id($mailbox), 'folders', $folder, 'messages', (string) $uid, 'move'), ['targetFolder' => $targetFolder]);
    }

    public function createFolder(Mailbox $mailbox, string $name): void
    {
        $this->api->post(HostingerMailApiClient::path('mailboxes', $this->id($mailbox), 'folders'), ['name' => $name]);
    }

    public function send(Mailbox $mailbox, OutgoingMessage $message): void
    {
        $body = array_filter([
            'to' => $message->to,
            'cc' => $message->cc,
            'bcc' => $message->bcc,
            'displayName' => $message->displayName,
            'subject' => $message->subject,
            'text' => $message->text,
            'html' => $message->html,
            'attachments' => array_map(fn (OutgoingAttachment $a) => ['filename' => $a->filename, 'content' => base64_encode($a->content), 'contentType' => $a->contentType], $message->attachments),
            'inReplyTo' => $message->inReplyTo,
            'forwardOf' => $message->forwardOf,
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');
        $this->api->post(HostingerMailApiClient::path('mailboxes', $this->id($mailbox), 'send'), $body);
    }

    /** @return array<string, string> lower-case address => provider id */
    private function mailboxMap(): array
    {
        if (! $this->isConfigured()) {
            throw MailProviderException::of(MailProviderException::UNCONFIGURED);
        }
        $key = 'mail-center:provider-mailboxes:'.substr(hash_hmac('sha256', (string) config('services.hostinger_mail.token'), (string) config('app.key')), 0, 16);

        return Cache::remember($key, self::MAILBOXES_CACHE_SECONDS, function () {
            $map = [];
            foreach ((array) ($this->api->get('/api/v1/me')['data']['mailboxes'] ?? []) as $row) {
                if (is_array($row) && is_string($row['address'] ?? null) && is_string($row['resourceId'] ?? null)) {
                    $map[strtolower($row['address'])] = $row['resourceId'];
                }
            }

            return $map;
        });
    }

    /** The provider's id of the registry row's mailbox, once the token is known to reach it. */
    private function id(Mailbox $mailbox): string
    {
        return $this->mailboxMap()[strtolower((string) $mailbox->address)] ?? throw MailProviderException::of(MailProviderException::NOT_IN_SCOPE);
    }

    /** @param array<string, mixed> $response */
    private function page(array $response, string $folder, int $page, int $perPage): MailMessagePage
    {
        $messages = array_values(array_map(fn (array $m) => $this->mapMessage($m, $folder), array_filter((array) ($response['data'] ?? []), 'is_array')));
        $p = is_array($response['pagination'] ?? null) ? $response['pagination'] : [];

        return new MailMessagePage($messages, (int) ($p['page'] ?? $page), (int) ($p['perPage'] ?? $perPage), (int) ($p['total'] ?? count($messages)), max(1, (int) ($p['totalPages'] ?? 1)));
    }

    /** @param array<string, mixed> $m */
    private function mapMessage(array $m, string $folder): MailMessage
    {
        $attachments = [];
        foreach (array_values(array_filter((array) ($m['attachments'] ?? []), 'is_array')) as $index => $a) {
            $attachments[] = new MailAttachment(
                $index,
                (string) ($a['id'] ?? ''),
                (string) ($a['filename'] ?? ''),
                strtolower((string) ($a['contentType'] ?? 'application/octet-stream')),
                (int) ($a['sizeBytes'] ?? 0),
                (bool) ($a['inline'] ?? false),
                is_string($a['contentId'] ?? null) && $a['contentId'] !== '' ? $a['contentId'] : null,
            );
        }

        return new MailMessage(
            (int) ($m['uid'] ?? 0),
            is_string($m['path'] ?? null) && $m['path'] !== '' ? $m['path'] : $folder,
            $this->date($m['date'] ?? null),
            is_string($m['subject'] ?? null) ? $m['subject'] : '',
            $this->address($m['from'] ?? null),
            $this->addresses($m['to'] ?? []),
            $this->addresses($m['cc'] ?? []),
            $this->addresses($m['bcc'] ?? []),
            array_values(array_filter((array) ($m['flags'] ?? []), 'is_string')),
            (bool) ($m['unseen'] ?? false),
            (int) ($m['size'] ?? 0),
            is_string($m['messageId'] ?? null) && $m['messageId'] !== '' ? $m['messageId'] : null,
            $attachments,
        );
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<MailAddress> */
    private function addresses(mixed $list): array
    {
        return array_values(array_filter(array_map(fn ($a) => $this->address($a), is_array($list) ? $list : [])));
    }

    private function address(mixed $a): ?MailAddress
    {
        if (! is_array($a) || ! is_string($a['address'] ?? null) || trim($a['address']) === '') {
            return null;
        }
        $name = is_string($a['name'] ?? null) ? trim($a['name']) : '';

        return new MailAddress(strtolower(trim($a['address'])), $name !== '' ? $name : null);
    }
}
