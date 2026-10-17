<?php

namespace Tests\Feature\Mfa;

use App\Http\Controllers\Admin\AccountSecurityController;
use App\Http\Controllers\Auth\MfaChallengeController;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\Mfa\MfaPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;

/**
 * O (no unauthorised reset), P (CSRF), J (a password reset leaves two-step on), and the break-glass runbook's command
 * (docs/MFA_SECURITY.md, "Recovery when everything is lost").
 */
class MfaResetAndAuthorizationTest extends MfaTestCase
{
    // O — there is nothing in the web panel or the API that resets someone else's two-step verification.
    public function test_no_route_can_reach_another_accounts_two_step_verification(): void
    {
        $mfaRoutes = 0;
        foreach (Route::getRoutes() as $route) {
            $action = (string) $route->getActionName();
            if (str_starts_with($action, AccountSecurityController::class) || str_starts_with($action, MfaChallengeController::class)) {
                $mfaRoutes++;
                $this->assertSame([], $route->parameterNames(), "{$route->uri()} takes a parameter: two-step routes act on the signed-in account only.");
            }
            $this->assertDoesNotMatchRegularExpression('/(mfa|two-factor|2fa).*reset|reset.*(mfa|two-factor|2fa)/i', $route->uri(), 'A reset endpoint exists: the reset is console-only.');
        }
        $this->assertGreaterThanOrEqual(14, $mfaRoutes);
    }

    public function test_a_forged_user_id_cannot_redirect_an_action_to_another_account(): void
    {
        $victim = $this->superAdmin();
        ['secret' => $victimSecret] = $this->enrol($victim);
        $attacker = $this->staff();
        ['secret' => $secret] = $this->enrol($attacker);
        $this->signInWithCode($attacker, $secret);

        $this->post(route('admin.account.security.disable'), ['user_id' => $victim->id, 'id' => $victim->id, 'email' => $victim->email,
            'password' => self::PASSWORD, 'code' => $this->codeFor($victimSecret)])->assertSessionHasErrors();
        $this->withRecentPassword()->post(route('admin.account.security.codes.regenerate'), ['user_id' => $victim->id]);

        $this->assertTrue($victim->fresh()->hasMfaEnabled());
        $this->assertSame(10, count($victim->fresh()->mfa_recovery_codes));
        $this->assertDatabaseMissing('security_events', ['user_id' => $victim->id, 'event' => SecurityEvent::MFA_RECOVERY_CODES_REGENERATED]);
    }

    public function test_an_admin_who_manages_users_cannot_clear_a_super_admins_two_step_through_the_user_form(): void
    {
        $owner = $this->superAdmin();
        $this->enrol($owner);
        $manager = $this->staffWithRole($this->roleWith(['users.view', 'users.update']));
        $before = DB::table('users')->where('id', $owner->id)->first();

        $this->actingAs($manager)->put(route('admin.users.update', $owner), [
            'name' => $owner->name, 'email' => $owner->email, 'status' => 'active',
            'roles' => $owner->roles->pluck('id')->all(),
            'mfa_secret' => null, 'mfa_confirmed_at' => null, 'mfa_recovery_codes' => null, 'mfa_enrollment_required_at' => null,
        ]);

        $after = DB::table('users')->where('id', $owner->id)->first();
        foreach (['mfa_secret', 'mfa_confirmed_at', 'mfa_recovery_codes', 'mfa_last_used_step'] as $column) {
            $this->assertSame($before->{$column}, $after->{$column}, "{$column} changed through the user form.");
        }
    }

    public function test_two_step_columns_are_not_mass_assignable(): void
    {
        $user = new User;
        foreach (['mfa_secret', 'mfa_pending_secret', 'mfa_pending_created_at', 'mfa_recovery_codes', 'mfa_confirmed_at', 'mfa_last_used_step', 'mfa_enrollment_required_at'] as $column) {
            $this->assertFalse($user->isFillable($column), "{$column} must not be fillable.");
        }
        $this->assertFalse((new \App\Models\Role)->isFillable('mfa_required'));

        $person = $this->staff();
        $this->actingAs($person)->patch(route('profile.update'), [
            'name' => $person->name, 'email' => $person->email, 'mfa_confirmed_at' => now()->toDateTimeString(), 'mfa_secret' => app(\App\Services\Mfa\TotpService::class)->generateSecret(),
        ]);
        $this->assertFalse($person->fresh()->hasMfaEnabled());
    }

    public function test_the_break_glass_reset_runs_only_from_the_console_and_forces_a_new_enrolment(): void
    {
        config(['session.driver' => 'database']);
        $person = $this->superAdmin();
        ['secret' => $secret, 'codes' => $codes] = $this->enrol($person);
        $person->createToken('api');
        DB::table('sessions')->insert([
            ['id' => str_repeat('a', 40), 'user_id' => $person->id, 'ip_address' => '203.0.113.5', 'user_agent' => 'x', 'payload' => 'x', 'last_activity' => now()->getTimestamp()],
            ['id' => str_repeat('b', 40), 'user_id' => $person->id, 'ip_address' => '203.0.113.6', 'user_agent' => 'x', 'payload' => 'x', 'last_activity' => now()->getTimestamp()],
        ]);
        $rememberBefore = $person->fresh()->remember_token;

        // Without --force: a report, nothing changes.
        $this->artisan('mfa:reset', ['email' => $person->email])->assertSuccessful()->doesntExpectOutputToContain($secret);
        $this->assertTrue($person->fresh()->hasMfaEnabled());

        // A reason is required.
        $this->artisan('mfa:reset', ['email' => $person->email, '--force' => true, '--reason' => 'lost'])->assertFailed();
        $this->assertTrue($person->fresh()->hasMfaEnabled());

        $this->artisan('mfa:reset', ['email' => $person->email, '--force' => true, '--reason' => 'Lost phone and codes; identity checked by phone call'])
            ->assertSuccessful()->doesntExpectOutputToContain($secret)->doesntExpectOutputToContain($codes[0]);

        $fresh = $person->fresh();
        $this->assertFalse($fresh->hasMfaEnabled());
        $this->assertNull($fresh->getRawOriginal('mfa_secret'));
        $this->assertNull($fresh->getRawOriginal('mfa_recovery_codes'));
        $this->assertNotNull($fresh->mfa_enrollment_required_at);
        $this->assertNotSame($rememberBefore, $fresh->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $person->id)->count());
        $this->assertSame(0, $person->tokens()->count());

        $event = SecurityEvent::query()->where('user_id', $person->id)->where('event', SecurityEvent::MFA_RESET)->firstOrFail();
        $this->assertSame('console', $event->actor_type);
        $this->assertNull($event->actor_id);
        $this->assertSame(2, $event->context['sessions_ended']);
        $this->assertStringContainsString('identity checked', $event->context['reason']);

        // The old secret and codes are dead; the account must enrol again before anything else.
        $this->assertTrue(MfaPolicy::mustEnrollNow($fresh));
        config(['session.driver' => 'array']);
    }

    public function test_after_a_reset_the_account_signs_in_to_the_set_up_page_only(): void
    {
        $person = $this->staff();
        ['secret' => $secret] = $this->enrol($person);
        $this->artisan('mfa:reset', ['email' => $person->email, '--force' => true, '--reason' => 'Lost phone and every recovery code'])->assertSuccessful();

        $this->passwordStep($person)->assertRedirect(route('dashboard', absolute: false));
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.account.security'));
        $this->get(route('admin.account.security'))->assertOk()->assertSee(__('admin.mfa.reason_reset'));
        $this->assertFalse(app(\App\Services\Mfa\MfaManager::class)->verifyCode($person->fresh(), $this->codeFor($secret)));
    }

    public function test_the_status_report_prints_no_secret(): void
    {
        $person = $this->staff();
        ['secret' => $secret, 'codes' => $codes] = $this->enrol($person);

        $this->artisan('mfa:status', ['email' => $person->email])->assertSuccessful()
            ->doesntExpectOutputToContain($secret)->doesntExpectOutputToContain($codes[0]);
    }

    // P — every two-step form needs the CSRF token.
    public function test_every_two_step_form_is_refused_without_its_csrf_token(): void
    {
        $user = $this->staff();
        $role = $this->roleWith(['activities.view']);
        $forms = [
            ['POST', route('mfa.challenge.verify')], ['POST', route('mfa.challenge.recovery')], ['POST', route('mfa.challenge.cancel')],
            ['POST', route('admin.account.security.enable')], ['POST', route('admin.account.security.restart')],
            ['POST', route('admin.account.security.codes.regenerate')], ['POST', route('admin.account.security.disable')],
            ['POST', route('admin.account.security.step-up.verify')], ['POST', route('admin.account.security.enforcement')],
            ['PATCH', route('admin.roles.mfa', $role)], ['POST', route('password.confirm.store')], ['POST', '/login'],
        ];

        // CSRF checks are skipped while running unit tests; outside "testing" they apply as in production.
        $this->app->detectEnvironment(fn () => 'local');
        try {
            foreach ($forms as [$method, $url]) {
                $this->actingAs($user)->call($method, $url, ['code' => '123456', 'password' => self::PASSWORD])->assertStatus(419);
            }
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_the_two_step_pages_render_their_csrf_token(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);
        $this->signInWithCode($user, $secret);

        foreach ([route('admin.account.security'), route('admin.account.security.step-up')] as $url) {
            $this->get($url)->assertOk()->assertSee('name="_token"', false);
        }
    }

    // J — a password reset changes the password, not the second step.
    public function test_a_password_reset_leaves_two_step_on_and_ends_every_session_and_token(): void
    {
        config(['session.driver' => 'database']);
        $person = $this->staff();
        ['secret' => $secret, 'codes' => $codes] = $this->enrol($person);
        $person->createToken('api');
        DB::table('sessions')->insert(['id' => str_repeat('c', 40), 'user_id' => $person->id, 'ip_address' => null, 'user_agent' => null, 'payload' => 'x', 'last_activity' => now()->getTimestamp()]);
        $hashesBefore = $person->fresh()->mfa_recovery_codes;

        $token = Password::createToken($person);
        $this->post(route('password.store'), [
            'token' => $token, 'email' => $person->email, 'password' => 'N3w-Str0ng-Passw0rd!', 'password_confirmation' => 'N3w-Str0ng-Passw0rd!',
        ])->assertRedirect(route('login'))->assertSessionHasNoErrors();

        $fresh = $person->fresh();
        $this->assertTrue($fresh->hasMfaEnabled(), 'A password reset must not turn two-step verification off.');
        $this->assertSame($hashesBefore, $fresh->mfa_recovery_codes, 'A password reset must not touch the recovery codes.');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $person->id)->count());
        $this->assertSame(0, $person->tokens()->count());
        $this->assertDatabaseHas('security_events', ['user_id' => $person->id, 'event' => SecurityEvent::SESSIONS_ENDED_AFTER_PASSWORD_RESET]);

        // The new password still leads to the challenge, never straight in.
        $this->passwordStep($person, password: 'N3w-Str0ng-Passw0rd!')->assertRedirect(route('mfa.challenge'));
        $this->assertGuest();
        $this->post(route('mfa.challenge.verify'), ['code' => $this->codeFor($secret)])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($person);
        config(['session.driver' => 'array']);
    }

    public function test_changing_ones_own_password_ends_the_other_sessions_but_keeps_this_one(): void
    {
        config(['session.driver' => 'database']);
        $person = $this->staff();
        DB::table('sessions')->insert(['id' => str_repeat('d', 40), 'user_id' => $person->id, 'ip_address' => null, 'user_agent' => null, 'payload' => 'x', 'last_activity' => now()->getTimestamp()]);

        $this->actingAs($person)->put(route('password.update'), [
            'current_password' => self::PASSWORD, 'password' => 'N3w-Str0ng-Passw0rd!', 'password_confirmation' => 'N3w-Str0ng-Passw0rd!',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('sessions')->where('id', str_repeat('d', 40))->count());
        $this->assertAuthenticatedAs($person);
        config(['session.driver' => 'array']);
    }
}
