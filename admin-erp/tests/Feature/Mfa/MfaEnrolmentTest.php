<?php

namespace Tests\Feature\Mfa;

use App\Models\Role;
use App\Models\SecurityEvent;
use App\Services\Mfa\MfaManager;
use App\Services\Mfa\TotpService;
use Illuminate\Support\Facades\DB;

/**
 * Enrolment (docs/MFA_SECURITY.md, "Enrolment") and the role-based requirement (B): a recent password, a new secret shown
 * as a QR code and a manual key, a correct code from it — only then is it on, and the recovery codes appear once.
 */
class MfaEnrolmentTest extends MfaTestCase
{
    public function test_setting_up_needs_a_password_confirmed_in_the_last_fifteen_minutes(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->get(route('admin.account.security.setup'))->assertRedirect(route('password.confirm'));

        $this->post(route('password.confirm.store'), ['password' => self::PASSWORD])->assertRedirect(route('admin.account.security.setup'));
        $this->get(route('admin.account.security.setup'))->assertOk();

        $this->travel(16)->minutes();
        $this->get(route('admin.account.security.setup'))->assertRedirect(route('password.confirm'));
    }

    public function test_enrolment_turns_on_only_after_a_correct_code_and_shows_the_recovery_codes_once(): void
    {
        $user = $this->staff();
        $this->actingAs($user)->withRecentPassword();

        $setup = $this->get(route('admin.account.security.setup'))->assertOk();
        $secret = $user->fresh()->mfa_pending_secret;
        $this->assertIsString($secret);
        $this->assertSame(TotpService::SECRET_LENGTH, strlen($secret));
        $setup->assertSee('<svg', false)
            ->assertSee(TotpService::formatForDisplay($secret))
            ->assertSee('id="mfa-enable-form"', false)
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertFalse($user->fresh()->hasMfaEnabled(), 'Showing the QR code must not turn anything on.');

        // A code from anything but the new secret leaves it off.
        $this->post(route('admin.account.security.enable'), ['code' => '000000'])
            ->assertRedirect(route('admin.account.security.setup'))->assertSessionHasErrors('code');
        $this->assertFalse($user->fresh()->hasMfaEnabled());

        $shown = $this->post(route('admin.account.security.enable'), ['code' => $this->codeFor($secret)])->assertOk()
            ->assertSee('id="mfa-recovery-codes"', false)
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasMfaEnabled());
        $this->assertNull($fresh->mfa_pending_secret);
        $this->assertSame($secret, $fresh->mfa_secret);

        preg_match_all('/\b[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}\b/', (string) $shown->getContent(), $m);
        $codes = array_values(array_unique($m[0]));
        $this->assertCount(10, $codes, 'Ten recovery codes are shown on activation.');
        foreach ($codes as $code) {
            $this->assertNotContains($code, (array) $fresh->mfa_recovery_codes, 'A recovery code is stored in plain text.');
        }

        // Once: the Security page afterwards shows the count, never the codes nor the secret.
        $page = $this->get(route('admin.account.security'))->assertOk()->assertSee('data-mfa-status="on"', false)->assertSee('data-mfa-codes-left="10"', false);
        foreach ($codes as $code) {
            $page->assertDontSee($code);
        }
        $page->assertDontSee($secret)->assertDontSee(TotpService::formatForDisplay($secret));

        // The activity list names each event in words (an event name contains dots: never shown as a raw key).
        $page->assertSee(trans('admin.mfa.events', [], 'en')['mfa.enabled'])->assertDontSee('admin.mfa.events.');

        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'event' => SecurityEvent::MFA_ENABLED]);
    }

    public function test_the_secret_is_never_shown_again_once_it_is_on(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);
        $this->signInWithCode($user, $secret);
        $this->travel(16)->minutes(); // the sign-in's own confirmation is no longer recent

        $this->withRecentPassword()->get(route('admin.account.security.setup'))->assertRedirect(route('admin.account.security.step-up'));
        foreach ([route('admin.account.security'), route('admin.account.security.step-up'), route('profile.edit')] as $url) {
            $this->get($url)->assertOk()->assertDontSee($secret)->assertDontSee(TotpService::formatForDisplay($secret))->assertDontSee('otpauth://', false);
        }
    }

    public function test_an_unfinished_enrolment_expires_and_must_be_started_again(): void
    {
        $user = $this->staff();
        $this->actingAs($user)->withRecentPassword()->get(route('admin.account.security.setup'))->assertOk();
        $secret = $user->fresh()->mfa_pending_secret;

        $this->travel(MfaManager::PENDING_ENROLLMENT_TTL + 60)->seconds();
        $this->withRecentPassword()->post(route('admin.account.security.enable'), ['code' => $this->codeFor($secret)])
            ->assertRedirect(route('admin.account.security.setup'))->assertSessionHasErrors('code');

        $this->assertFalse($user->fresh()->hasMfaEnabled());
        // Coming back gives a NEW secret.
        $this->get(route('admin.account.security.setup'))->assertOk();
        $this->assertNotSame($secret, $user->fresh()->mfa_pending_secret);
    }

    public function test_enabling_needs_the_password_confirmation_to_still_be_recent(): void
    {
        $user = $this->staff();
        $this->actingAs($user)->withRecentPassword()->get(route('admin.account.security.setup'))->assertOk();
        $secret = $user->fresh()->mfa_pending_secret;

        $this->travel(16)->minutes();
        $this->post(route('admin.account.security.enable'), ['code' => $this->codeFor($secret)])->assertRedirect(route('admin.account.security.setup'));
        $this->assertFalse($user->fresh()->hasMfaEnabled());
    }

    public function test_moving_to_a_new_phone_needs_a_recent_code_and_the_old_app_works_until_the_new_one_is_confirmed(): void
    {
        $user = $this->staff();
        ['secret' => $old] = $this->enrol($user);
        $this->signInWithCode($user, $old);
        $this->travel(16)->minutes();

        $this->withRecentPassword()->get(route('admin.account.security.setup'))->assertRedirect(route('admin.account.security.step-up'));
        $this->post(route('admin.account.security.step-up.verify'), ['code' => $this->codeFor($old)])
            ->assertRedirect(route('admin.account.security.setup'));
        $this->nextStep();

        $this->get(route('admin.account.security.setup'))->assertOk()->assertSee(__('admin.mfa.setup_replacing'));
        $new = $user->fresh()->mfa_pending_secret;
        $this->assertNotSame($old, $new);
        $this->assertSame($old, $user->fresh()->mfa_secret, 'The old authenticator keeps working until the new one is confirmed.');

        $this->post(route('admin.account.security.enable'), ['code' => $this->codeFor($new)])->assertOk();
        $this->assertSame($new, $user->fresh()->mfa_secret);
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'event' => SecurityEvent::MFA_REPLACED]);

        $this->nextStep();
        $this->assertFalse(app(MfaManager::class)->verifyCode($user->fresh(), $this->codeFor($old)), 'The old app must stop working.');
    }

    // B — a role that requires it sends its people to set-up, and nowhere else.
    public function test_an_account_whose_role_requires_it_can_reach_only_the_enrolment_pages(): void
    {
        $role = $this->roleWith(['organization.view', 'activities.view'], mfaRequired: true);
        $user = $this->staffWithRole($role);

        // The password works (nobody is locked out), but every page except set-up says "set it up first".
        $this->passwordStep($user)->assertRedirect(route('dashboard', absolute: false));
        foreach ([route('admin.dashboard'), route('admin.organization.units.index'), route('profile.edit')] as $url) {
            $this->get($url)->assertRedirect(route('admin.account.security'))->assertSessionHas('warning');
        }
        $this->getJson(route('admin.organization.units.index'))->assertForbidden();

        $this->get(route('admin.account.security'))->assertOk()->assertSee('data-mfa-required="yes"', false)->assertSee('id="mfa-setup-button"', false);
        $this->get(route('admin.account.security.setup'))->assertRedirect(route('password.confirm'));
        $this->get(route('password.confirm'))->assertOk();
        $this->post(route('password.confirm.store'), ['password' => self::PASSWORD])->assertRedirect(route('admin.account.security.setup'));
        $this->get(route('admin.account.security.setup'))->assertOk();

        $secret = $user->fresh()->mfa_pending_secret;
        $this->post(route('admin.account.security.enable'), ['code' => $this->codeFor($secret)])->assertOk();
        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.organization.units.index'))->assertOk();
    }

    public function test_an_account_that_must_enrol_can_still_sign_out(): void
    {
        $user = $this->staffWithRole($this->roleWith(['activities.view'], mfaRequired: true));

        $this->actingAs($user)->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_gaining_a_required_role_restricts_the_account_at_its_next_request_and_is_recorded(): void
    {
        $admin = $this->superAdmin();
        $person = $this->staff();
        $required = $this->roleWith(['activities.view'], mfaRequired: true);

        $this->actingAs($person)->get(route('admin.dashboard'))->assertOk();

        $this->actingAs($admin)->put(route('admin.users.update', $person), [
            'name' => $person->name, 'email' => $person->email, 'status' => 'active', 'roles' => [$required->id],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('security_events', ['user_id' => $person->id, 'actor_id' => $admin->id, 'event' => SecurityEvent::MFA_NEWLY_REQUIRED]);
        $this->actingAs($person->fresh())->get(route('admin.dashboard'))->assertRedirect(route('admin.account.security'));
    }

    public function test_losing_the_role_keeps_the_secret_and_the_recovery_codes(): void
    {
        $role = $this->roleWith(['activities.view'], mfaRequired: true);
        $user = $this->staffWithRole($role);
        $this->enrol($user);
        $raw = DB::table('users')->where('id', $user->id)->first(['mfa_secret', 'mfa_recovery_codes']);

        $user->roles()->detach($role);

        $after = DB::table('users')->where('id', $user->id)->first(['mfa_secret', 'mfa_recovery_codes']);
        $this->assertSame($raw->mfa_secret, $after->mfa_secret);
        $this->assertSame($raw->mfa_recovery_codes, $after->mfa_recovery_codes);
        $this->assertTrue($user->fresh()->hasMfaEnabled());
    }

    public function test_the_super_admin_role_does_not_require_it_until_someone_says_so(): void
    {
        $this->assertFalse((bool) Role::query()->where('slug', 'super_admin')->value('mfa_required'));
    }
}
