<?php

namespace App\Services\Mail;

use App\Models\MailAuditEvent;
use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\Role;
use App\Models\User;
use App\Support\Mfa\NewlyRequiredAudit;
use Illuminate\Support\Facades\DB;

/**
 * Writes mailbox access from the access grid (docs/MAIL_CENTER.md, "Assigning access"). One row per mailbox and role (or
 * account); a mailbox with nothing ticked has no row. Replying, forwarding and changing message state need the message,
 * so ticking any of them grants reading too — the stored grant always says what is really allowed.
 *
 * Every change is audited (before → after, per mailbox), and an account that the change makes "privileged" under the
 * two-step policy is recorded as newly required, exactly as a role-permission change is.
 */
final class MailboxGrantManager
{
    public function __construct(private readonly MailAuditLogger $audit) {}

    /** @param array<int|string, mixed> $input mailbox id => [capability => truthy] @return int mailboxes changed */
    public function syncForRole(Role $role, array $input, User $actor): int
    {
        $before = NewlyRequiredAudit::before($role->users()->with('roles.permissions')->get());
        $changed = $this->sync('role_id', (int) $role->getKey(), 'role', $input, $actor);
        if ($changed > 0) {
            NewlyRequiredAudit::after($before, $role->users()->with('roles.permissions')->get(), $actor, 'mailbox_access');
        }

        return $changed;
    }

    /** @param array<int|string, mixed> $input @return int mailboxes changed */
    public function syncForUser(User $target, array $input, User $actor): int
    {
        $before = NewlyRequiredAudit::before(collect([$target->fresh(['roles.permissions'])]));
        $changed = $this->sync('user_id', (int) $target->getKey(), 'user', $input, $actor);
        if ($changed > 0) {
            NewlyRequiredAudit::after($before, collect([$target->fresh(['roles.permissions'])]), $actor, 'mailbox_access');
        }

        return $changed;
    }

    /**
     * @param  array<int|string, mixed>  $row
     * @return array<string, bool> capability => granted, with the read rule applied
     */
    public static function normalize(array $row): array
    {
        $flags = [];
        foreach (MailboxGrant::CAPABILITIES as $capability) {
            $flags[$capability] = filter_var($row[$capability] ?? false, FILTER_VALIDATE_BOOLEAN);
        }
        foreach (MailboxGrant::NEEDS_READ as $capability) {
            if ($flags[$capability]) {
                $flags['read'] = true;
            }
        }

        return $flags;
    }

    private function sync(string $column, int $id, string $subjectType, array $input, User $actor): int
    {
        $changed = 0;
        DB::transaction(function () use ($column, $id, $subjectType, $input, $actor, &$changed) {
            $existing = MailboxGrant::query()->where($column, $id)->lockForUpdate()->get()->keyBy('mailbox_id');
            // Only registered mailboxes: an id the form did not offer is ignored, never created.
            foreach (Mailbox::query()->orderBy('id')->get() as $mailbox) {
                $flags = self::normalize((array) ($input[$mailbox->getKey()] ?? []));
                $grant = $existing->get($mailbox->getKey());
                $before = $grant?->flags() ?? array_fill_keys(MailboxGrant::CAPABILITIES, false);
                if ($flags === $before) {
                    continue;
                }
                if (! in_array(true, $flags, true)) {
                    $grant?->delete();
                } else {
                    $values = ['granted_by' => $actor->getKey()];
                    foreach ($flags as $capability => $on) {
                        $values['can_'.$capability] = $on;
                    }
                    MailboxGrant::query()->updateOrCreate(['mailbox_id' => $mailbox->getKey(), $column => $id], $values);
                }
                $this->audit->record(MailAuditEvent::ACCESS_CHANGED, $mailbox, ['before' => $before, 'after' => $flags], actor: $actor, subjectType: $subjectType, subjectId: $id);
                $changed++;
            }
        });

        return $changed;
    }
}
