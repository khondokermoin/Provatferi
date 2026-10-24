<?php

namespace App\Services\Mail;

use App\Models\Mailbox;
use App\Services\Mail\Data\MailFolder;
use App\Services\Mail\Data\MailMessage;
use App\Services\Mail\Data\MailMessagePage;
use App\Services\Mail\Data\MailMessageText;
use App\Services\Mail\Data\MailSearch;
use App\Services\Mail\Data\OutgoingMessage;

/**
 * The Mail Center's view of a mail provider (docs/MAIL_CENTER.md, "Provider integration"). Every operation is for ONE
 * registered mailbox, addressed by the registry row the caller has already authorised — never by an address or a
 * provider id taken from a request. Implementations throw MailProviderException and nothing else.
 *
 * Only operations the provider really offers are here. In particular there is no permanent delete: "delete" in the Mail
 * Center is a move to the mailbox's Trash folder.
 */
interface MailProvider
{
    /** A credential is configured on the server (says nothing about whether it works). */
    public function isConfigured(): bool;

    /** @return list<string> the lower-case addresses the credential reaches */
    public function reachableAddresses(): array;

    /** The provider's own (non-secret) identifier of the mailbox at $address, or null when the credential cannot reach it. */
    public function mailboxId(string $address): ?string;

    /** @return list<MailFolder> */
    public function folders(Mailbox $mailbox): array;

    public function messages(Mailbox $mailbox, string $folder, int $page, int $perPage): MailMessagePage;

    public function search(Mailbox $mailbox, string $folder, MailSearch $search, int $page, int $perPage): MailMessagePage;

    /** The envelope and attachment list. Does not change the message's read state. */
    public function message(Mailbox $mailbox, string $folder, int $uid): MailMessage;

    /** The body. The provider marks the message read (\Seen) when it is fetched. */
    public function messageText(Mailbox $mailbox, string $folder, int $uid): MailMessageText;

    /** The bytes of one attachment, by the provider's id from message()'s attachment list. */
    public function attachmentContent(Mailbox $mailbox, string $folder, int $uid, string $attachmentId): string;

    /** @param list<string> $add @param list<string> $remove IMAP flags such as \Seen, \Flagged */
    public function setFlags(Mailbox $mailbox, string $folder, int $uid, array $add, array $remove): void;

    public function move(Mailbox $mailbox, string $folder, int $uid, string $targetFolder): void;

    public function createFolder(Mailbox $mailbox, string $name): void;

    /** Sends from the mailbox itself; a copy is saved in its Sent folder by the provider. */
    public function send(Mailbox $mailbox, OutgoingMessage $message): void;
}
