<?php

namespace Tests\Feature\Admin;

use App\Models\OrganizationalUnit;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The organisation works in Bangla, so unit names are almost always Bangla.
 * This guards the whole round-trip: form submit -> database -> rendered page.
 */
class BengaliContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_bengali_unit_name_survives_create_and_render(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('slug', 'super_admin')->firstOrFail());

        $name = 'চান্দিনা উপজেলা শাখা';

        $this->actingAs($user)->post(route('admin.organization.units.store'), [
            'name' => $name,
            'unit_type' => 'upazila',
            'status' => 'active',
            'sort_order' => 3,
        ])->assertRedirect();

        $unit = OrganizationalUnit::query()->firstOrFail();

        $this->assertSame($name, $unit->name);
        $this->assertTrue(mb_check_encoding($unit->name, 'UTF-8'));

        // assertSee with escape=false, since Blade leaves these code points as-is.
        $this->actingAs($user)->get(route('admin.organization.units.show', $unit))
            ->assertOk()
            ->assertSee($name, false);

        $this->actingAs($user)->get(route('admin.organization.units.index'))
            ->assertOk()
            ->assertSee($name, false);
    }
}
