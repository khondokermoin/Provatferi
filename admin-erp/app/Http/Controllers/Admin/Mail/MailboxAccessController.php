<?php

namespace App\Http\Controllers\Admin\Mail;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\Mail\MailboxGrantManager;
use App\Support\SuperAdminGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Assigning mailbox access (docs/MAIL_CENTER.md, "Assigning access"): the "Mailbox access" grid on a role's page and on
 * a user's page. Needs `mail.manage_access` AND a two-step confirmation from the last fifteen minutes (route middleware
 * `mfa.recent`): handing someone an institutional mailbox is as sensitive as handing them a role.
 *
 * Super Admin is never edited here — it has every mailbox through mail.manage_all, structurally.
 */
class MailboxAccessController extends Controller
{
    public function __construct(private readonly MailboxGrantManager $grants) {}

    public function updateRole(Request $request, Role $role): RedirectResponse
    {
        if ($role->slug === SuperAdminGuard::ROLE) {
            return back()->with('warning', __('admin.mail.access.super_admin_has_all'));
        }
        $changed = $this->grants->syncForRole($role, $this->input($request), $request->user());

        return redirect()->to(route('admin.roles.edit', $role).'#mailbox-access')
            ->with('success', trans_choice('admin.mail.access.saved', $changed, ['count' => $changed]));
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $changed = $this->grants->syncForUser($user, $this->input($request), $request->user());

        return redirect()->to(route('admin.users.show', $user).'#mailbox-access')
            ->with('success', trans_choice('admin.mail.access.saved', $changed, ['count' => $changed]));
    }

    /** @return array<int|string, mixed> mailbox id => [capability => '1'] */
    private function input(Request $request): array
    {
        $request->validate([
            'mailbox_access' => ['nullable', 'array', 'max:200'],
            'mailbox_access.*' => ['array'],
            'mailbox_access.*.*' => ['in:0,1'],
        ]);

        return (array) $request->input('mailbox_access', []);
    }
}
