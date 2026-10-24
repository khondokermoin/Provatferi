<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Mail Center's role permissions (docs/MAIL_CENTER.md, "Permission model"). What a person may do INSIDE a mailbox is
 * no longer a role permission: it is a per-mailbox grant (mailbox_grants). A role keeps three switches:
 *
 *   mail.access         may open the Mail Center at all (and sees only the mailboxes it has a grant for)
 *   mail.manage_all     every enabled mailbox, every action, without a grant (Super Admin level)
 *   mail.manage_access  register mailboxes and assign / revoke mailbox access (behind a fresh two-step confirmation)
 *
 * The old role-level actions (mail.view / read / send / manage / delete, defined in 2026-09 for a Mail Center that was
 * never built) are removed with their role links. On production none of them existed — the seeder never ran there and no
 * migration had created them — so nothing that worked before changes; on installs where the seeder did create them, only
 * super_admin held them. Only super_admin receives the new ones: every other role stays without mail until someone grants
 * it deliberately.
 *
 * The action list is a literal copy of Permission::MODULE_ACTIONS['mail'] as of this migration, on purpose.
 */
return new class extends Migration
{
    private const ACTIONS = ['access', 'manage_all', 'manage_access'];

    private const RETIRED = ['view', 'read', 'send', 'manage', 'delete'];

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->where('module', 'mail')->whereIn('action', self::RETIRED)->delete();

        $names = ['access' => 'Open the Mail Center', 'manage_all' => 'Use every mailbox', 'manage_access' => 'Manage mailboxes and mailbox access'];
        foreach (self::ACTIONS as $action) {
            $slug = "mail.{$action}";
            if (! DB::table('permissions')->where('slug', $slug)->exists()) {
                DB::table('permissions')->insert([
                    'name' => $names[$action],
                    'slug' => $slug,
                    'module' => 'mail',
                    'action' => $action,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $superAdminId = DB::table('roles')->where('slug', 'super_admin')->value('id');
        if ($superAdminId !== null) {
            foreach (DB::table('permissions')->where('module', 'mail')->pluck('id') as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $superAdminId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        // role_permissions rows cascade with their permission. The retired actions are not recreated: nothing uses them.
        DB::table('permissions')->where('module', 'mail')->whereIn('action', self::ACTIONS)->delete();
    }
};
