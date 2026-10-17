<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use App\Models\Setting;
use App\Models\User;
use App\Services\Mfa\MfaManager;
use App\Services\Mfa\RecoveryCodes;
use App\Services\Mfa\TotpService;
use App\Services\SecurityEventLogger;
use App\Support\Mfa\MfaPolicy;
use App\Support\Mfa\MfaSession;
use App\Support\Mfa\MfaThrottle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Admin -> Account security (docs/MFA_SECURITY.md). Everything here acts on the SIGNED-IN account only: no route takes a
 * user id, so another account's two-step verification can never be reached from this controller (no IDOR surface).
 *
 *   setup         new secret + QR code + manual key; needs the password confirmed within 15 minutes, and — when an
 *                 authenticator is already active (replacing it) — a code from it within 15 minutes as well
 *   enable        a code from the NEW secret turns it on; the recovery codes are shown once, in that response only
 *   codes         new recovery codes: recent password AND recent code; the old ones stop working at once
 *   disable       current password + a current code (or a recovery code), and only where the policy allows it
 *   step-up       "confirm with your authenticator" for sensitive actions (RequireRecentMfa)
 *   enforcement   switches the privileged-account rule on/off (Super Admin, with a recent code)
 */
class AccountSecurityController extends Controller
{
    public function __construct(
        private readonly MfaManager $mfa,
        private readonly TotpService $totp,
        private readonly RecoveryCodes $codes,
        private readonly SecurityEventLogger $events,
    ) {
    }

    public function show(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $isSuperAdmin = $user->hasRole(MfaPolicy::PRIVILEGED_ROLE);

        return view('admin.account.security', [
            'title' => __('admin.mfa.page_title'),
            'breadcrumbs' => [['label' => __('admin.nav.groups.account')], ['label' => __('admin.mfa.page_title')]],
            'user' => $user,
            'enabled' => $user->hasMfaEnabled(),
            'reasons' => MfaPolicy::requirementReasons($user),
            'required' => MfaPolicy::isRequired($user),
            'canDisable' => MfaPolicy::canDisable($user),
            'remaining' => $this->mfa->remainingRecoveryCodes($user),
            'events' => SecurityEvent::query()->where('user_id', $user->getKey())->latest('id')->limit(6)->get(),
            'isSuperAdmin' => $isSuperAdmin,
            'privilegedEnforced' => MfaPolicy::privilegedEnforcementActive(),
            'privilegedWithoutMfa' => $isSuperAdmin ? $this->privilegedWithoutMfa() : 0,
        ]);
    }

    /** GET behind password.confirm (15 min). Creates the enrolment secret when there is no fresh one. */
    public function setup(Request $request): View|RedirectResponse|Response
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->hasMfaEnabled() && ! MfaSession::confirmedRecently($request)) {
            $request->session()->put('url.intended', route('admin.account.security.setup'));

            return redirect()->route('admin.account.security.step-up');
        }

        $secret = $this->mfa->pendingSecret($user) ?? $this->mfa->startEnrollment($user);
        $uri = $this->totp->provisioningUri($user->email, $secret);

        return $this->private(response()->view('admin.account.mfa-setup', [
            'title' => __('admin.mfa.setup_title'),
            'breadcrumbs' => [['label' => __('admin.mfa.page_title'), 'route' => 'admin.account.security'], ['label' => __('admin.mfa.setup_title')]],
            'qrSvg' => $this->totp->qrSvg($uri),
            'manualKey' => TotpService::formatForDisplay($secret),
            'replacing' => $user->hasMfaEnabled(),
            'issuer' => TotpService::ISSUER,
            'account' => $user->email,
        ]));
    }

    /** A new secret (a fresh QR code), e.g. when the first one was scanned into the wrong app. */
    public function restart(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! MfaSession::passwordConfirmedRecently($request)) {
            return redirect()->route('admin.account.security.setup');
        }
        if ($user->hasMfaEnabled() && ! MfaSession::confirmedRecently($request)) {
            return redirect()->route('admin.account.security.setup');
        }
        $this->mfa->startEnrollment($user);

        return redirect()->route('admin.account.security.setup');
    }

    public function enable(Request $request): RedirectResponse|Response
    {
        /** @var User $user */
        $user = $request->user();
        $request->validate(['code' => ['required', 'string', 'max:20']]);
        if (! MfaSession::passwordConfirmedRecently($request) || $this->mfa->pendingSecret($user) === null) {
            return redirect()->route('admin.account.security.setup')->withErrors(['code' => __('admin.mfa.setup_expired')]);
        }

        $account = (string) $user->getKey();
        if (($seconds = MfaThrottle::blockedFor('enroll', $account, $request)) !== null) {
            return redirect()->route('admin.account.security.setup')->withErrors(['code' => __('admin.mfa.throttled', ['minutes' => (int) ceil($seconds / 60)])]);
        }

        $plain = $this->mfa->confirmEnrollment($user, (string) $request->input('code'));
        if ($plain === null) {
            MfaThrottle::recordFailure('enroll', $account, $request);

            return redirect()->route('admin.account.security.setup')->withErrors(['code' => __('admin.mfa.code_invalid')]);
        }

        MfaThrottle::clear('enroll', $account, $request);
        // This session has just proved it holds the authenticator.
        MfaSession::markVerified($request, $user);

        return $this->showCodesOnce($plain, true);
    }

    /** GET behind password.confirm + recent code: the page with the "new recovery codes" button. */
    public function codes(Request $request): View
    {
        return view('admin.account.mfa-recovery-codes', [
            'title' => __('admin.mfa.codes_title'),
            'breadcrumbs' => [['label' => __('admin.mfa.page_title'), 'route' => 'admin.account.security'], ['label' => __('admin.mfa.codes_title')]],
            'codes' => null,
            'remaining' => $this->mfa->remainingRecoveryCodes($request->user()),
            'justEnabled' => false,
        ]);
    }

    public function regenerate(Request $request): RedirectResponse|Response
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->hasMfaEnabled()) {
            return redirect()->route('admin.account.security');
        }
        if (! MfaSession::passwordConfirmedRecently($request) || ! MfaSession::confirmedRecently($request)) {
            return redirect()->route('admin.account.security.codes');
        }
        $account = (string) $user->getKey();
        if (($seconds = MfaThrottle::blockedFor('manage', $account, $request)) !== null) {
            return redirect()->route('admin.account.security')->with('error', __('admin.mfa.throttled', ['minutes' => (int) ceil($seconds / 60)]));
        }
        MfaThrottle::recordFailure('manage', $account, $request); // regeneration itself is rate-limited, success or not

        return $this->showCodesOnce($this->mfa->regenerateRecoveryCodes($user), false);
    }

    public function disable(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:40'],
        ]);

        if (! $user->hasMfaEnabled()) {
            return redirect()->route('admin.account.security');
        }
        if (! MfaPolicy::canDisable($user)) {
            return redirect()->route('admin.account.security')->with('error', __('admin.mfa.disable_not_allowed'));
        }

        $account = (string) $user->getKey();
        if (($seconds = MfaThrottle::blockedFor('manage', $account, $request)) !== null) {
            return redirect()->route('admin.account.security')->withErrors(['code' => __('admin.mfa.throttled', ['minutes' => (int) ceil($seconds / 60)])]);
        }

        $passwordOk = Hash::check((string) $request->input('password'), (string) $user->password);
        $code = (string) $request->input('code');
        $codeOk = $passwordOk && $this->checkCode($user, $code);
        if (! $passwordOk || ! $codeOk) {
            MfaThrottle::recordFailure('manage', $account, $request);

            return redirect()->route('admin.account.security')->withErrors([
                $passwordOk ? 'code' : 'password' => $passwordOk ? __('admin.mfa.code_invalid') : __('auth.password'),
            ]);
        }

        MfaThrottle::clear('manage', $account, $request);
        $this->mfa->disable($user);

        return redirect()->route('admin.account.security')->with('success', __('admin.mfa.disabled_done'));
    }

    public function stepUp(Request $request): View|RedirectResponse
    {
        if (! $request->user()->hasMfaEnabled()) {
            return redirect()->route('admin.account.security');
        }

        return view('admin.account.mfa-step-up', [
            'title' => __('admin.mfa.step_up_title'),
            'breadcrumbs' => [['label' => __('admin.mfa.page_title'), 'route' => 'admin.account.security'], ['label' => __('admin.mfa.step_up_title')]],
            'mode' => $request->query('method') === 'recovery' ? 'recovery' : 'code',
        ]);
    }

    public function stepUpVerify(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $request->validate(['code' => ['required', 'string', 'max:40']]);
        $recovery = $request->input('method') === 'recovery';
        $account = (string) $user->getKey();
        $back = fn (string $message) => redirect()->route('admin.account.security.step-up', $recovery ? ['method' => 'recovery'] : [])->withErrors(['code' => $message]);

        if (($seconds = MfaThrottle::blockedFor('step-up', $account, $request)) !== null) {
            return $back(__('admin.mfa.throttled', ['minutes' => (int) ceil($seconds / 60)]));
        }
        $ok = $recovery ? $this->mfa->useRecoveryCode($user, (string) $request->input('code')) : $this->mfa->verifyCode($user, (string) $request->input('code'));
        if (! $ok) {
            if (MfaThrottle::recordFailure('step-up', $account, $request)) {
                $this->events->record(SecurityEvent::MFA_CHALLENGE_LOCKED, $user, ['scope' => 'step-up'], null, 'system');
            }

            return $back($recovery ? __('admin.mfa.recovery_invalid') : __('admin.mfa.code_invalid'));
        }

        MfaThrottle::clear('step-up', $account, $request);
        MfaSession::touchConfirmed($request);

        return redirect()->intended(route('admin.account.security'));
    }

    /** The privileged-account rule (MfaPolicy, rule 2). Super Admin only, behind a recent code (route middleware). */
    public function enforcement(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasRole(MfaPolicy::PRIVILEGED_ROLE), 403);
        $enabled = $request->boolean('enabled');

        Setting::query()->updateOrCreate(
            ['key' => MfaPolicy::ENFORCEMENT_SETTING],
            ['value' => $enabled ? 'true' : 'false', 'value_type' => 'boolean', 'group_name' => 'security', 'is_public' => false, 'updated_by' => $user->getKey()],
        );

        $affected = $enabled ? $this->privilegedUsersWithoutMfa() : collect();
        $this->events->record(SecurityEvent::MFA_PRIVILEGED_ENFORCEMENT_CHANGED, $user, ['enabled' => $enabled, 'accounts_now_required' => $affected->count()]);
        foreach ($affected as $account) {
            $this->events->record(SecurityEvent::MFA_NEWLY_REQUIRED, $account, ['because' => 'privileged'], $user);
        }

        return redirect()->route('admin.account.security')->with('success', __($enabled ? 'admin.mfa.enforcement_on' : 'admin.mfa.enforcement_off'));
    }

    /** One code check for "disable": six digits are an authenticator code, anything else a recovery code. */
    private function checkCode(User $user, #[\SensitiveParameter] string $code): bool
    {
        $digits = preg_replace('/\s+/', '', $code) ?? '';

        return preg_match('/^\d{6}$/', $digits) === 1
            ? $this->mfa->verifyCode($user, $digits)
            : $this->mfa->useRecoveryCode($user, $code);
    }

    /** @param list<string> $plain */
    private function showCodesOnce(array $plain, bool $justEnabled): Response
    {
        return $this->private(response()->view('admin.account.mfa-recovery-codes', [
            'title' => __('admin.mfa.codes_title'),
            'breadcrumbs' => [['label' => __('admin.mfa.page_title'), 'route' => 'admin.account.security'], ['label' => __('admin.mfa.codes_title')]],
            'codes' => $plain,
            'remaining' => count($plain),
            'justEnabled' => $justEnabled,
        ]));
    }

    /** Pages that carry a secret or recovery codes are never cached (browser, proxy or CDN) nor indexed. */
    private function private(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function privilegedWithoutMfa(): int
    {
        return $this->privilegedUsersWithoutMfa()->count();
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function privilegedUsersWithoutMfa(): \Illuminate\Support\Collection
    {
        return User::query()->with('roles.permissions')->where('status', 'active')->get()
            ->filter(fn (User $u) => MfaPolicy::isPrivileged($u) && ! $u->hasMfaEnabled())
            ->values();
    }
}
