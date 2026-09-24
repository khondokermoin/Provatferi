<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §MAIL-007: the show/hide toggle button (resources/views/components/
 * password-toggle-button.blade.php + public/js/provatferi-password-toggle.js)
 * must appear next to every password field, default to hidden, and carry
 * accessible markup. The actual click-to-reveal behavior is client-side JS
 * and isn't exercised here — this asserts the server-rendered contract the
 * JS depends on: type="button" (never submits the form), a real <svg> pair,
 * and Bengali+English aria-label/title, present from first render.
 */
class PasswordToggleMarkupTest extends TestCase
{
    use RefreshDatabase;

    private function assertHasPasswordToggle(string $html, int $expectedCount = 1): void
    {
        $this->assertSame(
            $expectedCount,
            substr_count($html, 'data-pf-password-toggle'),
            'expected '.$expectedCount.' password-toggle button(s)'
        );
        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('data-showing="false"', $html);
        $this->assertStringContainsString('aria-pressed="false"', $html);
        $this->assertStringContainsString('Show password', $html);
        $this->assertStringContainsString('পাসওয়ার্ড দেখান', $html);
        $this->assertStringContainsString('pf-icon-eye', $html);
        $this->assertStringContainsString('pf-icon-eye-off', $html);
        // The button must never itself be a submit control.
        $this->assertStringNotContainsString('type="submit" class="pf-password-toggle"', $html);
    }

    public function test_login_page_has_one_password_toggle_defaulting_to_hidden(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertHasPasswordToggle($html, 1);
        $this->assertStringContainsString('type="password" id="password" name="password"', $html);
    }

    public function test_reset_password_page_has_two_password_toggles(): void
    {
        $user = User::factory()->create();
        $token = \Illuminate\Support\Facades\Password::createToken($user);

        $html = $this->get('/reset-password/'.$token.'?email='.urlencode($user->email))
            ->assertOk()->getContent();

        $this->assertHasPasswordToggle($html, 2);
    }

    public function test_confirm_password_page_has_one_password_toggle(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'status' => 'active']);
        $user->roles()->attach(Role::query()->where('slug', 'super_admin')->firstOrFail());

        $html = $this->actingAs($user)->get('/confirm-password')->assertOk()->getContent();

        $this->assertHasPasswordToggle($html, 1);
    }

    public function test_profile_page_has_password_toggles_on_change_password_and_delete_account(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'status' => 'active']);
        $user->roles()->attach(Role::query()->where('slug', 'super_admin')->firstOrFail());

        $html = $this->actingAs($user)->get('/profile')->assertOk()->getContent();

        // 3 on the change-password form (current, new, confirm) + 1 on the
        // delete-account confirmation modal = 4.
        $this->assertHasPasswordToggle($html, 4);
    }
}
