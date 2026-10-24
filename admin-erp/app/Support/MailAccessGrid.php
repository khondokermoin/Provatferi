<?php

namespace App\Support;

use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\Role;
use App\Models\User;
use App\Support\Mfa\MfaPolicy;

/**
 * What the "Mailbox access" grid on a role's page and on a user's page shows (docs/MAIL_CENTER.md, "Assigning access"):
 * every registered mailbox, the subject's own grant on each, and — on a user's page — what the user already gets through
 * roles, so the person assigning access sees the effective result.
 */
final class MailAccessGrid
{
    /** @return array{mailboxes: \Illuminate\Support\Collection, own: array<int, array<string, bool>>, inherited: array<int, array<string, list<string>>>, manageAll: bool} */
    public static function forRole(Role $role): array
    {
        return [
            'mailboxes' => Mailbox::query()->ordered()->get(),
            'own' => self::flagsByMailbox(MailboxGrant::query()->where('role_id', $role->getKey())->get()),
            'inherited' => [],
            'manageAll' => $role->slug === MfaPolicy::PRIVILEGED_ROLE || $role->permissions()->where('slug', 'mail.manage_all')->exists(),
            'canOpen' => $role->slug === MfaPolicy::PRIVILEGED_ROLE || $role->permissions()->whereIn('slug', ['mail.access', 'mail.manage_all'])->exists(),
        ];
    }

    /** @return array{mailboxes: \Illuminate\Support\Collection, own: array<int, array<string, bool>>, inherited: array<int, array<string, list<string>>>, manageAll: bool} */
    public static function forUser(User $user): array
    {
        $roles = $user->roles()->get();
        $inherited = [];
        foreach (MailboxGrant::query()->with('role:id,name')->whereIn('role_id', $roles->pluck('id'))->get() as $grant) {
            foreach ($grant->flags() as $capability => $on) {
                if ($on) {
                    $inherited[$grant->mailbox_id][$capability][] = (string) $grant->role?->name;
                }
            }
        }

        return [
            'mailboxes' => Mailbox::query()->ordered()->get(),
            'own' => self::flagsByMailbox(MailboxGrant::query()->where('user_id', $user->getKey())->get()),
            'inherited' => $inherited,
            'manageAll' => $user->can('mail.manage_all'),
            'canOpen' => $user->can('mail-center'),
        ];
    }

    /** @return array<int, array<string, bool>> */
    private static function flagsByMailbox($grants): array
    {
        $out = [];
        foreach ($grants as $grant) {
            $out[$grant->mailbox_id] = $grant->flags();
        }

        return $out;
    }
}
