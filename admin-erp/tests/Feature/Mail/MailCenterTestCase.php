<?php

namespace Tests\Feature\Mail;

use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\Role;
use App\Models\User;
use App\Services\Mail\MailProvider;
use Tests\Feature\Mfa\MfaTestCase;
use Tests\Support\FakeMailProvider;

/**
 * Shared set-up for the Mail Center tests (docs/MAIL_CENTER.md). Six registered mailboxes on the institution's own domain —
 * the five institutional roles and the departmental partnerships mailbox — all reachable through a FakeMailProvider, a
 * "Partnerships" role that holds mail.access and every capability on partnerships@ only (the access the owner approved
 * for the Director of Social Development & Partnerships), and helpers for the other kinds of account. Nothing here, or
 * in the code under test, identifies a person: access comes from roles, permissions and grants.
 */
abstract class MailCenterTestCase extends MfaTestCase
{
    protected FakeMailProvider $fake;

    /** @var array<string, Mailbox> role => registry row */
    protected array $boxes = [];

    protected string $domain;

    protected function setUp(): void
    {
        parent::setUp();
        $public = (string) config('institution.email.public');
        $this->domain = substr($public, strrpos($public, '@') + 1);

        $this->fake = new FakeMailProvider();
        $this->app->instance(MailProvider::class, $this->fake);

        $addresses = [
            'partnerships' => 'partnerships@'.$this->domain,
            'internal' => (string) config('institution.email.internal'),
            'support' => (string) config('institution.email.support'),
            'security' => (string) config('institution.email.security'),
            'public' => (string) config('institution.email.public'),
            'noreply' => (string) config('institution.email.noreply'),
        ];
        $order = 10;
        foreach ($addresses as $role => $address) {
            $this->fake->addMailbox($address);
            $this->fake->addMessage($address, 'INBOX', 1, ['subject' => "Hello {$role}"], "Body of the {$role} message.");
            $this->boxes[$role] = $this->registerMailbox($address, ucfirst($role), $order);
            $order += 10;
        }
    }

    protected function registerMailbox(string $address, string $name, int $order = 0, bool $enabled = true): Mailbox
    {
        $box = new Mailbox(['display_name' => 'Provatferi / '.$name, 'enabled' => $enabled, 'sort_order' => $order]);
        $box->address = strtolower($address);
        $box->provider = 'hostinger_mail_api';
        $box->status = Mailbox::STATUS_CONNECTED;
        $box->save();

        return $box;
    }

    /** @param array<string, bool> $flags */
    protected function grantRole(Role $role, Mailbox $box, array $flags): MailboxGrant
    {
        return MailboxGrant::query()->create(['mailbox_id' => $box->id, 'role_id' => $role->id] + $this->columns($flags));
    }

    /** @param array<string, bool> $flags */
    protected function grantUser(User $user, Mailbox $box, array $flags): MailboxGrant
    {
        return MailboxGrant::query()->create(['mailbox_id' => $box->id, 'user_id' => $user->id] + $this->columns($flags));
    }

    /** @return array<string, bool> */
    protected function all(): array
    {
        return ['read' => true, 'send' => true, 'reply' => true, 'forward' => true, 'manage_state' => true];
    }

    /** The role the owner approved: Mail Center plus every capability on partnerships@, nothing else. */
    protected function partnershipsRole(?array $flags = null): Role
    {
        $role = $this->roleWith(['mail.access'], name: 'Social Development & Partnerships');
        $this->grantRole($role, $this->boxes['partnerships'], $flags ?? $this->all());

        return $role;
    }

    protected function limitedStaff(?array $flags = null): User
    {
        return $this->staffWithRole($this->partnershipsRole($flags));
    }

    /** An account allowed to assign mailbox access, with two-step verification on and a code entered just now. */
    protected function accessAdmin(): User
    {
        $admin = $this->staffWithRole($this->roleWith(['mail.manage_access', 'users.view', 'users.update'], name: 'Mail administrators'));
        $this->enrol($admin);

        return $admin->fresh();
    }

    /** The session state of an account that has passed the second step and confirmed a code within the last minutes. */
    protected function asVerified(User $user): static
    {
        return $this->actingAs($user)->withSession(['mfa.verified' => $user->getKey(), 'mfa.confirmed_at' => now()->getTimestamp()]);
    }

    /** @param array<string, bool> $flags */
    private function columns(array $flags): array
    {
        $columns = [];
        foreach (MailboxGrant::CAPABILITIES as $capability) {
            $columns['can_'.$capability] = (bool) ($flags[$capability] ?? false);
        }

        return $columns;
    }
}
