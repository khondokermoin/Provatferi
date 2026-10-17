<?php

namespace Tests\Feature\Mfa;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Mfa\MfaManager;
use App\Services\Mfa\TotpService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Shared set-up for the two-step verification tests (docs/MFA_SECURITY.md). Codes are computed here exactly as an
 * authenticator app computes them (RFC 6238 through the same library), from a secret the test holds in memory only.
 * Time moves with travel(): the TOTP step, the challenge expiry and the step-up window all read the application clock.
 */
abstract class MfaTestCase extends TestCase
{
    use RefreshDatabase;

    protected const PASSWORD = 'password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function staff(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['email_verified_at' => now(), 'status' => 'active', 'ui_locale' => 'en']);
    }

    protected function superAdmin(array $attributes = []): User
    {
        $user = $this->staff($attributes);
        $user->roles()->attach(Role::query()->where('slug', 'super_admin')->firstOrFail());

        return $user->fresh();
    }

    /** @param list<string> $permissions */
    protected function roleWith(array $permissions = [], bool $mfaRequired = false, string $name = 'Editors'): Role
    {
        $role = Role::query()->create(['name' => $name.' '.uniqid(), 'slug' => 'qa-'.uniqid()]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $permissions)->pluck('id'));
        if ($mfaRequired) {
            $role->forceFill(['mfa_required' => true])->save();
        }

        return $role;
    }

    protected function staffWithRole(Role $role, array $attributes = []): User
    {
        $user = $this->staff($attributes);
        $user->roles()->attach($role);

        return $user->fresh();
    }

    /**
     * Turns two-step verification on the way the Security page does (MfaManager), returning what the person would hold:
     * the secret their app stores and the recovery codes shown once.
     *
     * @return array{secret: string, codes: list<string>}
     */
    protected function enrol(User $user): array
    {
        $mfa = app(MfaManager::class);
        $secret = $mfa->startEnrollment($user);
        $codes = $mfa->confirmEnrollment($user, $this->codeFor($secret));
        $this->assertIsArray($codes, 'enrolment failed in the test set-up');
        $this->nextStep();

        return ['secret' => $secret, 'codes' => $codes];
    }

    /** The code an authenticator shows now (or $offset steps away). */
    protected function codeFor(string $secret, int $offset = 0): string
    {
        return (new Google2FA)->oathTotp($secret, app(TotpService::class)->currentStep() + $offset);
    }

    /** Moves the clock to the next 30-second step, as a person waiting for the next code would. */
    protected function nextStep(): void
    {
        $this->travel(TotpService::PERIOD)->seconds();
    }

    protected function passwordStep(User $user, bool $remember = false, string $password = self::PASSWORD): TestResponse
    {
        return $this->post('/login', ['email' => $user->email, 'password' => $password] + ($remember ? ['remember' => 'on'] : []));
    }

    /** A complete sign-in through the forms: password, then a current code. */
    protected function signInWithCode(User $user, string $secret): TestResponse
    {
        $this->passwordStep($user)->assertRedirect(route('mfa.challenge'));
        $response = $this->post(route('mfa.challenge.verify'), ['code' => $this->codeFor($secret)]);
        $this->nextStep();

        return $response;
    }

    /** As if the password had just been confirmed on /confirm-password in this session. */
    protected function withRecentPassword(): static
    {
        return $this->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
    }
}
