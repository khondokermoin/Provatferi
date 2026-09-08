<?php

namespace Tests\Feature\Admin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class AdminTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function superAdmin(): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'status' => 'active']);
        $user->roles()->attach(Role::query()->where('slug', 'super_admin')->firstOrFail());

        return $user;
    }

    /** @param array<int, string> $slugs */
    protected function userWith(array $slugs): User
    {
        $role = Role::query()->create(['name' => 'Scoped '.uniqid(), 'slug' => 'scoped-'.uniqid()]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $slugs)->pluck('id'));

        $user = User::factory()->create(['email_verified_at' => now(), 'status' => 'active']);
        $user->roles()->attach($role);

        return $user;
    }
}
