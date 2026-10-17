<?php

namespace Tests\Feature\Mfa;

use App\Models\SecurityEvent;
use App\Services\Mfa\MfaManager;
use App\Services\Mfa\RecoveryCodes;
use Illuminate\Support\Facades\DB;

/**
 * Recovery codes (docs/MFA_SECURITY.md, "Recovery codes"): single use, counted, replaced all at once, stored only as keyed
 * hashes, and the answer for a used code is the same as for a code that never existed.
 */
class MfaRecoveryCodesTest extends MfaTestCase
{
    // G — a recovery code signs in, once.
    public function test_a_recovery_code_signs_in_once_and_says_how_many_are_left(): void
    {
        $user = $this->staff();
        ['codes' => $codes] = $this->enrol($user);

        $this->passwordStep($user);
        // Typed sloppily: lower case, spaces instead of the hyphen.
        $this->post(route('mfa.challenge.recovery'), ['recovery_code' => strtolower(str_replace('-', ' ', $codes[0]))])
            ->assertRedirect(route('admin.account.security'))->assertSessionHas('warning');

        $this->assertAuthenticatedAs($user);
        $this->assertSame(9, app(MfaManager::class)->remainingRecoveryCodes($user->fresh()));
        $event = SecurityEvent::query()->where('user_id', $user->id)->where('event', SecurityEvent::MFA_RECOVERY_CODE_USED)->firstOrFail();
        $this->assertSame(['remaining' => 9], $event->context);
        $this->get(route('admin.account.security'))->assertSee('data-mfa-codes-left="9"', false);
    }

    // H — and never again; the refusal does not reveal that it once worked.
    public function test_a_used_recovery_code_gets_the_same_answer_as_one_that_never_existed(): void
    {
        $user = $this->staff();
        ['codes' => $codes] = $this->enrol($user);

        $this->passwordStep($user);
        $this->post(route('mfa.challenge.recovery'), ['recovery_code' => $codes[0]]);
        $this->assertAuthenticatedAs($user);
        $this->post('/logout');

        $this->passwordStep($user);
        $reused = $this->post(route('mfa.challenge.recovery'), ['recovery_code' => $codes[0]]);
        $reusedMessage = session('errors')->first('recovery_code');
        $never = $this->post(route('mfa.challenge.recovery'), ['recovery_code' => 'ABCDE-FGHJK']);
        $neverMessage = session('errors')->first('recovery_code');

        $reused->assertRedirect(route('mfa.challenge', ['method' => 'recovery']));
        $never->assertRedirect(route('mfa.challenge', ['method' => 'recovery']));
        $this->assertSame($neverMessage, $reusedMessage);
        $this->assertGuest();
        $this->assertSame(9, app(MfaManager::class)->remainingRecoveryCodes($user->fresh()));
    }

    public function test_an_authenticator_code_is_not_accepted_as_a_recovery_code_and_the_reverse(): void
    {
        $user = $this->staff();
        ['secret' => $secret, 'codes' => $codes] = $this->enrol($user);

        $this->passwordStep($user);
        $this->post(route('mfa.challenge.recovery'), ['recovery_code' => $this->codeFor($secret)])->assertSessionHasErrors('recovery_code');
        $this->post(route('mfa.challenge.verify'), ['code' => $codes[1]])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertSame(10, app(MfaManager::class)->remainingRecoveryCodes($user->fresh()));
    }

    // I — new codes replace every old one at once.
    public function test_new_recovery_codes_retire_every_old_one_immediately(): void
    {
        $user = $this->staff();
        ['secret' => $secret, 'codes' => $old] = $this->enrol($user);
        $this->signInWithCode($user, $secret);

        $response = $this->withRecentPassword()->post(route('admin.account.security.codes.regenerate'))->assertOk()
            ->assertSee('id="mfa-recovery-codes"', false)
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');
        preg_match_all('/\b[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}\b/', (string) $response->getContent(), $m);
        $new = array_values(array_unique($m[0]));
        $this->assertCount(10, $new);
        $this->assertSame([], array_intersect($old, $new));

        $mfa = app(MfaManager::class);
        foreach ($old as $code) {
            $this->assertFalse($mfa->useRecoveryCode($user->fresh(), $code), 'An old recovery code still works after regeneration.');
        }
        $this->assertTrue($mfa->useRecoveryCode($user->fresh(), $new[3]));
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'event' => SecurityEvent::MFA_RECOVERY_CODES_REGENERATED]);
    }

    public function test_regenerating_needs_both_a_recent_password_and_a_recent_code(): void
    {
        $user = $this->staff();
        ['secret' => $secret, 'codes' => $old] = $this->enrol($user);
        $this->signInWithCode($user, $secret);
        $stored = $user->fresh()->mfa_recovery_codes;

        // Recent code (the sign-in), no recent password.
        $this->get(route('admin.account.security.codes'))->assertRedirect(route('password.confirm'));
        $this->post(route('admin.account.security.codes.regenerate'))->assertRedirect(route('admin.account.security.codes'));
        $this->assertSame($stored, $user->fresh()->mfa_recovery_codes);

        // Recent password, code older than fifteen minutes.
        $this->travel(16)->minutes();
        $this->withRecentPassword()->get(route('admin.account.security.codes'))->assertRedirect(route('admin.account.security.step-up'));
        $this->post(route('admin.account.security.codes.regenerate'))->assertRedirect(route('admin.account.security.codes'));
        $this->assertSame($stored, $user->fresh()->mfa_recovery_codes);

        // Both: the page offers it.
        $this->post(route('admin.account.security.step-up.verify'), ['code' => $this->codeFor($secret)])->assertRedirect();
        $this->get(route('admin.account.security.codes'))->assertOk()->assertSee('id="mfa-regenerate-form"', false);
        foreach ($old as $code) {
            $this->assertStringNotContainsString($code, (string) $this->get(route('admin.account.security.codes'))->getContent());
        }
    }

    public function test_recovery_codes_are_stored_only_as_keyed_hashes(): void
    {
        $user = $this->staff();
        ['codes' => $codes] = $this->enrol($user);

        $raw = (string) DB::table('users')->where('id', $user->id)->value('mfa_recovery_codes');
        $hashes = json_decode($raw, true);
        $this->assertCount(10, $hashes);
        foreach ($hashes as $hash) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        }
        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, $raw);
            $this->assertStringNotContainsString(str_replace('-', '', $code), $raw);
            // Keyed: not the bare SHA-256 of the code, so the table alone cannot be checked against guesses.
            $this->assertNotContains(hash('sha256', str_replace('-', '', $code)), $hashes);
        }
        $this->assertSame($hashes, app(RecoveryCodes::class)->hashAll($codes));
    }

    public function test_recovery_codes_are_long_random_and_unique(): void
    {
        $generator = app(RecoveryCodes::class);
        $seen = [];
        for ($i = 0; $i < 20; $i++) {
            foreach ($generator->generate() as $code) {
                $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}$/', $code);
                $seen[] = $code;
            }
        }
        $this->assertCount(200, array_unique($seen));
    }
}
