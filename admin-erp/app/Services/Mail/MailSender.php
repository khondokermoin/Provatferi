<?php

namespace App\Services\Mail;

use App\Models\MailAuditEvent;
use App\Models\MailIdentity;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\Mail\Data\OutgoingMessage;
use App\Support\MailCenterIdentityMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Throwable;

/**
 * Sends one Mail Center message, at most once (docs/MAIL_CENTER.md, "Limits and duplicate protection").
 *
 *   1. The compose form's one-time key is claimed atomically for this account. A second submission of the same form — a
 *      double click, a resubmitted page, a retry after a time-out — finds the claim and sends nothing.
 *   2. Limits per account, per mailbox and per action (never per IP address alone), a one-minute burst limit per account
 *      and mailbox, and an hourly limit for the whole mailbox.
 *   3. Only then the provider. A refusal frees the key, so the same form can be sent again once corrected; an unknown
 *      outcome (no answer) KEEPS it, because the message may have gone out — the person is told to look in Sent first.
 */
final class MailSender
{
    public const SEND = 'send';

    public const REPLY = 'reply';

    public const FORWARD = 'forward';

    private const AUDIT_ACTIONS = [self::SEND => MailAuditEvent::MESSAGE_SENT, self::REPLY => MailAuditEvent::MESSAGE_REPLIED, self::FORWARD => MailAuditEvent::MESSAGE_FORWARDED];

    public function __construct(
        private readonly MailProvider $provider,
        private readonly MailAuditLogger $audit,
        private readonly MailFolders $folders,
    ) {}

    /**
     * @param  string  $kind  send | reply | forward
     * @param  array{uid: int, folder: string}|null  $source  the message answered or forwarded
     *
     * @throws MailSendRefused
     */
    public function send(User $actor, Mailbox $mailbox, OutgoingMessage $message, string $kind, string $formKey, ?array $source = null, ?MailIdentity $identity = null): void
    {
        $ttl = (int) config('mail_center.compose.idempotency_seconds', 86400);
        $claim = 'mail-center:sent:'.$actor->getKey().':'.hash('sha256', $formKey);
        if (! Cache::add($claim, 'pending', $ttl)) {
            $state = (string) Cache::get($claim, 'pending');
            $this->audit->record(MailAuditEvent::SEND_DUPLICATE_BLOCKED, $mailbox, ['kind' => $kind, 'state' => $state], $source['folder'] ?? null, $source['uid'] ?? null, $actor);

            throw MailSendRefused::duplicate($state);
        }

        foreach ($this->limits($actor, $mailbox, $kind) as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                Cache::forget($claim);

                throw MailSendRefused::rateLimited(RateLimiter::availableIn($key));
            }
        }
        foreach ($this->limits($actor, $mailbox, $kind) as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }

        // The transport is decided here, by the identity the server authorised — never by anything in the request.
        // The mailbox's own address goes out through the provider API, which saves the copy to Sent itself; any other
        // identity is an alias the API cannot send as, so it goes over SMTP (docs/MAIL_CENTER.md).
        $viaApi = $identity === null || strtolower((string) $identity->address) === strtolower((string) $mailbox->address);
        $facts = ['kind' => $kind, 'recipients' => $message->recipientCount(), 'attachments' => count($message->attachments),
            'attachment_kb' => (int) ceil(array_sum(array_map(fn ($a) => $a->size(), $message->attachments)) / 1024),
            'identity' => $identity !== null ? (string) $identity->address : (string) $mailbox->address,
            'transport' => $viaApi ? 'provider_api' : 'smtp'];

        try {
            if ($viaApi) {
                $this->provider->send($mailbox, $message);
            } else {
                MailCenterIdentityMail::send($identity, $message, $this->copyRecipients($mailbox));
            }
        } catch (MailProviderException $e) {
            if ($e->outcomeUnknown()) {
                Cache::put($claim, 'unknown', $ttl);
                $this->audit->record(MailAuditEvent::SEND_FAILED, $mailbox, $facts + ['result' => 'unknown_outcome', 'status' => $e->status], $source['folder'] ?? null, $source['uid'] ?? null, $actor);

                throw MailSendRefused::unknown();
            }
            Cache::forget($claim);
            $this->audit->record(MailAuditEvent::SEND_FAILED, $mailbox, $facts + ['result' => $e->kind, 'status' => $e->status], $source['folder'] ?? null, $source['uid'] ?? null, $actor);

            throw MailSendRefused::failed($e->kind);
        } catch (Throwable $e) {
            // SMTP. A message the transport refused outright (a malformed address) certainly did not go out, so the
            // form is freed. Anything else — a dropped connection, a time-out — may have been delivered after the
            // envelope was accepted, so the form stays claimed and the writer is told to look in the mailbox first
            // rather than being invited to send a second copy.
            $certainlyNotSent = $e instanceof RfcComplianceException;
            $this->audit->record(MailAuditEvent::SEND_FAILED, $mailbox, $facts + [
                'result' => $certainlyNotSent ? 'rejected' : 'unknown_outcome', 'exception' => class_basename($e),
            ], $source['folder'] ?? null, $source['uid'] ?? null, $actor);
            if ($certainlyNotSent) {
                Cache::forget($claim);

                throw MailSendRefused::failed(MailProviderException::REJECTED);
            }
            Cache::put($claim, 'unknown', $ttl);

            throw MailSendRefused::unknown();
        }

        Cache::put($claim, 'sent', $ttl);
        $this->audit->record(self::AUDIT_ACTIONS[$kind], $mailbox, $facts, $source['folder'] ?? null, $source['uid'] ?? null, $actor);
        $this->folders->forget($mailbox);
    }

    /**
     * Who is Bcc'd on an SMTP send so a copy of it survives. The provider API files its own copy in Sent; SMTP does
     * not, and the API offers no way to append one, so the mailbox is sent a copy of what went out under one of its
     * identities. Switchable, because it does put those copies in the mailbox's Inbox.
     *
     * @return list<string>
     */
    private function copyRecipients(Mailbox $mailbox): array
    {
        $address = trim((string) $mailbox->address);

        return config('mail_center.sending.keep_alias_copy_in_mailbox', true) && $address !== '' ? [$address] : [];
    }

    /** @return list<array{0: string, 1: int, 2: int}> key, attempts, decay seconds */
    private function limits(User $actor, Mailbox $mailbox, string $kind): array
    {
        $limits = (array) config('mail_center.rate_limits');
        $who = $actor->getKey().':'.$mailbox->getKey();
        [$max, $decay] = $limits[$kind] ?? [20, 3600];
        [$burstMax, $burstDecay] = $limits['outgoing_burst'] ?? [5, 60];
        [$boxMax, $boxDecay] = $limits['mailbox_hourly'] ?? [100, 3600];

        return [
            ["mail-center:{$kind}:{$who}", (int) $max, (int) $decay],
            ["mail-center:outgoing:{$who}", (int) $burstMax, (int) $burstDecay],
            ['mail-center:mailbox:'.$mailbox->getKey(), (int) $boxMax, (int) $boxDecay],
        ];
    }
}
