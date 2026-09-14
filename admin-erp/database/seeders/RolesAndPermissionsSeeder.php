<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [];
        foreach (Permission::definitions() as ['module' => $module, 'action' => $action]) {
            $permissions[] = Permission::query()->firstOrCreate(
                ['slug' => "{$module}.{$action}"],
                [
                    'name' => ucfirst($action)." {$module}",
                    'module' => $module,
                    'action' => $action,
                ],
            );
        }

        $superAdmin = Role::query()->firstOrCreate(
            ['slug' => 'super_admin'],
            ['name' => 'Super Admin', 'description' => 'Full system access.', 'is_system_role' => true],
        );
        $superAdmin->permissions()->sync(collect($permissions)->pluck('id'));

        $regionalAdmin = Role::query()->firstOrCreate(
            ['slug' => 'regional_admin'],
            ['name' => 'Regional Admin', 'description' => 'Manages a specific organizational unit.', 'is_system_role' => true],
        );
        $regionalAdmin->permissions()->sync(
            collect($permissions)
                ->filter(fn (Permission $p) => in_array($p->module, ['organization', 'activities', 'membership', 'recruitment', 'payments']))
                ->pluck('id'),
        );

        $membershipAdmin = Role::query()->firstOrCreate(
            ['slug' => 'membership_admin'],
            ['name' => 'Membership Admin', 'description' => 'Reviews membership applications.', 'is_system_role' => true],
        );
        $membershipAdmin->permissions()->sync(
            collect($permissions)->filter(fn (Permission $p) => in_array($p->module, ['membership', 'payments']))->pluck('id'),
        );

        // No 'member' role: members are never `users` rows and never touch
        // this RBAC system at all — see App\Models\Member and config/auth.php.
        // A role of this slug was pre-seeded before that separation was
        // decided, with no code ever offering it as an assignable option.
        // Deleted only when confirmed unused (no user ever holds it) —
        // never blindly, since a delete here would cascade to user_roles.
        $deadMemberRole = Role::query()->where('slug', 'member')->where('is_system_role', true)->first();
        if ($deadMemberRole && !$deadMemberRole->users()->exists()) {
            $deadMemberRole->delete();
        }
    }
}
