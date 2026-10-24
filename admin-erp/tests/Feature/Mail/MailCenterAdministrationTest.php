<?php

namespace Tests\Feature\Mail;

use App\Models\MailAuditEvent;
use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\Setting;
use App\Support\Mfa\MfaPolicy;

/**
 * Administering the Mail Center (docs/MAIL_CENTER.md, "Adding a mailbox", "Assigning access", "Two-step verification"):
 * mailboxes are registered and access is assigned from the panel — no code — by mail.manage_access holders who have just
 * confirmed a two-step code; every change is audited; mailbox access makes an account privileged under the MFA policy.
 */
class MailCenterAdministrationTest extends MailCenterTestCase
{
    public function test_a_future_mailbox_is_registered_and_assigned_without_any_code(): void
    {
        $admin = $this->accessAdmin();
        $programs = 'programs@'.$this->domain;
        $this->fake->addMailbox($programs);

        $this->asVerified($admin)->post(route('admin.mail.mailboxes.store'), [
            'address' => strtoupper($programs), 'display_name' => 'Provatferi / Programs', 'description' => 'Programmes team', 'enabled' => '1', 'sort_order' => '70',
        ])->assertRedirect(route('admin.mail.mailboxes.index'));

        $box = Mailbox::query()->where('address', $programs)->firstOrFail();
        $this->assertSame(Mailbox::STATUS_CONNECTED, $box->status);
        $this->assertNotNull($box->provider_mailbox_id);
        $this->assertDatabaseHas('mail_audit_events', ['action' => MailAuditEvent::MAILBOX_REGISTERED, 'mailbox_id' => $box->id, 'actor_id' => $admin->id]);

        $role = $this->roleWith(['mail.access'], name: 'Programmes');
        $this->asVerified($admin)->put(route('admin.mail.access.role', $role), ['mailbox_access' => [$box->id => ['read' => '1', 'send' => '1']]])
            ->assertRedirect(route('admin.roles.edit', $role).'#mailbox-access');

        $staff = $this->staffWithRole($role);
        $this->actingAs($staff)->get(route('admin.mail.mailbox', $box))->assertOk();
        $this->actingAs($staff)->get(route('admin.mail.mailbox', $this->boxes['partnerships']))->assertNotFound();
    }

    public function test_only_the_institutions_own_domain_can_be_registered_and_an_address_never_changes(): void
    {
        $admin = $this->accessAdmin();
        $this->asVerified($admin)->from(route('admin.mail.mailboxes.create'))->post(route('admin.mail.mailboxes.store'), [
            'address' => 'someone@gmail.com', 'display_name' => 'X',
        ])->assertSessionHasErrors('address');
        $this->asVerified($admin)->from(route('admin.mail.mailboxes.create'))->post(route('admin.mail.mailboxes.store'), [
            'address' => $this->boxes['partnerships']->address, 'display_name' => 'Twice',
        ])->assertSessionHasErrors('address');
        $this->asVerified($admin)->from(route('admin.mail.mailboxes.create'))->post(route('admin.mail.mailboxes.store'), [
            'address' => "x@{$this->domain}", 'display_name' => "Name\r\nBcc: x@example.org",
        ])->assertSessionHasErrors('display_name');

        $box = $this->boxes['partnerships'];
        $this->asVerified($admin)->put(route('admin.mail.mailboxes.update', $box), [
            'address' => $this->boxes['internal']->address, 'display_name' => 'Provatferi / Partnerships', 'enabled' => '1', 'sort_order' => '10',
        ])->assertRedirect();
        $this->assertSame('partnerships@'.$this->domain, $box->fresh()->address);
    }

    public function test_the_registry_and_access_changes_need_mail_manage_access_and_a_fresh_two_step_code(): void
    {
        $role = $this->partnershipsRole();
        $box = $this->boxes['support'];

        // Not a mail administrator: 403 (Super Admin passes, see below; a Users & Permissions editor does not).
        $usersEditor = $this->staffWithRole($this->roleWith(['users.view', 'users.update'], name: 'User editors'));
        $this->actingAs($usersEditor)->put(route('admin.mail.access.role', $role), ['mailbox_access' => [$box->id => ['read' => '1']]])->assertForbidden();
        $this->actingAs($usersEditor)->get(route('admin.mail.mailboxes.index'))->assertForbidden();

        // A mail administrator WITHOUT two-step verification cannot hand out access at all.
        $noMfa = $this->staffWithRole($this->roleWith(['mail.manage_access'], name: 'Mail admins without MFA'));
        $this->actingAs($noMfa)->put(route('admin.mail.access.role', $role), ['mailbox_access' => [$box->id => ['read' => '1']]])
            ->assertRedirect(route('admin.account.security'));

        // With two-step verification but no code in the last 15 minutes: asked for one first.
        $admin = $this->accessAdmin();
        $this->actingAs($admin)->withSession(['mfa.verified' => $admin->id, 'mfa.confirmed_at' => now()->subMinutes(20)->getTimestamp()])
            ->put(route('admin.mail.access.role', $role), ['mailbox_access' => [$box->id => ['read' => '1']]])
            ->assertRedirect(route('admin.account.security.step-up'));
        $this->assertFalse(MailboxGrant::query()->where('role_id', $role->id)->where('mailbox_id', $box->id)->exists());

        // A fresh code: saved.
        $this->asVerified($admin)->put(route('admin.mail.access.role', $role), ['mailbox_access' => [$box->id => ['read' => '1']]])->assertRedirect();
        $this->assertTrue(MailboxGrant::query()->where('role_id', $role->id)->where('mailbox_id', $box->id)->where('can_read', true)->exists());
    }

    public function test_saving_the_grid_applies_the_read_rule_removes_empty_rows_and_audits_every_change(): void
    {
        $admin = $this->accessAdmin();
        $role = $this->partnershipsRole();
        $partnerships = $this->boxes['partnerships'];
        $support = $this->boxes['support'];

        $this->asVerified($admin)->put(route('admin.mail.access.role', $role), ['mailbox_access' => [
            $partnerships->id => [],                          // everything unticked: the row goes
            $support->id => ['reply' => '1'],                 // reply alone: read comes with it
            999999 => ['read' => '1'],                        // not a registered mailbox: ignored
        ]])->assertSessionHas('success');

        $this->assertFalse(MailboxGrant::query()->where('role_id', $role->id)->where('mailbox_id', $partnerships->id)->exists());
        $grant = MailboxGrant::query()->where('role_id', $role->id)->where('mailbox_id', $support->id)->firstOrFail();
        $this->assertTrue($grant->can_read && $grant->can_reply);
        $this->assertFalse($grant->can_send || $grant->can_forward || $grant->can_manage_state);
        $this->assertFalse(MailboxGrant::query()->where('mailbox_id', 999999)->exists());

        $events = MailAuditEvent::query()->where('action', MailAuditEvent::ACCESS_CHANGED)->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame(['role', $role->id], [$events[0]->subject_type, (int) $events[0]->subject_id]);
        $this->assertTrue($events[0]->context['before']['forward']);
        $this->assertFalse($events[0]->context['after']['forward']);
        $this->assertSame($admin->id, $events[1]->actor_id);
    }

    public function test_a_users_own_grant_is_assigned_on_the_users_page_and_the_super_admin_role_is_never_edited(): void
    {
        $admin = $this->staffWithRole($this->roleWith(['mail.manage_access', 'users.view', 'users.update'], name: 'Admins'));
        $this->enrol($admin);
        $admin = $admin->fresh();
        $staff = $this->staff();

        $this->asVerified($admin)->get(route('admin.users.show', $staff))->assertOk()->assertSee(__('admin.mail.access.title'))->assertSee($this->boxes['partnerships']->address);
        $this->asVerified($admin)->put(route('admin.mail.access.user', $staff), ['mailbox_access' => [$this->boxes['partnerships']->id => ['read' => '1']]])
            ->assertRedirect(route('admin.users.show', $staff).'#mailbox-access');
        $this->assertTrue(MailboxGrant::query()->where('user_id', $staff->id)->where('can_read', true)->exists());

        $super = Role::query()->where('slug', 'super_admin')->firstOrFail();
        $this->asVerified($admin)->put(route('admin.mail.access.role', $super), ['mailbox_access' => [$this->boxes['partnerships']->id => ['read' => '1']]])
            ->assertSessionHas('warning', __('admin.mail.access.super_admin_has_all'));
        $this->assertFalse(MailboxGrant::query()->where('role_id', $super->id)->exists());
    }

    public function test_the_access_grid_shows_no_credential_and_only_to_mail_administrators(): void
    {
        config(['services.hostinger_mail.token' => 'SENTINEL-TOKEN-'.str_repeat('z', 20)]);
        $role = $this->partnershipsRole();

        $usersEditor = $this->staffWithRole($this->roleWith(['users.view', 'users.update'], name: 'User editors'));
        $this->actingAs($usersEditor)->get(route('admin.roles.edit', $role))->assertOk()->assertDontSee(__('admin.mail.access.title'));

        $owner = $this->superAdmin();
        $page = $this->actingAs($owner)->get(route('admin.roles.edit', $role))->assertOk()->assertSee(__('admin.mail.access.title'));
        $page->assertSee('mailbox_access['.$this->boxes['partnerships']->id.'][read]', false);
        $page->assertDontSee('SENTINEL-TOKEN');
    }

    public function test_y_mailbox_access_makes_an_account_privileged_under_the_mfa_policy(): void
    {
        $limited = $this->limitedStaff();
        $directOnly = $this->staff();
        $this->grantUser($directOnly, $this->boxes['partnerships'], ['read' => true]);
        $editor = $this->staffWithRole($this->roleWith(['activities.view'], name: 'Editors'));

        $this->assertTrue(MfaPolicy::isPrivileged($limited), 'mail.access + a grant');
        $this->assertTrue(MfaPolicy::isPrivileged($directOnly), 'a direct grant alone');
        $this->assertTrue(MfaPolicy::roleIsPrivileged($limited->roles->first()));
        $this->assertFalse(MfaPolicy::isPrivileged($editor));

        // With the privileged-account rule on, the partnerships account must enrol before it can reach anything.
        Setting::query()->create(['key' => MfaPolicy::ENFORCEMENT_SETTING, 'value' => 'true', 'value_type' => 'boolean', 'group_name' => 'security', 'is_public' => false]);
        $this->assertTrue(MfaPolicy::mustEnrollNow($limited->fresh()));
        $this->actingAs($limited->fresh())->get(route('admin.mail.mailbox', $this->boxes['partnerships']))->assertRedirect(route('admin.account.security'));
        $this->actingAs($limited->fresh())->get(route('admin.mail.unread'))->assertRedirect(route('admin.account.security'));
    }

    public function test_a_role_that_requires_two_step_verification_keeps_its_mail_staff_out_until_they_enrol(): void
    {
        $role = $this->partnershipsRole();
        $role->forceFill(['mfa_required' => true])->save();
        $staff = $this->staffWithRole($role);

        $this->actingAs($staff)->get(route('admin.mail.mailbox', $this->boxes['partnerships']))->assertRedirect(route('admin.account.security'));

        $this->enrol($staff);
        $this->asVerified($staff->fresh())->get(route('admin.mail.mailbox', $this->boxes['partnerships']))->assertOk();
    }

    public function test_granting_mailbox_access_records_newly_required_two_step_verification(): void
    {
        Setting::query()->create(['key' => MfaPolicy::ENFORCEMENT_SETTING, 'value' => 'true', 'value_type' => 'boolean', 'group_name' => 'security', 'is_public' => false]);
        $admin = $this->accessAdmin();
        $role = $this->roleWith([], name: 'Plain staff');
        $staff = $this->staffWithRole($role);

        $this->asVerified($admin)->put(route('admin.mail.access.role', $role), ['mailbox_access' => [$this->boxes['partnerships']->id => ['read' => '1']]]);

        $this->assertDatabaseHas('security_events', ['user_id' => $staff->id, 'event' => SecurityEvent::MFA_NEWLY_REQUIRED]);
    }

    public function test_the_archive_folder_can_be_created_and_the_connection_rechecked(): void
    {
        $admin = $this->accessAdmin();
        $box = $this->registerMailbox('projects@'.$this->domain, 'Projects', 90);
        $this->fake->addMailbox($box->address, ['INBOX' => '\\Inbox', 'INBOX.Trash' => '\\Trash']);

        $this->asVerified($admin)->post(route('admin.mail.mailboxes.archive-folder', $box))->assertSessionHas('success');
        $this->assertContains('createFolder '.$box->address.' Archive', $this->fake->calls);

        $this->fake->configured = false;
        $this->asVerified($admin)->post(route('admin.mail.mailboxes.check', $box))->assertSessionHas('warning');
        $this->assertSame(Mailbox::STATUS_UNCONFIGURED, $box->fresh()->status);
    }

    public function test_the_mail_audit_log_is_for_mail_administrators_only(): void
    {
        $this->actingAs($this->limitedStaff())->get(route('admin.mail.audit'))->assertForbidden();
        $this->asVerified($this->accessAdmin())->get(route('admin.mail.audit'))->assertOk();
    }
}
