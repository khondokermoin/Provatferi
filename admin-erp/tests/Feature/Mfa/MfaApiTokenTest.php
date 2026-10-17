<?php

namespace Tests\Feature\Mfa;

use App\Models\SecurityEvent;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Staff API tokens (docs/MFA_SECURITY.md, "API tokens"): POST /api/v1/auth/login follows the web sign-in's rules, so a
 * password alone can never buy a bearer token for an account with two-step verification.
 */
class MfaApiTokenTest extends MfaTestCase
{
    public function test_an_account_without_two_step_still_gets_a_token_with_its_password(): void
    {
        $user = $this->staff();

        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', $user->email);
    }

    public function test_an_account_with_two_step_needs_a_current_code_for_a_token(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);
        $credentials = ['email' => $user->email, 'password' => self::PASSWORD];

        $this->postJson('/api/v1/auth/login', $credentials)->assertUnauthorized()->assertJsonPath('mfa_required', true)->assertJsonMissingPath('data');
        $this->postJson('/api/v1/auth/login', $credentials + ['code' => '000000'])->assertUnprocessable()->assertJsonMissingPath('data');
        $this->postJson('/api/v1/auth/login', $credentials + ['code' => $this->codeFor($secret, -2)])->assertUnprocessable();
        $this->assertSame(0, $user->tokens()->count());

        $code = $this->codeFor($secret);
        $token = $this->postJson('/api/v1/auth/login', $credentials + ['code' => $code])->assertOk()->json('data.token');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        // The same code cannot buy a second token.
        $this->postJson('/api/v1/auth/login', $credentials + ['code' => $code])->assertUnprocessable();
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_a_wrong_password_with_a_right_code_is_still_a_wrong_password(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong', 'code' => $this->codeFor($secret)])
            ->assertUnprocessable()->assertJsonValidationErrors('email')->assertJsonMissingPath('mfa_required');
    }

    public function test_an_account_that_must_enrol_gets_no_token_until_it_has(): void
    {
        $user = $this->staffWithRole($this->roleWith(['organization.view'], mfaRequired: true));

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertForbidden()->assertJsonMissingPath('data');
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_an_inactive_account_gets_the_same_answer_as_a_wrong_password(): void
    {
        $user = $this->staff(['status' => 'inactive']);
        $wrong = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable()->json('errors.email.0');
        $inactive = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertUnprocessable()->json('errors.email.0');

        $this->assertSame($wrong, $inactive);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_turning_two_step_on_revokes_every_older_token(): void
    {
        $user = $this->staff();
        $old = $user->createToken('api')->plainTextToken;
        $this->withToken($old)->getJson('/api/v1/auth/me')->assertOk();

        $this->enrol($user);

        $this->assertSame(0, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($old)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $event = SecurityEvent::query()->where('user_id', $user->id)->where('event', SecurityEvent::MFA_ENABLED)->firstOrFail();
        $this->assertSame(1, $event->context['api_tokens_revoked']);
    }

    public function test_a_token_older_than_the_activation_is_refused_even_if_it_survived(): void
    {
        $user = $this->staff();
        $old = $user->createToken('api')->plainTextToken;
        $this->travel(5)->minutes();
        // Turned on without going through MfaManager (as a restored backup might): the token is still refused.
        $user->forceFill(['mfa_secret' => app(\App\Services\Mfa\TotpService::class)->generateSecret(), 'mfa_confirmed_at' => now(), 'mfa_recovery_codes' => []])->save();

        $this->withToken($old)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertSame(0, PersonalAccessToken::query()->count(), 'The outdated token is deleted.');
    }

    public function test_an_account_that_becomes_required_cannot_keep_using_an_old_token(): void
    {
        $role = $this->roleWith(['organization.view']);
        $user = $this->staffWithRole($role);
        $token = $user->createToken('api')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/admin/organization-units')->assertOk();

        $role->forceFill(['mfa_required' => true])->save();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/admin/organization-units')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
    }

    public function test_api_code_guesses_share_the_accounts_allowance(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);
        $credentials = ['email' => $user->email, 'password' => self::PASSWORD];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', $credentials + ['code' => '00000'.$i])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', $credentials + ['code' => $this->codeFor($secret)])->assertStatus(429);

        // The web challenge is paused too: the guesses were counted against the account, wherever they came from.
        $this->passwordStep($user);
        $this->post(route('mfa.challenge.verify'), ['code' => $this->codeFor($secret)])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_the_token_responses_carry_no_two_step_material(): void
    {
        $user = $this->staff();
        ['secret' => $secret, 'codes' => $codes] = $this->enrol($user);

        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD, 'code' => $this->codeFor($secret)])->assertOk();
        $me = $this->withToken($login->json('data.token'))->getJson('/api/v1/auth/me')->assertOk();

        foreach ([$login, $me] as $response) {
            $body = (string) $response->getContent();
            foreach (['mfa_secret', 'mfa_pending_secret', 'mfa_recovery_codes', 'mfa_last_used_step', 'mfa_pending_created_at', 'password', 'remember_token', $secret] as $needle) {
                $this->assertStringNotContainsString($needle, $body);
            }
            foreach ($codes as $code) {
                $this->assertStringNotContainsString($code, $body);
            }
        }
    }
}
