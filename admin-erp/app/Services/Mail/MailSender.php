<?php

namespace App\Services\Mail;

use App\Models\MailAuditEvent;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\Mail\Data\OutgoingMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

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
    public function send(User $actor, Mailbox $mailbox, OutgoingMessage $message, string $kind, string $formKey, ?array $source = null): void
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

        $facts = ['kind' => $kind, 'recipients' => $message->recipientCount(), 'attachments' => count($message->attachments),
            'attachment_kb' => (int) ceil(array_sum(array_map(fn ($a) => $a->size(), $message->attachments)) / 1024)];

        try {
            $this->provider->send($mailbox, $message);
        } catch (MailProviderException $e) {
            if ($e->outcomeUnknown()) {
                Cache::put($claim, 'unknown', $ttl);
                $this->audit->record(MailAuditEvent::SEND_FAILED, $mailbox, $facts + ['result' => 'unknown_outcome', 'status' => $e->status], $source['folder'] ?? null, $source['uid'] ?? null, $actor);

                throw MailSendRefused::unknown();
            }
            Cache::forget($claim);
            $this->audit->record(MailAuditEvent::SEND_FAILED, $mailbox, $facts + ['result' => $e->kind, 'status' => $e->status], $source['folder'] ?? null, $source['uid'] ?? null, $actor);

            throw MailSendRefused::failed($e->kind);
        }

        Cache::put($claim, 'sent', $ttl);
        $this->audit->record(self::AUDIT_ACTIONS[$kind], $mailbox, $facts, $source['folder'] ?? null, $source['uid'] ?? null, $actor);
        $this->folders->forget($mailbox);
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
