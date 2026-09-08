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
        foreach (Permission::MODULES as $module) {
            foreach (Permission::ACTIONS as $action) {
                $permissions[] = Permission::query()->firstOrCreate(
                    ['slug' => "{$module}.{$action}"],
                    [
                        'name' => ucfirst($action)." {$module}",
                        'module' => $module,
                        'action' => $action,
                    ],
                );
            }
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
                ->filter(fn (Permission $p) => in_array($p->module, ['organization', 'activities', 'membership', 'recruitment']))
                ->pluck('id'),
        );

        $membershipAdmin = Role::query()->firstOrCreate(
            ['slug' => 'membership_admin'],
            ['name' => 'Membership Admin', 'description' => 'Reviews membership applications.', 'is_system_role' => true],
        );
        $membershipAdmin->permissions()->sync(
            collect($permissions)->filter(fn (Permission $p) => $p->module === 'membership')->pluck('id'),
        );

        Role::query()->firstOrCreate(
            ['slug' => 'member'],
            ['name' => 'Member', 'description' => 'Default role for an approved member.', 'is_system_role' => true],
        );
    }
}
