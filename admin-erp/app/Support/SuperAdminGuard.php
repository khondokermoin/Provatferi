<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

/**
 * Prevents the system from being locked out of its own administration.
 *
 * A "usable" Super Admin is an active, non-deleted user holding the
 * super_admin role. Any operation that would take the count to zero — removing
 * the role, deactivating the account, or deleting it — must be refused.
 */
class SuperAdminGuard
{
    public const ROLE = 'super_admin';

    public static function usableCount(): int
    {
        return User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn ($q) => $q->where('slug', self::ROLE))
            ->count();
    }

    public static function isUsable(User $user): bool
    {
        return $user->status === 'active' && $user->hasRole(self::ROLE);
    }

    /** True when removing/disabling this user would leave no usable Super Admin. */
    public static function isLastUsable(User $user): bool
    {
        return self::isUsable($user) && self::usableCount() <= 1;
    }

    /**
     * True when the given role set would strip super_admin from the last
     * remaining usable Super Admin.
     *
     * @param  array<int, int|string>  $newRoleIds
     */
    public static function wouldOrphanBySync(User $user, array $newRoleIds): bool
    {
        if (! self::isLastUsable($user)) {
            return false;
        }

        $superAdminId = Role::query()->where('slug', self::ROLE)->value('id');

        return ! in_array((int) $superAdminId, array_map('intval', $newRoleIds), true);
    }
}
