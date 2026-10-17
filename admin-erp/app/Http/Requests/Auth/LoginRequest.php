<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\Mfa\MfaThrottle;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Checks the email and password WITHOUT signing anyone in (docs/MFA_SECURITY.md, "Login flow"): the caller decides
     * whether this account goes straight in or through the two-step challenge first. An inactive (deactivated) account is
     * refused with the same message as a wrong password, so the answer never says which accounts exist or are disabled.
     *
     * @throws ValidationException
     */
    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        /** @var \Illuminate\Auth\SessionGuard $guard */
        $guard = Auth::guard('web');
        $valid = $guard->validate($this->only('email', 'password'));
        $user = $guard->getLastAttempted();

        if (! $valid || ! $user instanceof User || $user->status !== 'active') {
            RateLimiter::hit($this->throttleKey());
            MfaThrottle::recordFailure('login', $this->accountKey(), $this);

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        MfaThrottle::clear('login', $this->accountKey(), $this);

        // What Auth::attempt() did before: upgrade an old password hash while the plain password is at hand.
        if (config('hashing.rehash_on_login', true)) {
            $guard->getProvider()->rehashPasswordIfRequired($user, $this->only('password'));
        }

        return $user;
    }

    /**
     * Ensure the login request is not rate limited: five failures a minute for one account from one address (the
     * original rule), plus an allowance per account across all addresses and a larger one per address
     * (App\Support\Mfa\MfaThrottle) — never the address alone.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $seconds = RateLimiter::tooManyAttempts($this->throttleKey(), 5) ? RateLimiter::availableIn($this->throttleKey()) : null;
        $wider = MfaThrottle::blockedFor('login', $this->accountKey(), $this);
        if ($wider !== null) {
            $seconds = max($seconds ?? 0, $wider);
        }
        if ($seconds === null) {
            return;
        }

        event(new Lockout($this));

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }

    private function accountKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')));
    }
}
