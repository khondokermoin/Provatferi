<?php

namespace Tests\Feature\Mfa;

use App\Models\Setting;
use App\Support\Mfa\MfaSession;
use Illuminate\Support\Facades\Auth;

/**
 * The login flow (docs/MFA_SECURITY.md, "Login flow"): a correct password opens a challenge, never a session; a code
 * from the authenticator completes it.
 */
class MfaSignInTest extends MfaTestCase
{
    // A — an account without two-step verification is unchanged.
    public function test_an_account_without_two_step_verification_signs_in_with_its_password(): void
    {
        $user = $this->staff();

        $this->passwordStep($user)->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_signing_in_still_upgrades_an_outdated_password_hash(): void
    {
        // The password is now checked with validate() instead of attempt(); the upgrade attempt() did must survive.
        $user = $this->staff();
        // Written below the model: the 'hashed' cast refuses a hash made with other settings.
        \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->update(['password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 5])]);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::needsRehash($user->fresh()->getRawOriginal('password')));

        $this->passwordStep($user)->assertRedirect(route('dashboard', absolute: false));

        $this->assertFalse(\Illuminate\Support\Facades\Hash::needsRehash($user->fresh()->getRawOriginal('password')));
    }

    public function test_a_correct_password_alone_does_not_sign_an_account_with_two_step_in(): void
    {
        $user = $this->staff();
        $this->enrol($user);

        $this->passwordStep($user)->assertRedirect(route('mfa.challenge'));

        $this->assertGuest();
        $this->get(route('mfa.challenge'))->assertOk()
            ->assertSee('autocomplete="one-time-code"', false)
            ->assertSee('inputmode="numeric"', false)
            ->assertSee('name="_token"', false);
    }

    // C — a valid code completes the sign-in, in a new session.
    public function test_a_valid_code_completes_the_sign_in_with_a_new_session_id(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);

        $this->passwordStep($user);
        $idDuringChallenge = session()->getId();

        // The browser sends its session cookie back with the code.
        $this->withCookie(config('session.cookie'), $idDuringChallenge)
            ->post(route('mfa.challenge.verify'), ['code' => $this->codeFor($secret)])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($idDuringChallenge, session()->getId(), 'The session id must change when the sign-in completes.');
        $this->assertFalse(session()->has('mfa.pending'));
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_a_code_typed_with_a_space_in_the_middle_is_accepted(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);
        $code = $this->codeFor($secret);

        $this->passwordStep($user);
        $this->post(route('mfa.challenge.verify'), ['code' => substr($code, 0, 3).' '.substr($code, 3)])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    // D — a wrong code is refused.
    public function test_a_wrong_code_is_refused_and_nobody_is_signed_in(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);

        $this->passwordStep($user);
        foreach ([$this->wrongCodeFor($secret), 'abcdef', '12345', '1234567', ''] as $wrong) {
            $this->post(route('mfa.challenge.verify'), ['code' => $wrong])
                ->assertRedirect()->assertSessionHasErrors('code');
        }

        $this->assertGuest();
        $this->get(route('admin.dashboard'))->assertRedirect(route('mfa.challenge'));
    }

    // E — an old code fails: outside the clock window, and a code already used.
    public function test_a_code_older_or_newer_than_one_step_is_refused(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);

        $this->passwordStep($user);
        foreach ([-3, -2, 2, 3] as $offset) {
            $this->post(route('mfa.challenge.verify'), ['code' => $this->codeFor($secret, $offset)])->assertSessionHasErrors('code');
        }
        $this->assertGuest();
    }

    public function test_a_phone_clock_one_step_off_is_tolerated(): void
    {
        $early = $this->staff();
        ['secret' => $secretEarly] = $this->enrol($early);
        $this->nextStep(); // the step before "now" must not be the one the enrolment itself used
        $this->passwordStep($early);
        $this->post(route('mfa.challenge.verify'), ['code' => $this->codeFor($secretEarly, -1)])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($early);

        $this->post('/logout');
        $late = $this->staff();
        ['secret' => $secretLate] = $this->enrol($late);
        $this->passwordStep($late);
        $this->post(route('mfa.challenge.verify'), ['code' => $this->codeFor($secretLate, 1)])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($late);
    }

    public function test_a_code_cannot_be_used_a_second_time(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);
        $code = $this->codeFor($secret);

        $this->passwordStep($user);
        $this->post(route('mfa.challenge.verify'), ['code' => $code])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $this->post('/logout');

        // Same 30-second step: the code an observer saw is worthless now, and so is the previous step's code.
        $this->passwordStep($user);
        $this->post(route('mfa.challenge.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->post(route('mfa.challenge.verify'), ['code' => $this->codeFor($secret, -1)])->assertSessionHasErrors('code');
        $this->assertGuest();

        // The next code works.
        $this->nextStep();
        $this->post(route('mfa.challenge.verify'), ['code' => $this->codeFor($secret)])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    // F — privileged pages, forms and APIs stay closed while the challenge is open.
    public function test_nothing_privileged_is_reachable_between_the_password_and_the_code(): void
    {
        $admin = $this->superAdmin();
        $this->enrol($admin);
        $this->passwordStep($admin);

        foreach ([route('admin.dashboard'), route('admin.users.index'), route('admin.roles.index'), route('admin.settings.index'),
            route('admin.account.security'), route('admin.account.security.setup'), route('profile.edit'), '/dashboard'] as $url) {
            $this->get($url)->assertRedirect(route('mfa.challenge'));
        }
        $this->getJson(route('admin.users.index'))->assertUnauthorized();

        $this->post(route('admin.users.store'), [
            'name' => 'Intruder', 'email' => 'intruder@example.test', 'status' => 'active',
            'password' => 'Str0ng-Passw0rd!', 'password_confirmation' => 'Str0ng-Passw0rd!',
        ])->assertRedirect(route('mfa.challenge'));
        $this->put(route('admin.settings.update'), ['site_name_bn' => 'X'])->assertRedirect(route('mfa.challenge'));
        $this->post(route('admin.account.security.disable'), ['password' => self::PASSWORD, 'code' => '000000'])->assertRedirect(route('mfa.challenge'));
        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.test']);

        // The browser cannot claim the second step was done.
        $this->withHeaders(['X-MFA-Verified' => '1'])->withCookie('mfa_verified', '1')
            ->get(route('admin.dashboard').'?mfa=1&mfa_verified=1')->assertRedirect(route('mfa.challenge'));

        // The API gives no token for the password alone.
        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => self::PASSWORD])
            ->assertUnauthorized()->assertJsonPath('mfa_required', true)->assertJsonMissingPath('data.token');

        $this->assertGuest();
        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_the_challenge_expires_after_ten_minutes(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);

        $this->passwordStep($user);
        $this->travel(MfaSession::PENDING_TTL + 1)->seconds();

        $this->post(route('mfa.challenge.verify'), ['code' => $this->codeFor($secret)])
            ->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->get(route('mfa.challenge'))->assertRedirect(route('login'));
    }

    public function test_going_back_to_the_sign_in_form_closes_the_challenge(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);

        $this->passwordStep($user);
        $this->post(route('mfa.challenge.cancel'))->assertRedirect(route('login'));

        $this->post(route('mfa.challenge.verify'), ['code' => $this->codeFor($secret)])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_deactivated_account_cannot_finish_its_challenge(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);

        $this->passwordStep($user);
        $user->forceFill(['status' => 'inactive'])->save();

        $this->post(route('mfa.challenge.verify'), ['code' => $this->codeFor($secret)])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->passwordStep($user)->assertSessionHasErrors('email');
        $this->assertFalse(session()->has('mfa.pending'));
    }

    // K — remember-me never replaces the second step.
    public function test_remember_me_is_honoured_only_after_the_code_and_never_skips_it(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);
        $recallerName = Auth::guard('web')->getRecallerName();

        $this->passwordStep($user, remember: true)->assertCookieMissing($recallerName);

        $signedIn = $this->post(route('mfa.challenge.verify'), ['code' => $this->codeFor($secret)]);
        $signedIn->assertCookie($recallerName);
        $recaller = $signedIn->getCookie($recallerName)->getValue();
        $this->nextStep();

        // A new browser that carries only the remember-me cookie: it is sent through the challenge, not signed in.
        $this->startNewBrowser();
        $this->withCookie($recallerName, $recaller)->get(route('admin.dashboard'))->assertRedirect(route('mfa.challenge'));
        $this->assertGuest();

        $this->post(route('mfa.challenge.verify'), ['code' => $this->codeFor($secret)])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_session_signed_in_before_two_step_was_turned_on_must_pass_the_challenge(): void
    {
        $user = $this->staff();
        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();

        // Turned on elsewhere (another browser): this older session is not trusted for it.
        $this->enrol($user);
        $this->get(route('admin.dashboard'))->assertRedirect(route('mfa.challenge'));
        $this->assertGuest();
    }

    // L — signing out ends the session and the second step with it.
    public function test_signing_out_ends_the_session_and_its_second_step(): void
    {
        $user = $this->staff();
        ['secret' => $secret] = $this->enrol($user);
        $this->signInWithCode($user, $secret);
        $this->assertAuthenticatedAs($user);
        $oldId = session()->getId();

        $this->withCookie(config('session.cookie'), $oldId)->post('/logout')->assertRedirect('/');

        $this->assertGuest();
        $this->assertFalse(session()->has('mfa.verified'));
        $this->assertSame('', app('session')->driver()->getHandler()->read($oldId), 'The signed-out session must be destroyed on the server.');
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_nothing_is_required_of_anyone_until_the_owner_turns_a_rule_on(): void
    {
        // The state right after deployment: no role requires it and the privileged rule is off.
        $owner = $this->superAdmin();

        $this->assertNull(Setting::query()->where('key', 'security.mfa_privileged_enforced')->first());
        $this->assertFalse((bool) \App\Models\Role::query()->where('slug', 'super_admin')->value('mfa_required'));

        $this->passwordStep($owner)->assertRedirect(route('dashboard', absolute: false));
        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.users.index'))->assertOk();
    }

    /** A six-digit code that is not valid anywhere in the accepted window. */
    private function wrongCodeFor(string $secret): string
    {
        $valid = array_map(fn (int $o) => $this->codeFor($secret, $o), [-1, 0, 1]);
        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (in_array($code, $valid, true));

        return $code;
    }

    /** Forgets the in-memory session and sign-in, as a different browser would start. */
    private function startNewBrowser(): void
    {
        app('session')->driver()->flush();
        app('session')->driver()->setId(null);
        Auth::forgetGuards();
    }
}
