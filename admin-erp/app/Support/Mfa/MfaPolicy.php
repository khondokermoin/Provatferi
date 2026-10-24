<?php

namespace App\Support\Mfa;

use App\Models\MailboxGrant;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;

/**
 * WHO must use two-step verification (docs/MFA_SECURITY.md, "Role-based policy"). The one place that decides it.
 *
 * An account is REQUIRED to use it when any of these holds:
 *   1. one of its roles has `mfa_required` switched on (an explicit, per-role choice, effective immediately);
 *   2. the privileged-account rule is switched on (setting security.mfa_privileged_enforced) and the account is privileged:
 *      it holds the super_admin role, or any capability that manages users and permissions, security-sensitive settings,
 *      payments, or the mailbox (PRIVILEGED_CAPABILITIES) — so a FUTURE financial or user-management role is covered the
 *      moment it is given such a permission, without anyone remembering to tick a box;
 *   3. an administrator reset its two-step verification (php artisan mfa:reset): it must enrol again.
 * Everyone else may use it (optional). Nothing here looks at a user id: the policy is roles and capabilities only.
 *
 * The privileged rule is OFF until the owner has enrolled and turns it on (staged roll-out: no deployment can force the
 * only Super Admin into enrolment). Removing a role never deletes anyone's secret.
 */
final class MfaPolicy
{
    public const PRIVILEGED_ROLE = 'super_admin';

    public const ENFORCEMENT_SETTING = 'security.mfa_privileged_enforced';

    /**
     * Capabilities that make an account privileged: users/permissions, security-sensitive settings, money, the mailboxes.
     * Every Mail Center permission counts (docs/MAIL_CENTER.md, "Two-step verification"): opening the Mail Center, every
     * mailbox, and managing mailbox access. So does holding a grant on any mailbox (hasMailboxGrant), whether through a role
     * or directly — reading institutional mail is never an unprivileged act.
     */
    public const PRIVILEGED_CAPABILITIES = [
        'users.create', 'users.update', 'users.delete',
        'settings.update',
        'payments.create', 'payments.update', 'payments.delete', 'payments.approve',
        'mail.access', 'mail.manage_all', 'mail.manage_access',
    ];

    public const REASON_ROLE = 'role';

    public const REASON_PRIVILEGED = 'privileged';

    public const REASON_RESET = 'reset';

    public static function isPrivileged(User $user): bool
    {
        if ($user->hasRole(self::PRIVILEGED_ROLE)) {
            return true;
        }
        if (array_intersect(self::PRIVILEGED_CAPABILITIES, $user->permissionSlugs()) !== []) {
            return true;
        }

        return self::hasMailboxGrant($user);
    }

    public static function roleIsPrivileged(Role $role): bool
    {
        if ($role->slug === self::PRIVILEGED_ROLE) {
            return true;
        }
        if ($role->permissions()->whereIn('slug', self::PRIVILEGED_CAPABILITIES)->exists()) {
            return true;
        }

        return MailboxGrant::existsForRole($role);
    }

    /** Access to any mailbox, through a role or directly (a grant row exists only when it grants something). */
    public static function hasMailboxGrant(User $user): bool
    {
        return MailboxGrant::existsForAccount($user);
    }

    public static function privilegedEnforcementActive(): bool
    {
        return (bool) Setting::get(self::ENFORCEMENT_SETTING, false);
    }

    /**
     * Why this account must use two-step verification; empty when it is optional.
     *
     * @return list<array{reason: string, role?: string}>
     */
    public static function requirementReasons(User $user): array
    {
        $reasons = [];
        foreach ($user->roles as $role) {
            if ($role->mfa_required) {
                $reasons[] = ['reason' => self::REASON_ROLE, 'role' => $role->name];
            }
        }
        if (self::privilegedEnforcementActive() && self::isPrivileged($user)) {
            $reasons[] = ['reason' => self::REASON_PRIVILEGED];
        }
        if ($user->mfa_enrollment_required_at !== null) {
            $reasons[] = ['reason' => self::REASON_RESET];
        }

        return $reasons;
    }

    public static function isRequired(User $user): bool
    {
        return self::requirementReasons($user) !== [];
    }

    /** Required accounts cannot turn it off themselves (they can move it to a new device instead). */
    public static function canDisable(User $user): bool
    {
        return $user->hasMfaEnabled() && ! self::isRequired($user);
    }

    /** Required but not set up yet: such an account may reach nothing but the enrolment pages. */
    public static function mustEnrollNow(User $user): bool
    {
        return ! $user->hasMfaEnabled() && self::isRequired($user);
    }
}
