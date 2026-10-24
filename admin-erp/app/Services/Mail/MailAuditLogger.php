<?php

namespace App\Services\Mail;

use App\Models\MailAuditEvent;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\SecurityEventLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Writes the Mail Center audit trail (docs/MAIL_CENTER.md, "Audit"): who, which mailbox, what, which message (folder +
 * UID), when, from which address and browser. `context` may carry counts, flags and result codes. A key that could carry a
 * credential or a piece of a message is refused outright, so a careless caller cannot put a token, a body or a subject in
 * the table.
 */
class MailAuditLogger
{
    private const FORBIDDEN_KEYS = [
        'token', 'secret', 'password', 'authorization', 'auth', 'header', 'headers', 'cookie', 'session', 'credential',
        'body', 'html', 'text', 'content', 'subject', 'message', 'snippet', 'preview', 'filename', 'attachment_content',
    ];

    /** @param array<string, scalar|list<scalar>|array<string, scalar>|null> $context */
    public function record(
        string $action,
        ?Mailbox $mailbox,
        array $context = [],
        ?string $folder = null,
        ?int $uid = null,
        ?User $actor = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
    ): void {
        foreach (array_keys($context) as $key) {
            if (in_array(strtolower((string) $key), self::FORBIDDEN_KEYS, true)) {
                throw new InvalidArgumentException("A mail audit event may not carry the field \"{$key}\".");
            }
        }
        $request = SecurityEventLogger::currentRequest();
        $actor ??= $request?->user();

        try {
            MailAuditEvent::query()->create([
                'actor_id' => $actor?->getKey(),
                'mailbox_id' => $mailbox?->getKey(),
                'action' => $action,
                'folder' => $folder !== null ? mb_substr($folder, 0, 191) : null,
                'message_uid' => $uid,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
                'context' => $context === [] ? null : $context,
            ]);
        } catch (Throwable $e) {
            // The audit trail must never turn a working action into an error page; a failure is reported without request data.
            Log::error('Mail audit event could not be recorded.', ['action' => $action, 'exception' => $e::class]);
        }
    }

    /** "Mailbox opened", at most once per account and mailbox per window (config mail_center.audit_open_window_seconds). */
    public function mailboxOpened(Mailbox $mailbox, User $actor, bool $viaManageAll): void
    {
        $window = (int) config('mail_center.audit_open_window_seconds', 1800);
        if (Cache::add("mail-center:opened:{$actor->getKey()}:{$mailbox->getKey()}", 1, $window)) {
            $this->record(MailAuditEvent::MAILBOX_OPENED, $mailbox, ['via' => $viaManageAll ? 'manage_all' : 'grant'], actor: $actor);
        }
    }
}
