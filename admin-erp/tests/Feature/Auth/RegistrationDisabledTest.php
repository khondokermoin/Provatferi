<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SYSTEM-010: this is an internal, invite-only ERP — the only intended
 * account-creation path is the seeded Super Admin (AdminUserSeeder). Public
 * self-registration (Breeze's default scaffolding) must stay unreachable.
 * Replaces the old RegistrationTest, which asserted the opposite.
 */
class RegistrationDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_is_not_reachable(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(404);
    }

    public function test_registration_cannot_be_submitted(): void
    {
        $countBefore = User::count();

        $response = $this->post('/register', [
            'name' => 'Attempted User',
            'email' => 'attempted@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(404);
        $this->assertGuest();
        $this->assertSame($countBefore, User::count());
        $this->assertDatabaseMissing('users', ['email' => 'attempted@example.com']);
    }

    public function test_register_route_name_no_longer_exists(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('register'));
    }
}
