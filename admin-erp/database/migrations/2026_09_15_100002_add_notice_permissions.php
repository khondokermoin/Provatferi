<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Production never re-runs RolesAndPermissionsSeeder, so a new permission
 * module has to arrive through the migration pipeline or it won't exist
 * there at all. Idempotent: rows the seeder already created are left alone.
 * Only super_admin is granted these for now; other roles get mapped later.
 *
 * The action list is a literal copy of Permission::MODULE_ACTIONS['notices']
 * as of this migration, on purpose — a migration must keep doing exactly
 * what it did even if that constant changes later.
 */
return new class extends Migration
{
    private const ACTIONS = ['view', 'create', 'update', 'delete', 'publish', 'archive'];

    public function up(): void
    {
        $now = now();

        foreach (self::ACTIONS as $action) {
            $slug = "notices.{$action}";
            if (! DB::table('permissions')->where('slug', $slug)->exists()) {
                DB::table('permissions')->insert([
                    'name' => ucfirst($action).' notices',
                    'slug' => $slug,
                    'module' => 'notices',
                    'action' => $action,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $superAdminId = DB::table('roles')->where('slug', 'super_admin')->value('id');
        if ($superAdminId !== null) {
            foreach (DB::table('permissions')->where('module', 'notices')->pluck('id') as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $superAdminId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        // role_permissions rows cascade with their permission.
        DB::table('permissions')->where('module', 'notices')->delete();
    }
};
