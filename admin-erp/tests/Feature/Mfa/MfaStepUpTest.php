<?php

namespace Tests\Feature\Mfa;

use App\Models\SecurityEvent;
use Illuminate\Support\Facades\Route;

/**
 * Step-up (docs/MFA_SECURITY.md, "Step-up"): `mfa.recent` lets an action through only when THIS session proved the
 * authenticator within fifteen minutes — the server's own record, never anything the browser sends.
 */
class MfaStepUpTest extends MfaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'mfa.recent'])->group(function () {
            Route::get('/_test/sensitive', fn () => 'sensitive-ok');
            Route::post('/_test/sensitive', fn () => 'sensitive-posted');
        });
        Route::middleware(['web', 'auth', 'mfa.recent:lenient'])->get('/_test/lenient', fn () => 'lenient-ok');
    }

    public function test_a_sensitive_action_asks_for_a_fresh_code_and_comes_back_afterwards(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);
        $this->signInWithCode($user, $secret);

        // The sign-in itself counts as a recent confirmation.
        $this->get('/_test/sensitive')->assertOk()->assertSee('sensitive-ok');

        $this->travel(16)->minutes();
        $this->get('/_test/sensitive')->assertRedirect(route('admin.account.security.step-up'));
        $this->get(route('admin.account.security.step-up'))->assertOk()->assertSee('id="mfa-step-up-form"', false);
        $this->post(route('admin.account.security.step-up.verify'), ['code' => $this->codeFor($secret)])->assertRedirect(url('/_test/sensitive'));
        $this->get('/_test/sensitive')->assertOk();

        // A form is sent back to the page it was on, never re-submitted blindly.
        $this->travel(16)->minutes();
        $this->from(url('/admin'))->post('/_test/sensitive')->assertRedirect(route('admin.account.security.step-up'));
        $this->assertSame(url('/admin'), session('url.intended'));
    }

    public function test_the_browser_cannot_claim_a_recent_confirmation(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);
        $this->signInWithCode($user, $secret);
        $this->travel(16)->minutes();

        $this->withHeaders(['X-MFA-Confirmed' => '1', 'X-MFA-Confirmed-At' => (string) now()->getTimestamp()])
            ->withCookie('mfa_confirmed_at', (string) now()->getTimestamp())
            ->get('/_test/sensitive?mfa_confirmed_at='.now()->getTimestamp().'&confirmed=1')
            ->assertRedirect(route('admin.account.security.step-up'));
        $this->post('/_test/sensitive', ['mfa_confirmed_at' => now()->getTimestamp(), 'mfa' => ['confirmed_at' => now()->getTimestamp()]])
            ->assertRedirect(route('admin.account.security.step-up'));
    }

    public function test_a_recent_password_is_not_a_recent_code(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);
        $this->signInWithCode($user, $secret);
        $this->travel(16)->minutes();

        $this->withRecentPassword()->get('/_test/sensitive')->assertRedirect(route('admin.account.security.step-up'));
    }

    public function test_a_recovery_code_can_confirm_and_is_then_used_up(): void
    {
        $user = $this->staff();
        ['secret' => $secret, 'codes' => $codes] = $this->enrol($user);
        $this->signInWithCode($user, $secret);
        $this->travel(16)->minutes();

        $this->get('/_test/sensitive');
        $this->post(route('admin.account.security.step-up.verify'), ['method' => 'recovery', 'code' => $codes[2]])->assertRedirect(url('/_test/sensitive'));
        $this->get('/_test/sensitive')->assertOk();
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'event' => SecurityEvent::MFA_RECOVERY_CODE_USED]);
    }

    public function test_wrong_step_up_codes_are_refused_and_paused(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);
        $this->signInWithCode($user, $secret);
        $this->travel(16)->minutes();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('admin.account.security.step-up.verify'), ['code' => '00000'.$i])->assertSessionHasErrors('code');
        }
        $this->post(route('admin.account.security.step-up.verify'), ['code' => $this->codeFor($secret)])->assertSessionHasErrors('code');
        $this->get('/_test/sensitive')->assertRedirect(route('admin.account.security.step-up'));
        $this->assertSame(1, SecurityEvent::query()->where('user_id', $user->id)->where('event', SecurityEvent::MFA_CHALLENGE_LOCKED)->count());
    }

    public function test_strict_step_up_requires_the_account_to_use_two_step_at_all(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->get('/_test/sensitive')->assertRedirect(route('admin.account.security'))->assertSessionHas('warning');
        $this->getJson('/_test/sensitive')->assertForbidden();
        $this->get('/_test/lenient')->assertOk()->assertSee('lenient-ok');
    }

    public function test_an_api_style_request_without_a_recent_code_gets_a_clear_status(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);
        $this->signInWithCode($user, $secret);
        $this->travel(16)->minutes();

        $this->getJson('/_test/sensitive')->assertStatus(423);
    }
}
