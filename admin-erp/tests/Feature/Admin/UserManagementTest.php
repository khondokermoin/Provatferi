<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use App\Support\SuperAdminGuard;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

class UserManagementTest extends AdminTestCase
{
    public function test_index_lists_and_filters_users(): void
    {
        $admin = $this->superAdmin();
        User::factory()->create(['name' => 'Nazmul Islam', 'status' => 'active']);
        User::factory()->create(['name' => 'Rokeya Begum', 'status' => 'inactive']);

        $this->actingAs($admin)->get(route('admin.users.index'))
            ->assertOk()->assertSee('Nazmul Islam')->assertSee('Rokeya Begum');

        $this->actingAs($admin)->get(route('admin.users.index', ['search' => 'Rokeya']))
            ->assertOk()->assertSee('Rokeya Begum')->assertDontSee('Nazmul Islam');

        $this->actingAs($admin)->get(route('admin.users.index', ['status' => 'inactive']))
            ->assertOk()->assertSee('Rokeya Begum')->assertDontSee('Nazmul Islam');
    }

    public function test_a_user_can_be_created_with_roles_and_a_hashed_password(): void
    {
        $role = Role::query()->where('slug', 'membership_admin')->firstOrFail();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.users.store'), [
                'name' => 'New Admin',
                'email' => 'new-admin@example.test',
                'status' => 'active',
                'roles' => [$role->id],
                'password' => 'Str0ng-Passw0rd!',
                'password_confirmation' => 'Str0ng-Passw0rd!',
            ])->assertRedirect();

        $user = User::query()->where('email', 'new-admin@example.test')->firstOrFail();
        $this->assertTrue($user->roles->contains($role));
        $this->assertNotSame('Str0ng-Passw0rd!', $user->password);
        $this->assertTrue(Hash::check('Str0ng-Passw0rd!', $user->password));
    }

    public function test_password_is_never_rendered_in_the_ui(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create();

        $this->actingAs($admin)->get(route('admin.users.show', $target))
            ->assertOk()->assertDontSee($target->password);

        $this->actingAs($admin)->get(route('admin.users.edit', $target))
            ->assertOk()
            ->assertDontSee($target->password)
            ->assertDontSee('name="password"', false);
    }

    public function test_creation_validates_input(): void
    {
        $existing = User::factory()->create();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.users.store'), [
                'name' => '',
                'email' => $existing->email,
                'status' => 'active',
                'password' => 'short',
                'password_confirmation' => 'mismatch',
            ])->assertSessionHasErrors(['name', 'email', 'password']);
    }

    public function test_status_can_be_toggled(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create(['status' => 'active']);

        $this->actingAs($admin)->patch(route('admin.users.status', $target))->assertRedirect();
        $this->assertSame('inactive', $target->fresh()->status);

        $this->actingAs($admin)->patch(route('admin.users.status', $target))->assertRedirect();
        $this->assertSame('active', $target->fresh()->status);
    }

    public function test_password_reset_link_is_sent_rather_than_set_directly(): void
    {
        Notification::fake();
        $target = User::factory()->create();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.users.password-reset', $target))
            ->assertRedirect();

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $target->email]);
    }

    /* ---------- last Super Admin safeguards ---------- */

    public function test_the_last_super_admin_cannot_be_deactivated(): void
    {
        $admin = $this->superAdmin();
        $this->assertSame(1, SuperAdminGuard::usableCount());

        $this->actingAs($admin)->patch(route('admin.users.status', $admin))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame('active', $admin->fresh()->status);
    }

    public function test_the_last_super_admin_cannot_lose_the_role(): void
    {
        $admin = $this->superAdmin();
        $other = Role::query()->where('slug', 'member')->firstOrFail();

        $this->actingAs($admin)->put(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'status' => 'active',
            'roles' => [$other->id],
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertTrue($admin->fresh()->hasRole('super_admin'));
    }

    public function test_the_last_super_admin_cannot_be_deleted(): void
    {
        $admin = $this->superAdmin();
        $second = $this->superAdmin();

        // With two, deleting one is allowed.
        $this->actingAs($admin)->delete(route('admin.users.destroy', $second))->assertRedirect();
        $this->assertSoftDeleted($second);

        // The remaining one is now protected.
        $another = $this->superAdmin();
        $another->update(['status' => 'inactive']); // inactive does not count as usable
        $this->actingAs($admin)->delete(route('admin.users.destroy', $admin))
            ->assertRedirect()->assertSessionHas('error');
        $this->assertNotSoftDeleted($admin);
    }

    public function test_super_admin_role_can_be_removed_when_another_active_super_admin_exists(): void
    {
        $first = $this->superAdmin();
        $second = $this->superAdmin();
        $member = Role::query()->where('slug', 'member')->firstOrFail();

        $this->actingAs($first)->put(route('admin.users.update', $second), [
            'name' => $second->name,
            'email' => $second->email,
            'status' => 'active',
            'roles' => [$member->id],
        ])->assertRedirect();

        $this->assertFalse($second->fresh()->hasRole('super_admin'));
    }

    public function test_a_user_cannot_delete_their_own_account_here(): void
    {
        $admin = $this->superAdmin();
        $this->superAdmin(); // ensure not the last one

        $this->actingAs($admin)->delete(route('admin.users.destroy', $admin))
            ->assertRedirect()->assertSessionHas('error');
        $this->assertNotSoftDeleted($admin);
    }

    /* ---------- RBAC ---------- */

    public function test_rbac_blocks_and_hides_user_management(): void
    {
        $viewer = $this->userWith(['users.view']);

        $this->actingAs($viewer)->get(route('admin.users.index'))
            ->assertOk()->assertDontSee(route('admin.users.create'));

        $this->actingAs($viewer)->get(route('admin.users.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.users.store'), [])->assertForbidden();

        $outsider = $this->userWith(['organization.view']);
        $this->actingAs($outsider)->get(route('admin.users.index'))->assertForbidden();
    }
}
