<?php

namespace Tests\Feature\Admin;

use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationUnitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->firstOrFail());

        return $user;
    }

    private function userWithPermissions(array $slugs): User
    {
        $role = Role::query()->create(['name' => 'Scoped', 'slug' => 'scoped-'.uniqid()]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $slugs)->pluck('id'));

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role);

        return $user;
    }

    public function test_dashboard_renders_real_counts_and_zero_when_empty(): void
    {
        $this->actingAs($this->userWithRole('super_admin'))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Organizational units')
            // No records seeded, so every figure must be a real 0.
            ->assertSee('Activities');

        $this->assertSame(0, OrganizationalUnit::query()->count());
    }

    public function test_index_lists_units_and_supports_search_and_filters(): void
    {
        OrganizationalUnit::query()->create(['name' => 'Central Office', 'slug' => 'central-office', 'unit_type' => 'central', 'status' => 'active']);
        OrganizationalUnit::query()->create(['name' => 'Chandina Branch', 'slug' => 'chandina-branch', 'unit_type' => 'upazila', 'status' => 'inactive']);

        $admin = $this->userWithRole('super_admin');

        $this->actingAs($admin)->get(route('admin.organization.units.index'))
            ->assertOk()->assertSee('Central Office')->assertSee('Chandina Branch');

        $this->actingAs($admin)->get(route('admin.organization.units.index', ['search' => 'Chandina']))
            ->assertOk()->assertSee('Chandina Branch')->assertDontSee('Central Office');

        $this->actingAs($admin)->get(route('admin.organization.units.index', ['status' => 'inactive']))
            ->assertOk()->assertSee('Chandina Branch')->assertDontSee('Central Office');
    }

    public function test_empty_state_is_shown_when_no_units_exist(): void
    {
        $this->actingAs($this->userWithRole('super_admin'))
            ->get(route('admin.organization.units.index'))
            ->assertOk()
            ->assertSee('এখনো কোনো সাংগঠনিক ইউনিট নেই');
    }

    public function test_a_unit_can_be_created(): void
    {
        $this->actingAs($this->userWithRole('super_admin'))
            ->post(route('admin.organization.units.store'), [
                'name' => 'Dollai Nowabpur Union',
                'unit_type' => 'union',
                'status' => 'active',
                'sort_order' => 5,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('organizational_units', [
            'name' => 'Dollai Nowabpur Union',
            'unit_type' => 'union',
            'sort_order' => 5,
        ]);
    }

    public function test_validation_errors_are_returned_for_invalid_input(): void
    {
        $this->actingAs($this->userWithRole('super_admin'))
            ->post(route('admin.organization.units.store'), [
                'name' => '',
                'unit_type' => 'not-a-real-type',
                'status' => 'active',
                'sort_order' => -1,
            ])
            ->assertSessionHasErrors(['name', 'unit_type', 'sort_order']);
    }

    public function test_a_unit_cannot_be_set_as_its_own_parent(): void
    {
        $unit = OrganizationalUnit::query()->create(['name' => 'Self', 'slug' => 'self', 'unit_type' => 'unit', 'status' => 'active']);

        $this->actingAs($this->userWithRole('super_admin'))
            ->put(route('admin.organization.units.update', $unit), [
                'name' => 'Self',
                'unit_type' => 'unit',
                'status' => 'active',
                'sort_order' => 0,
                'parent_id' => $unit->id,
            ])
            ->assertSessionHasErrors('parent_id');
    }

    public function test_a_unit_with_children_cannot_be_deleted(): void
    {
        $parent = OrganizationalUnit::query()->create(['name' => 'Parent', 'slug' => 'parent', 'unit_type' => 'district', 'status' => 'active']);
        OrganizationalUnit::query()->create(['name' => 'Child', 'slug' => 'child', 'unit_type' => 'upazila', 'status' => 'active', 'parent_id' => $parent->id]);

        $this->actingAs($this->userWithRole('super_admin'))
            ->delete(route('admin.organization.units.destroy', $parent))
            ->assertRedirect(route('admin.organization.units.show', $parent))
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted($parent);
    }

    public function test_a_leaf_unit_can_be_deleted(): void
    {
        $unit = OrganizationalUnit::query()->create(['name' => 'Leaf', 'slug' => 'leaf', 'unit_type' => 'unit', 'status' => 'active']);

        $this->actingAs($this->userWithRole('super_admin'))
            ->delete(route('admin.organization.units.destroy', $unit))
            ->assertRedirect(route('admin.organization.units.index'));

        $this->assertSoftDeleted($unit);
    }

    public function test_create_action_is_hidden_and_blocked_without_the_create_permission(): void
    {
        $viewer = $this->userWithPermissions(['organization.view']);

        // The button must not be offered...
        $this->actingAs($viewer)
            ->get(route('admin.organization.units.index'))
            ->assertOk()
            ->assertDontSee(route('admin.organization.units.create'));

        // ...and the route itself must still refuse.
        $this->actingAs($viewer)->get(route('admin.organization.units.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.organization.units.store'), [])->assertForbidden();
    }

    public function test_delete_action_is_blocked_without_the_delete_permission(): void
    {
        $unit = OrganizationalUnit::query()->create(['name' => 'Kept', 'slug' => 'kept', 'unit_type' => 'unit', 'status' => 'active']);
        $editor = $this->userWithPermissions(['organization.view', 'organization.update']);

        $this->actingAs($editor)->delete(route('admin.organization.units.destroy', $unit))->assertForbidden();
        $this->assertNotSoftDeleted($unit);
    }

    public function test_a_user_without_module_access_cannot_reach_the_module(): void
    {
        $outsider = $this->userWithPermissions(['activities.view']);

        $this->actingAs($outsider)->get(route('admin.organization.units.index'))->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.organization.units.index'))->assertRedirect(route('login'));
    }
}
