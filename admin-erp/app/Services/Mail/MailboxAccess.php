<?php

namespace App\Services\Mail;

use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * THE one place that decides which mailboxes an account may use and what it may do with them (docs/MAIL_CENTER.md,
 * "Permission model"). Nothing here looks at who the person is — no address, no id: only roles, permissions and grants.
 *
 *   1. The Mail Center opens for `mail.access` or `mail.manage_all` (Super Admin has every permission).
 *   2. `mail.manage_all`: every ENABLED mailbox, every action.
 *   3. Otherwise: the enabled mailboxes for which the account, or one of its roles, holds a grant — and on each, exactly
 *      the union of those grants.
 *
 * Grants are read from the database on every call — never cached, not even within a request (the router keeps controller
 * instances, and with them this object, alive across requests in a long-running server) — so revoking access takes effect
 * on the very next request. A mailbox the account may not use answers 404, exactly like one that does not exist: a
 * guessed id tells nothing.
 */
final class MailboxAccess
{
    public function canOpenMailCenter(User $user): bool
    {
        return Gate::forUser($user)->allows('mail-center');
    }

    public function managesAll(User $user): bool
    {
        return Gate::forUser($user)->allows('mail.manage_all');
    }

    /** @return Collection<int, Mailbox> the enabled mailboxes this account can use, in registry order */
    public function mailboxesFor(User $user): Collection
    {
        if (! $this->canOpenMailCenter($user)) {
            return new Collection();
        }
        $query = Mailbox::query()->enabled()->ordered();
        if (! $this->managesAll($user)) {
            $query->whereHas('grants', fn (Builder $q) => $this->grantsOf($q, $user)->where(fn (Builder $any) => $any
                ->where('can_read', true)->orWhere('can_send', true)->orWhere('can_reply', true)
                ->orWhere('can_forward', true)->orWhere('can_manage_state', true)));
        }

        return $query->get();
    }

    public function capabilities(User $user, Mailbox $mailbox): MailboxCapabilities
    {
        if (! $mailbox->enabled || ! $this->canOpenMailCenter($user)) {
            return MailboxCapabilities::none();
        }
        if ($this->managesAll($user)) {
            return MailboxCapabilities::all();
        }

        return MailboxCapabilities::fromGrants(
            $this->grantsOf(MailboxGrant::query()->where('mailbox_id', $mailbox->getKey()), $user)->get()
        );
    }

    /**
     * The mailbox a request names, if this account may do $capability with it. A mailbox it has no access to at all is
     * a 404 (indistinguishable from a missing one); one it can see but not use this way is a 403.
     *
     * @param  string|null  $capability  one of MailboxGrant::CAPABILITIES, or null for "any access"
     */
    public function resolve(User $user, mixed $mailboxId, ?string $capability = null): Mailbox
    {
        $id = filter_var($mailboxId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $mailbox = $id === false ? null : Mailbox::query()->find($id);
        if ($mailbox === null) {
            throw new NotFoundHttpException();
        }
        $capabilities = $this->capabilities($user, $mailbox);
        if (! $capabilities->any()) {
            throw new NotFoundHttpException();
        }
        if ($capability !== null && ! $capabilities->allows($capability)) {
            throw new HttpException(403, __('admin.mail.errors.not_allowed'));
        }

        return $mailbox;
    }

    /** Restricts a grants query to the account's own grant and its roles' grants. */
    private function grantsOf(Builder $query, User $user): Builder
    {
        $roleIds = $user->roles()->pluck('roles.id')->all();

        return $query->where(function (Builder $q) use ($user, $roleIds) {
            $q->where('user_id', $user->getKey());
            if ($roleIds !== []) {
                $q->orWhereIn('role_id', $roleIds);
            }
        });
    }
}
