<?php

namespace Tests\Feature\Mail;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The 2026_10_24_100300 migration (docs/MAIL_CENTER.md, "Permission model"): the never-enforced 2026-09 mail actions go,
 * the three Mail Center permissions arrive for super_admin ONLY, and running it twice changes nothing.
 */
class MailPermissionsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_retires_the_old_actions_and_grants_the_new_ones_to_super_admin_only(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $other = Role::query()->create(['name' => 'Custom', 'slug' => 'custom-'.uniqid()]);
        // An install where the old seeder had created the 2026-09 rows, one of them on a custom role.
        foreach (['view', 'read', 'send', 'manage', 'delete'] as $action) {
            DB::table('permissions')->insertOrIgnore(['name' => ucfirst($action).' mail', 'slug' => "mail.{$action}", 'module' => 'mail', 'action' => $action, 'created_at' => now(), 'updated_at' => now()]);
        }
        $other->permissions()->attach(Permission::query()->where('slug', 'mail.read')->value('id'));

        $migration = require database_path('migrations/2026_10_24_100300_replace_mail_permissions.php');
        $migration->up();
        $migration->up();

        $this->assertSame(['mail.access', 'mail.manage_access', 'mail.manage_all'], Permission::query()->where('module', 'mail')->orderBy('slug')->pluck('slug')->all());
        $super = Role::query()->where('slug', 'super_admin')->firstOrFail();
        $this->assertSame(3, $super->permissions()->where('module', 'mail')->count());
        $this->assertSame(0, $other->permissions()->count(), 'a retired permission is not turned into Mail Center access');
        foreach (Role::query()->where('slug', '!=', 'super_admin')->get() as $role) {
            $this->assertSame(0, $role->permissions()->where('module', 'mail')->count(), "{$role->slug} received a mail permission");
        }
    }

    public function test_the_permission_definitions_and_the_migration_agree(): void
    {
        $this->assertSame(['access', 'manage_all', 'manage_access'], Permission::actionsFor('mail'));
    }
}
