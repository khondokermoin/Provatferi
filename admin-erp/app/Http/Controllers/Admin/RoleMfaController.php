<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\SecurityEventLogger;
use App\Support\Mfa\MfaPolicy;
use App\Support\Mfa\MfaSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Require two-step verification for this role" (docs/MFA_SECURITY.md, "Role-based policy"). Its own route, not part of
 * the role form, so that the protection can only change through this one, deliberately guarded action:
 *
 *   turning it ON   raises protection: the password must have been confirmed within 15 minutes (or, for an administrator
 *                   who uses two-step verification, a code within 15 minutes);
 *   turning it OFF  lowers protection: only an administrator who uses two-step verification, with a code from the last 15
 *                   minutes — a stolen password alone can never switch a role's requirement off.
 *
 * Accounts that become required and have not enrolled are recorded (mfa.newly_required); at their next request they can
 * reach the enrolment pages only.
 */
class RoleMfaController extends Controller
{
    public function __construct(private readonly SecurityEventLogger $events)
    {
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $required = $request->boolean('mfa_required');
        $editPage = route('admin.roles.edit', $role);

        if ($required === (bool) $role->mfa_required) {
            return redirect($editPage);
        }
        // The Super Admin role's protection is decided by Super Admins only (an administrator who may edit roles cannot
        // lower it, nor force it on the owner).
        if ($role->slug === MfaPolicy::PRIVILEGED_ROLE && ! $actor->hasRole(MfaPolicy::PRIVILEGED_ROLE)) {
            return redirect($editPage)->with('error', __('admin.mfa.role_super_admin_only'));
        }

        if ($required) {
            $confirmed = $actor->hasMfaEnabled() ? MfaSession::confirmedRecently($request) : MfaSession::passwordConfirmedRecently($request);
            if (! $confirmed) {
                $request->session()->put('url.intended', $editPage);

                // Back to the role screen afterwards (never to this PATCH address, which a browser cannot GET).
                return $actor->hasMfaEnabled()
                    ? redirect()->route('admin.account.security.step-up')
                    : redirect()->route('password.confirm');
            }
        } else {
            if (! $actor->hasMfaEnabled()) {
                return redirect($editPage)->with('error', __('admin.mfa.role_off_needs_mfa'));
            }
            if (! MfaSession::confirmedRecently($request)) {
                $request->session()->put('url.intended', $editPage);

                return redirect()->route('admin.account.security.step-up');
            }
        }

        $role->forceFill(['mfa_required' => $required])->save();

        $newlyRequired = $required
            ? $role->users()->with('roles')->get()->filter(fn (User $u) => ! $u->hasMfaEnabled())->values()
            : collect();
        $this->events->record(SecurityEvent::MFA_ROLE_REQUIREMENT_CHANGED, $actor, [
            'role' => $role->slug, 'required' => $required, 'accounts_now_required' => $newlyRequired->count(),
        ]);
        foreach ($newlyRequired as $account) {
            $this->events->record(SecurityEvent::MFA_NEWLY_REQUIRED, $account, ['because' => 'role', 'role' => $role->slug], $actor);
        }

        return redirect($editPage)->with('success', __($required ? 'admin.mfa.role_required_on' : 'admin.mfa.role_required_off', ['role' => $role->name]));
    }

    /** For the role screens: is this role privileged (MFA recommended or, with the privileged rule on, required)? */
    public static function isPrivileged(Role $role): bool
    {
        return MfaPolicy::roleIsPrivileged($role);
    }
}
