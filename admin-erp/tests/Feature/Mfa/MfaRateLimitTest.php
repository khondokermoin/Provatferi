<?php

namespace Tests\Feature\Mfa;

use App\Models\SecurityEvent;
use Illuminate\Support\Facades\Auth;

/**
 * Q — rate limiting (docs/MFA_SECURITY.md, "Rate limiting"): failures only, per account, per session and per address with
 * a far larger allowance for the address (shared NAT), and every pause ends on its own.
 */
class MfaRateLimitTest extends MfaTestCase
{
    public function test_five_wrong_codes_pause_the_challenge_and_are_audited_once(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);
        $this->passwordStep($user);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('mfa.challenge.verify'), ['code' => '00000'.$i])->assertSessionHasErrors('code');
        }
        // Paused: even the right code is not checked now, and nothing is signed in.
        $this->post(route('mfa.challenge.verify'), ['code' => $this->codeFor($secret)])->assertSessionHasErrors('code');
        $this->assertStringContainsString(
            trim(explode(':minutes', trans('admin.mfa.throttled', [], 'bn'))[0]),
            (string) session('errors')->first('code'),
        );
        $this->assertGuest();
        $this->assertSame(1, SecurityEvent::query()->where('user_id', $user->id)->where('event', SecurityEvent::MFA_CHALLENGE_LOCKED)->count());

        // Temporary: five minutes later the right code works.
        $this->travel(301)->seconds();
        $this->post(route('mfa.challenge.verify'), ['code' => $this->codeFor($secret)])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_pause_follows_the_account_into_a_new_browser(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);
        $this->passwordStep($user);
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('mfa.challenge.verify'), ['code' => '00000'.$i]);
        }

        app('session')->driver()->flush();
        app('session')->driver()->setId(null);
        Auth::forgetGuards();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20']);
        $this->passwordStep($user)->assertRedirect(route('mfa.challenge'));
        $this->post(route('mfa.challenge.verify'), ['code' => $this->codeFor($secret)])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_one_persons_mistakes_do_not_lock_out_others_behind_the_same_address(): void
    {
        $first = $this->staff();
        $this->enrol($first);
        $second = $this->staff();
        ['secret' => $secondSecret] = $this->enrol($second);

        $this->passwordStep($first);
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('mfa.challenge.verify'), ['code' => '00000'.$i]);
        }

        app('session')->driver()->flush();
        app('session')->driver()->setId(null);
        $this->passwordStep($second);
        $this->post(route('mfa.challenge.verify'), ['code' => $this->codeFor($secondSecret)])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($second);
    }

    public function test_wrong_recovery_codes_are_paused(): void
    {
        $user = $this->staff();
        ['codes' => $codes] = $this->enrol($user);
        $this->passwordStep($user);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('mfa.challenge.recovery'), ['recovery_code' => 'ABCDE-FGHJ'.$i + 2])->assertSessionHasErrors('recovery_code');
        }
        $this->post(route('mfa.challenge.recovery'), ['recovery_code' => $codes[0]])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
        $this->assertSame(10, count($user->fresh()->mfa_recovery_codes), 'A paused attempt must not use up a code.');
    }

    public function test_enrolment_codes_are_paused(): void
    {
        $user = $this->staff();
        $this->actingAs($user)->withRecentPassword()->get(route('admin.account.security.setup'));
        $secret = $user->fresh()->mfa_pending_secret;

        for ($i = 0; $i < 6; $i++) {
            $this->post(route('admin.account.security.enable'), ['code' => '00000'.$i])->assertSessionHasErrors('code');
        }
        $this->post(route('admin.account.security.enable'), ['code' => $this->codeFor($secret)])->assertSessionHasErrors('code');
        $this->assertFalse($user->fresh()->hasMfaEnabled());
    }

    public function test_turning_it_off_is_paused_after_repeated_failures(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);
        $this->signInWithCode($user, $secret);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('admin.account.security.disable'), ['password' => 'wrong-'.$i, 'code' => $this->codeFor($secret)]);
        }
        $this->post(route('admin.account.security.disable'), ['password' => self::PASSWORD, 'code' => $this->codeFor($secret)])->assertSessionHasErrors('code');
        $this->assertTrue($user->fresh()->hasMfaEnabled());
    }

    public function test_regenerating_recovery_codes_is_limited(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);
        $this->signInWithCode($user, $secret);

        for ($i = 0; $i < 5; $i++) {
            $this->withRecentPassword()->post(route('admin.account.security.codes.regenerate'))->assertOk();
        }
        $this->withRecentPassword()->post(route('admin.account.security.codes.regenerate'))
            ->assertRedirect(route('admin.account.security'))->assertSessionHas('error');
    }

    public function test_password_confirmation_is_limited(): void
    {
        $user = $this->staff();
        $this->actingAs($user);

        for ($i = 0; $i < 10; $i++) {
            $this->post(route('password.confirm.store'), ['password' => 'wrong-'.$i])->assertSessionHasErrors('password');
        }
        $this->post(route('password.confirm.store'), ['password' => self::PASSWORD])->assertSessionHasErrors('password');
        $this->assertNull(session('auth.password_confirmed_at'));

        $this->travel(901)->seconds();
        $this->post(route('password.confirm.store'), ['password' => self::PASSWORD])->assertSessionHasNoErrors();
    }

    public function test_sign_in_is_limited_per_account_even_across_many_addresses(): void
    {
        $user = $this->staff();

        for ($i = 0; $i < 20; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.($i + 1)])
                ->post('/login', ['email' => $user->email, 'password' => 'wrong-'.$i])->assertSessionHasErrors('email');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])->passwordStep($user)->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->travel(901)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.201'])->passwordStep($user)->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_address_trying_many_accounts_is_slowed_down(): void
    {
        $accounts = collect(range(1, 61))->map(fn () => $this->staff());
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.44']);
        foreach ($accounts->take(60) as $account) {
            $this->post('/login', ['email' => $account->email, 'password' => 'wrong']);
        }
        // The 61st account, correct password, same address: paused for ten minutes, not banned.
        $this->passwordStep($accounts->last())->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->travel(601)->seconds();
        $this->passwordStep($accounts->last())->assertSessionHasNoErrors();
    }
}
