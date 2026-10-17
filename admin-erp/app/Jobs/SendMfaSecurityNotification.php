<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\MfaSecurityNotification;
use App\Services\SecurityEventLogger;
use App\Support\AdminTime;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one account-security e-mail about a two-step verification change (docs/MFA_SECURITY.md, "Security e-mails").
 * Dispatched after the response, like the project's other mail (no queue worker on this host); from the console it is
 * sent at once. A failure is logged with the event and the exception class only — never the body.
 *
 * The facts are taken when the change happens (time, address, browser) and are not secret; a code, a secret or a
 * recovery code is never handed to this class.
 */
class SendMfaSecurityNotification
{
    use Dispatchable;

    /** @param array<string, scalar|null> $facts */
    public function __construct(public readonly int $userId, public readonly string $event, public readonly array $facts)
    {
    }

    /** @param array<string, scalar|null> $extra */
    public static function forUser(User $user, string $event, array $extra = []): void
    {
        $request = SecurityEventLogger::currentRequest();
        $facts = [
            'at' => AdminTime::now()->format('Y-m-d H:i').' (Asia/Dhaka)',
            'ip' => $request?->ip(),
            'device' => $request ? self::device((string) $request->userAgent()) : null,
        ] + $extra;

        $job = new self($user->getKey(), $event, $facts);
        if ($request === null || app()->runningUnitTests()) {
            $job->handle();

            return;
        }
        dispatch($job)->afterResponse();
    }

    public function handle(): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null || $user->email === '') {
            return;
        }

        try {
            $user->notify((new MfaSecurityNotification($this->event, $this->facts))->locale($user->ui_locale ?: 'bn'));
        } catch (Throwable $e) {
            Log::error('MFA security e-mail failed to send.', ['event' => $this->event, 'exception' => $e::class, 'error' => $e->getMessage()]);
        }
    }

    /** A short, human description of the browser ("Chrome on Windows"), never the whole user-agent string. */
    private static function device(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'a browser',
        };
        $system = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') || str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return $system !== null ? "{$browser} / {$system}" : $browser;
    }
}
