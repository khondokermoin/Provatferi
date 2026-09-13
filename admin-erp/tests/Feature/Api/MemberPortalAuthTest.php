<?php

namespace Tests\Feature\Api;

use App\Models\Member;
use App\Models\User;
use App\Notifications\MemberSetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * §12: member-portal authentication — a Sanctum guard shared polymorphically
 * with the ERP admin API, so the real security boundary under test here is
 * EnsureMemberAuthenticated, not a separate guard config.
 */
class MemberPortalAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_active_member_can_log_in_and_receive_a_token(): void
    {
        $member = Member::factory()->active()->create();

        $response = $this->postJson('/api/v1/member/auth/login', [
            'email' => $member->email, 'password' => 'password',
        ])->assertOk();

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_an_incorrect_password_is_rejected(): void
    {
        $member = Member::factory()->active()->create();

        $this->postJson('/api/v1/member/auth/login', [
            'email' => $member->email, 'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_a_pending_member_cannot_log_in_even_with_the_correct_password(): void
    {
        $member = Member::factory()->create(['status' => 'pending']);

        $this->postJson('/api/v1/member/auth/login', [
            'email' => $member->email, 'password' => 'password',
        ])->assertUnprocessable();
    }

    public function test_a_logged_in_member_can_reach_the_dashboard_endpoint(): void
    {
        $member = Member::factory()->active()->create();
        $token = $member->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/member/me')
            ->assertOk()
            ->assertJsonPath('data.profile.email', $member->email)
            ->assertJsonStructure(['data' => ['profile', 'memberships', 'season_history', 'payments', 'library']]);
    }

    public function test_an_erp_admin_token_cannot_reach_the_member_dashboard(): void
    {
        // The core §12 boundary: Sanctum resolves both models' tokens
        // through the same guard, so the separation has to be enforced by
        // EnsureMemberAuthenticated explicitly, not by guard config alone.
        $admin = User::factory()->create();
        $token = $admin->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/member/me')
            ->assertForbidden();
    }

    public function test_logging_out_revokes_the_token(): void
    {
        // Asserted against the database row rather than a second simulated
        // request with the same (now-revoked) header: Laravel's Sanctum
        // RequestGuard caches its resolved user for the lifetime of the
        // guard instance, which the test HTTP client can reuse across two
        // calls within one test method — a second request here would
        // silently reuse the first request's cached auth instead of
        // re-validating the token, and pass for the wrong reason.
        $member = Member::factory()->active()->create();
        $plainToken = $member->createToken('test')->plainTextToken;
        $tokenId = explode('|', $plainToken, 2)[0];

        $this->withHeader('Authorization', "Bearer {$plainToken}")
            ->postJson('/api/v1/member/auth/logout')
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_a_new_member_can_set_a_password_via_the_forgot_password_flow_and_then_log_in(): void
    {
        Notification::fake();
        $member = Member::factory()->active()->create();

        $this->postJson('/api/v1/member/auth/password/email', ['email' => $member->email])->assertOk();

        Notification::assertSentTo($member, MemberSetPasswordNotification::class);

        // The plaintext token isn't recoverable from the dispatched
        // notification without inspecting its private URL — generate one
        // through the same broker to exercise the reset endpoint itself.
        $token = Password::broker('members')->createToken($member);

        $this->postJson('/api/v1/member/auth/password/reset', [
            'token' => $token, 'email' => $member->email,
            'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->postJson('/api/v1/member/auth/login', [
            'email' => $member->email, 'password' => 'new-password-123',
        ])->assertOk();
    }

    public function test_an_invalid_reset_token_is_rejected(): void
    {
        $member = Member::factory()->active()->create();

        $this->postJson('/api/v1/member/auth/password/reset', [
            'token' => 'not-a-real-token', 'email' => $member->email,
            'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ])->assertUnprocessable();
    }

    public function test_forgot_password_gives_the_same_response_for_an_unknown_email(): void
    {
        // Enumeration-safe — the endpoint's own contract, asserted directly.
        $this->postJson('/api/v1/member/auth/password/email', ['email' => 'nobody@example.com'])
            ->assertOk();
    }
}
