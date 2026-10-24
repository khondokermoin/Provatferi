<?php

namespace App\Services\Mail;

use RuntimeException;

/** Why the Mail Center did not (or may not have) sent a message — as something the page can say plainly. */
final class MailSendRefused extends RuntimeException
{
    /** The same compose form was already submitted: it is being sent, was sent, or its outcome is unknown. */
    public const DUPLICATE = 'duplicate';

    /** Too many messages from this account / mailbox in the window. */
    public const RATE_LIMITED = 'rate_limited';

    /** The provider refused the message: it was NOT sent. */
    public const FAILED = 'failed';

    /** The provider did not answer: the message may or may not have gone out. */
    public const UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $reason,
        public readonly ?string $detail = null,
        public readonly int $retryAfter = 0,
    ) {
        parent::__construct("Mail not sent: {$reason}");
    }

    public static function duplicate(?string $state): self
    {
        return new self(self::DUPLICATE, $state);
    }

    public static function rateLimited(int $seconds): self
    {
        return new self(self::RATE_LIMITED, null, max(1, $seconds));
    }

    public static function failed(string $providerKind): self
    {
        return new self(self::FAILED, $providerKind);
    }

    public static function unknown(): self
    {
        return new self(self::UNKNOWN);
    }
}
