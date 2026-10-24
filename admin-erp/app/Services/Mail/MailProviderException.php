<?php

namespace App\Services\Mail;

use RuntimeException;
use Throwable;

/**
 * What went wrong with the mail provider, in words the Mail Center may show. It deliberately carries only a KIND and the
 * provider's HTTP status — never the provider's message or response body, which can contain identifiers and fragments of
 * mail. The original exception is kept as `previous` for the server log's exception class only.
 */
final class MailProviderException extends RuntimeException
{
    /** No provider token on the server: an administrator has to configure it. */
    public const UNCONFIGURED = 'unconfigured';

    /** The token works but does not include this mailbox. */
    public const NOT_IN_SCOPE = 'not_in_scope';

    /** The message, folder or attachment does not exist (any more). */
    public const NOT_FOUND = 'not_found';

    /** The provider asked us to slow down. */
    public const RATE_LIMITED = 'rate_limited';

    /** The provider refused the request as invalid (for a send: the message was NOT sent). */
    public const REJECTED = 'rejected';

    /** The provider refused the token. */
    public const AUTH = 'auth';

    /** No answer, a time-out or a server error: the outcome is unknown. */
    public const UNAVAILABLE = 'unavailable';

    private function __construct(
        public readonly string $kind,
        public readonly int $status = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Mail provider: {$kind}".($status > 0 ? " ({$status})" : ''), 0, $previous);
    }

    public static function of(string $kind, int $status = 0, ?Throwable $previous = null): self
    {
        return new self($kind, $status, $previous);
    }

    /** From an HTTP status the provider answered with (0 = no answer at all). */
    public static function fromStatus(int $status, ?Throwable $previous = null): self
    {
        $kind = match (true) {
            $status === 404 => self::NOT_FOUND,
            $status === 429 => self::RATE_LIMITED,
            $status === 401 || $status === 403 => self::AUTH,
            $status === 400 || $status === 409 || $status === 413 || $status === 422 => self::REJECTED,
            default => self::UNAVAILABLE,
        };

        return new self($kind, $status, $previous);
    }

    /** A send whose outcome is unknown: it may or may not have gone out. */
    public function outcomeUnknown(): bool
    {
        return $this->kind === self::UNAVAILABLE;
    }
}
