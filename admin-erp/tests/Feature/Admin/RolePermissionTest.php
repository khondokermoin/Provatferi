<?php

namespace Tests\Feature\Admin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

class RolePermissionTest extends AdminTestCase
{
    public function test_roles_index_lists_seeded_roles(): void
    {
        $this->actingAs($this->superAdmin())->get(route('admin.roles.index'))
            ->assertOk()->assertSee('Super Admin')->assertSee('Membership Admin');
    }

    public function test_a_custom_role_can_be_created_with_permissions(): void
    {
        $ids = Permission::query()->whereIn('slug', ['activities.view', 'activities.create'])->pluck('id')->all();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.roles.store'), [
                'name' => 'Activity Coordinator',
                'description' => 'Runs activity records.',
                'permissions' => $ids,
            ])->assertRedirect();

        $role = Role::query()->where('name', 'Activity Coordinator')->firstOrFail();
        $this->assertFalse($role->is_system_role);
        $this->assertEqualsCanonicalizing($ids, $role->permissions->pluck('id')->all());
    }

    public function test_role_name_must_be_unique(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.roles.store'), ['name' => 'Super Admin'])
            ->assertSessionHasErrors('name');
    }

    public function test_super_admin_always_keeps_every_permission(): void
    {
        $superAdmin = Role::query()->where('slug', 'super_admin')->firstOrFail();

        // Try to strip it down to a single permission.
        $this->actingAs($this->superAdmin())
            ->put(route('admin.roles.update', $superAdmin), [
                'name' => 'Super Admin',
                'permissions' => [Permission::query()->value('id')],
            ])->assertRedirect()->assertSessionHas('warning');

        $this->assertSame(
            Permission::query()->count(),
            $superAdmin->fresh()->permissions()->count(),
        );
    }

    public function test_system_roles_cannot_be_deleted(): void
    {
        $role = Role::query()->where('slug', 'membership_admin')->firstOrFail();

        $this->actingAs($this->superAdmin())->delete(route('admin.roles.destroy', $role))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_a_role_in_use_cannot_be_deleted(): void
    {
        $role = Role::query()->create(['name' => 'Temp Role', 'slug' => 'temp-role']);
        User::factory()->create()->roles()->attach($role);

        $this->actingAs($this->superAdmin())->delete(route('admin.roles.destroy', $role))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_an_unused_custom_role_can_be_deleted(): void
    {
        $role = Role::query()->create(['name' => 'Unused Role', 'slug' => 'unused-role']);

        $this->actingAs($this->superAdmin())->delete(route('admin.roles.destroy', $role))
            ->assertRedirect();

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_permissions_screen_is_read_only_and_grouped_by_module(): void
    {
        $this->actingAs($this->superAdmin())->get(route('admin.permissions.index'))
            ->assertOk()
            ->assertSee('organization.view')
            ->assertSee('Organization')
            ->assertSee('Recruitment');

        // No create/store route should exist for permissions.
        $this->assertFalse(app('router')->has('admin.permissions.store'));
        $this->assertFalse(app('router')->has('admin.permissions.create'));
    }

    public function test_rbac_guards_role_administration(): void
    {
        $viewer = $this->userWith(['users.view']);
        $this->actingAs($viewer)->get(route('admin.roles.index'))->assertOk();
        $this->actingAs($viewer)->get(route('admin.roles.create'))->assertForbidden();

        $outsider = $this->userWith(['activities.view']);
        $this->actingAs($outsider)->get(route('admin.roles.index'))->assertForbidden();
        $this->actingAs($outsider)->get(route('admin.permissions.index'))->assertForbidden();
    }
}
