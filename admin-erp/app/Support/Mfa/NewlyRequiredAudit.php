<?php

namespace App\Support\Mfa;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\SecurityEventLogger;
use Illuminate\Support\Collection;

/**
 * Records "this account must now use two-step verification" when a change of roles or of a role's permissions makes an
 * account required that was not before (docs/MFA_SECURITY.md, "Audit"). Take a snapshot before the change, record after.
 */
final class NewlyRequiredAudit
{
    /**
     * @param  Collection<int, User>  $users
     * @return array<int, bool> user id => required before the change
     */
    public static function before(Collection $users): array
    {
        $snapshot = [];
        foreach ($users as $user) {
            $snapshot[$user->getKey()] = MfaPolicy::isRequired($user);
        }

        return $snapshot;
    }

    /**
     * @param  array<int, bool>  $before
     * @param  Collection<int, User>  $users  reloaded after the change
     */
    public static function after(array $before, Collection $users, ?User $actor, string $because): int
    {
        $count = 0;
        foreach ($users as $user) {
            $wasRequired = $before[$user->getKey()] ?? false;
            if (! $wasRequired && ! $user->hasMfaEnabled() && MfaPolicy::isRequired($user)) {
                app(SecurityEventLogger::class)->record(SecurityEvent::MFA_NEWLY_REQUIRED, $user, ['because' => $because], $actor);
                $count++;
            }
        }

        return $count;
    }
}
