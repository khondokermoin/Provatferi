<?php

namespace App\Services;

use Hostinger\Api\AccountApi;
use Hostinger\Api\FoldersApi;
use Hostinger\Api\MessagesApi;
use Hostinger\Api\SendApi;
use Hostinger\Api\WebhooksApi;
use Hostinger\ApiException;
use Hostinger\Configuration;
use Hostinger\Model\V1FolderMessagesFlagsRequest;
use Hostinger\Model\V1FolderMessagesMoveRequest;
use Hostinger\Model\V1SendMessageRef;
use Hostinger\Model\V1SendRequest;
use Hostinger\Model\V1WebhooksCreateRequest;
use RuntimeException;

/**
 * Thin wrapper around the official Hostinger Mail API SDK.
 *
 * This is the ONE place a mailbox address is resolved to the Mail API's own
 * `resourceId` — every controller/job works with the human address
 * ("info@provatferi.org"), never the opaque resourceId directly, so a
 * mailbox never has to be looked up twice in slightly different ways.
 *
 * Deliberately thin: it does not cache or store message content anywhere.
 * Every read hits the live Mail API. See docs on the Mail Center controllers
 * for why (never mirroring the mailbox locally, per the rollout brief).
 */
class HostingerMailService
{
    private ?array $mailboxCache = null;

    public function __construct(
        private readonly AccountApi $account,
        private readonly FoldersApi $folders,
        private readonly MessagesApi $messages,
        private readonly SendApi $send,
        private readonly WebhooksApi $webhooks,
    ) {
    }

    /**
     * Deliberately does NOT throw when the token is unconfigured. This is
     * bound as a container singleton (AppServiceProvider), so any code that
     * merely type-hints HostingerMailService — including just to call
     * isConfigured() before deciding whether to do anything — forces this
     * factory to run. Throwing here previously meant isConfigured() itself
     * was unreachable in any environment without the token (a fresh CI
     * checkout, this app's own test suite, a pre-deploy build's isolated
     * tree): the very check meant to detect "unconfigured" crashed first
     * instead of returning false. An unconfigured instance's Api clients
     * simply carry an empty access token; a real call against them fails
     * naturally (Hostinger rejects it) rather than this class failing at
     * construction/injection time regardless of whether it's ever used.
     */
    public static function make(): self
    {
        $token = (string) (config('services.hostinger_mail.token') ?? '');
        $config = Configuration::getDefaultConfiguration()->setAccessToken($token);

        return new self(
            new AccountApi($config),
            new FoldersApi($config),
            new MessagesApi($config),
            new SendApi($config),
            new WebhooksApi($config),
        );
    }

    public function isConfigured(): bool
    {
        return filled(config('services.hostinger_mail.token'));
    }

    /** @return array<string, string> address => resourceId, for every mailbox this token can see. */
    public function mailboxes(): array
    {
        if ($this->mailboxCache !== null) {
            return $this->mailboxCache;
        }

        $account = $this->account->getCurrentAccount();
        $map = [];
        foreach ($account->getData()?->getMailboxes() ?? [] as $mailbox) {
            $map[$mailbox->getAddress()] = $mailbox->getResourceId();
        }

        return $this->mailboxCache = $map;
    }

    /** @throws RuntimeException when this token has no access to $address */
    public function resourceIdFor(string $address): string
    {
        $id = $this->mailboxes()[$address] ?? null;
        if ($id === null) {
            throw new RuntimeException("This Mail API token has no access to mailbox: {$address}");
        }

        return $id;
    }

    /** @return array<int, string> mailbox addresses this admin/token can act on, in a stable order. */
    public function accessibleAddresses(): array
    {
        $addresses = array_keys($this->mailboxes());
        sort($addresses);

        return $addresses;
    }

    /** @return \Hostinger\Model\V1FoldersFolder[] */
    public function listFolders(string $address): array
    {
        return $this->folders->listFolders($this->resourceIdFor($address), 1, 100)->getData();
    }

    public function listMessages(string $address, string $folder, int $page = 1, int $perPage = 25): \Hostinger\Model\V1FolderMessagesCollection
    {
        return $this->messages->listMessages($this->resourceIdFor($address), $folder, $page, $perPage, '-uid');
    }

    public function searchMessages(string $address, string $folder, \Hostinger\Model\V1FolderMessagesSearchRequest $criteria, int $page = 1, int $perPage = 25): \Hostinger\Model\V1FolderMessagesCollection
    {
        return $this->messages->searchMessages($this->resourceIdFor($address), $folder, $page, $perPage, '-uid', $criteria);
    }

    public function getMessage(string $address, string $folder, int $uid): \Hostinger\Model\V1FolderMessagesMessage
    {
        return $this->messages->getMessage($this->resourceIdFor($address), $folder, $uid)->getData();
    }

    /**
     * Rendered plain text + HTML — RAW, as the Mail API returns it. The
     * caller (NoticeController-style rendering layer) is responsible for
     * sanitizing the HTML before it ever reaches a view; nothing here does
     * that, so a controller must never echo this HTML unsanitized.
     */
    public function getMessageText(string $address, string $folder, int $uid): \Hostinger\Model\V1FolderMessagesMessageText
    {
        return $this->messages->getMessageText($this->resourceIdFor($address), $folder, $uid)->getData();
    }

    /** The raw attachment bytes, as the SDK deserializes them — an SplFileObject over a temp stream. */
    public function getAttachment(string $address, string $folder, int $uid, string $attachmentId): \SplFileObject
    {
        return $this->messages->getMessageAttachment($this->resourceIdFor($address), $folder, $uid, $attachmentId);
    }

    public function markRead(string $address, string $folder, int $uid, bool $read): void
    {
        $request = new V1FolderMessagesFlagsRequest();
        $read ? $request->setAddFlags(['\\Seen']) : $request->setRemoveFlags(['\\Seen']);
        $this->messages->patchMessage($this->resourceIdFor($address), $folder, $uid, $request);
    }

    public function move(string $address, string $folder, int $uid, string $targetFolder): void
    {
        $request = new V1FolderMessagesMoveRequest();
        $request->setTargetFolder($targetFolder);
        $this->messages->moveMessage($this->resourceIdFor($address), $folder, $uid, $request);
    }

    public function delete(string $address, string $folder, int $uid): void
    {
        $this->messages->deleteMessage($this->resourceIdFor($address), $folder, $uid);
    }

    /**
     * Send/reply/forward all go through this one call — the distinction is
     * purely which of $inReplyTo/$forwardOf the caller sets, per the Mail
     * API's own contract (mutually exclusive, {uid, folder} of the source
     * message). At least one of to/cc/bcc must be set.
     *
     * @param array<int, string> $to
     * @param array<int, string> $cc
     * @param array<int, string> $bcc
     * @param array<int, array{filename: string, content: string, contentType?: string}> $attachments base64-free: raw bytes, SDK/serializer handles encoding
     */
    public function send(
        string $fromAddress,
        array $to,
        string $subject,
        ?string $text = null,
        ?string $html = null,
        array $cc = [],
        array $bcc = [],
        array $attachments = [],
        ?array $inReplyTo = null,
        ?array $forwardOf = null,
    ): void {
        $request = new V1SendRequest();
        if ($to !== []) {
            $request->setTo($to);
        }
        if ($cc !== []) {
            $request->setCc($cc);
        }
        if ($bcc !== []) {
            $request->setBcc($bcc);
        }
        $request->setSubject($subject);
        if ($text !== null) {
            $request->setText($text);
        }
        if ($html !== null) {
            $request->setHtml($html);
        }

        if ($attachments !== []) {
            $request->setAttachments(array_map(function (array $a) {
                $attachment = new \Hostinger\Model\V1SendAttachment();
                $attachment->setFilename($a['filename']);
                $attachment->setContent(base64_encode($a['content']));
                if (isset($a['contentType'])) {
                    $attachment->setContentType($a['contentType']);
                }

                return $attachment;
            }, $attachments));
        }

        if ($inReplyTo !== null) {
            $ref = new V1SendMessageRef();
            $ref->setUid($inReplyTo['uid']);
            $ref->setFolder($inReplyTo['folder']);
            $request->setInReplyTo($ref);
        }

        if ($forwardOf !== null) {
            $ref = new V1SendMessageRef();
            $ref->setUid($forwardOf['uid']);
            $ref->setFolder($forwardOf['folder']);
            $request->setForwardOf($ref);
        }

        $this->send->sendEmail($this->resourceIdFor($fromAddress), $request);
    }

    /**
     * Registers (or re-registers) the message.received webhook for a
     * mailbox, pointed at this app's own receiver route. Returns the secret
     * — Hostinger returns it exactly once; the caller is responsible for
     * writing it to .env and never logging/persisting it anywhere else.
     */
    public function createMessageReceivedWebhook(string $address, string $callbackUrl): \Hostinger\Model\V1WebhooksWebhookWithSecret
    {
        $request = new V1WebhooksCreateRequest();
        $request->setName('Provatferi ERP — new message notifier');
        $request->setDescription('Notifies the admin ERP so it can create an in-panel notification. Registered via mail:webhook:register.');
        $request->setEvents(['message.received']);
        $request->setUrl($callbackUrl);
        $request->setStatus('active');

        return $this->webhooks->createWebhook($this->resourceIdFor($address), $request)->getData();
    }

    /** @return \Hostinger\Model\V1WebhooksWebhook[] */
    public function listWebhooks(string $address): array
    {
        return $this->webhooks->listWebhooks($this->resourceIdFor($address))->getData() ?? [];
    }

    public function testWebhook(string $address, string $webhookId): \Hostinger\Model\V1WebhooksTestResult
    {
        return $this->webhooks->testWebhook($this->resourceIdFor($address), $webhookId);
    }

    public function deleteWebhook(string $address, string $webhookId): void
    {
        $this->webhooks->deleteWebhook($this->resourceIdFor($address), $webhookId);
    }
}
