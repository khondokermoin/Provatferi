<?php

namespace Tests\Feature\Admin;

use App\Models\Permission;
use App\Models\Role;

/**
 * Regression coverage for the 2026-09-08 edit -> update rename. Guards against
 * the old slug quietly coming back via a seeder tweak, a copy-pasted route, or
 * a Blade @can that still references the page name instead of the operation.
 */
class PermissionNormalizationTest extends AdminTestCase
{
    public function test_no_edit_suffixed_permission_exists_anywhere(): void
    {
        $this->assertSame(0, Permission::query()->where('slug', 'like', '%.edit')->count());
        $this->assertDatabaseMissing('permissions', ['slug' => 'organization.edit']);
    }

    public function test_every_permission_slug_uses_a_sanctioned_action(): void
    {
        foreach (Permission::query()->get(['module', 'action']) as $permission) {
            $this->assertContains(
                $permission->action,
                Permission::actionsFor($permission->module),
                "Permission action '{$permission->action}' is not sanctioned for '{$permission->module}' — CRUD semantics stay view/create/update/delete/approve unless Permission::MODULE_ACTIONS declares a genuinely distinct operation for that module.",
            );
        }
    }

    public function test_workflow_actions_exist_only_where_a_module_declares_them(): void
    {
        $this->assertDatabaseHas('permissions', ['slug' => 'notices.publish']);
        $this->assertDatabaseHas('permissions', ['slug' => 'notices.archive']);
        $this->assertDatabaseMissing('permissions', ['slug' => 'recruitment.publish']);
        $this->assertDatabaseMissing('permissions', ['slug' => 'notices.approve']);
    }

    public function test_organization_update_permission_exists_and_grants_the_edit_ui(): void
    {
        $this->assertDatabaseHas('permissions', ['slug' => 'organization.update']);

        $unit = \App\Models\OrganizationalUnit::query()->create([
            'name' => 'Test Unit', 'slug' => 'test-unit', 'unit_type' => 'unit', 'status' => 'active',
        ]);

        // Only organization.update — the old organization.edit key must not be required.
        $editor = $this->userWith(['organization.view', 'organization.update']);

        $this->actingAs($editor)->get(route('admin.organization.units.edit', $unit))->assertOk();
        $this->actingAs($editor)->put(route('admin.organization.units.update', $unit), [
            'name' => 'Renamed Unit', 'unit_type' => 'unit', 'status' => 'active', 'sort_order' => 0,
        ])->assertRedirect();

        $this->assertDatabaseHas('organizational_units', ['id' => $unit->id, 'name' => 'Renamed Unit']);
    }

    public function test_a_user_with_only_the_old_slug_name_would_be_denied(): void
    {
        // Simulates what would happen if a stale 'organization.edit' permission
        // still existed somewhere (e.g. a leftover row from before the rename):
        // it must NOT satisfy the organization.update gate.
        $stale = Permission::query()->create([
            'name' => 'Edit organization (stale)', 'slug' => 'organization.edit',
            'module' => 'organization', 'action' => 'edit',
        ]);
        $role = Role::query()->create(['name' => 'Stale Role', 'slug' => 'stale-role']);
        $role->permissions()->sync([$stale->id]);
        $user = \App\Models\User::factory()->create(['email_verified_at' => now(), 'status' => 'active']);
        $user->roles()->attach($role);

        $unit = \App\Models\OrganizationalUnit::query()->create([
            'name' => 'Guarded Unit', 'slug' => 'guarded-unit', 'unit_type' => 'unit', 'status' => 'active',
        ]);

        $this->actingAs($user)->get(route('admin.organization.units.edit', $unit))->assertForbidden();
    }

    public function test_permissions_screen_shows_update_not_edit(): void
    {
        $this->actingAs($this->superAdmin())->get(route('admin.permissions.index'))
            ->assertOk()
            ->assertSee('organization.update')
            ->assertDontSee('organization.edit');
    }

    public function test_rbac_ui_visibility_still_hides_actions_without_the_renamed_permission(): void
    {
        $unit = \App\Models\OrganizationalUnit::query()->create([
            'name' => 'Visible Unit', 'slug' => 'visible-unit', 'unit_type' => 'unit', 'status' => 'active',
        ]);
        $viewer = $this->userWith(['organization.view']);

        $this->actingAs($viewer)->get(route('admin.organization.units.show', $unit))
            ->assertOk()
            ->assertDontSee(route('admin.organization.units.edit', $unit));
    }
}
