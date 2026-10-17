<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Production never re-runs RolesAndPermissionsSeeder, so a new permission module has to arrive through the migration
 * pipeline or it does not exist there at all (the notices module did the same, 2026-09-15). Idempotent: rows the seeder
 * already created are left alone. Only super_admin is granted these — every other role stays without access to
 * Leadership until someone grants it deliberately.
 *
 * The action list is a literal copy of Permission::MODULE_ACTIONS['leadership'] as of this migration, on purpose: a
 * migration keeps doing exactly what it did even if that constant changes later.
 *
 *   view     see the list and preview a profile
 *   create   add a profile (always saved as inactive: publishing needs `publish`)
 *   update   edit a profile's content and photo
 *   publish  activate / deactivate, feature / unfeature, set the display order
 *   archive  archive and restore
 */
return new class extends Migration
{
    private const ACTIONS = ['view', 'create', 'update', 'publish', 'archive'];

    public function up(): void
    {
        $now = now();

        foreach (self::ACTIONS as $action) {
            $slug = "leadership.{$action}";
            if (! DB::table('permissions')->where('slug', $slug)->exists()) {
                DB::table('permissions')->insert([
                    'name' => ucfirst($action).' leadership',
                    'slug' => $slug,
                    'module' => 'leadership',
                    'action' => $action,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $superAdminId = DB::table('roles')->where('slug', 'super_admin')->value('id');
        if ($superAdminId !== null) {
            foreach (DB::table('permissions')->where('module', 'leadership')->pluck('id') as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $superAdminId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        // role_permissions rows cascade with their permission.
        DB::table('permissions')->where('module', 'leadership')->delete();
    }
};
