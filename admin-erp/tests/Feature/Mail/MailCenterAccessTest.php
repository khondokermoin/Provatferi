<?php

namespace Tests\Feature\Mail;

use App\Models\MailboxGrant;

/**
 * Who sees which mailbox (docs/MAIL_CENTER.md, "Permission model"): the limited partnerships account sees partnerships@
 * and nothing else, by any URL; a guessed mailbox id, message id or folder is a 404 that reveals nothing; revoking access
 * works on the very next request; Super Admin and mail.manage_all see every enabled mailbox.
 */
class MailCenterAccessTest extends MailCenterTestCase
{
    public function test_a_limited_staff_sees_only_the_partnerships_mailbox(): void
    {
        $staff = $this->limitedStaff();

        $this->actingAs($staff)->get(route('admin.mail.index'))->assertRedirect(route('admin.mail.mailbox', $this->boxes['partnerships']));
        $page = $this->actingAs($staff)->get(route('admin.mail.mailbox', $this->boxes['partnerships']));
        $page->assertOk()->assertSee('partnerships@'.$this->domain)->assertSee('Hello partnerships');
        foreach (['internal', 'support', 'security', 'public', 'noreply'] as $role) {
            $page->assertDontSee($this->boxes[$role]->address);
            $page->assertDontSee("Hello {$role}");
        }
        $this->assertSame([], $this->fake->callsFor($this->boxes['internal']->address), 'the provider was never asked about admin@');
    }

    /** F–J: every other institutional mailbox is a 404 for the limited account, on every route, and the provider is never asked. */
    public function test_f_to_j_the_limited_staff_cannot_reach_any_other_mailbox(): void
    {
        $staff = $this->limitedStaff();
        foreach (['internal', 'support', 'security', 'public', 'noreply'] as $role) {
            $box = $this->boxes[$role];
            $this->actingAs($staff)->get(route('admin.mail.mailbox', $box))->assertNotFound();
            $this->actingAs($staff)->get(route('admin.mail.message', [$box, 1, 'folder' => 'INBOX']))->assertNotFound();
            $this->actingAs($staff)->get(route('admin.mail.attachment', [$box, 1, 0, 'folder' => 'INBOX']))->assertNotFound();
            $this->actingAs($staff)->get(route('admin.mail.compose', $box))->assertNotFound();
            $this->actingAs($staff)->post(route('admin.mail.send', $box), ['to' => 'x@example.org', 'subject' => 's', 'body' => 'b', 'form_key' => (string) \Illuminate\Support\Str::uuid()])->assertNotFound();
            $this->actingAs($staff)->get(route('admin.mail.reply', [$box, 1, 'folder' => 'INBOX']))->assertNotFound();
            $this->actingAs($staff)->get(route('admin.mail.forward', [$box, 1, 'folder' => 'INBOX']))->assertNotFound();
            $this->actingAs($staff)->post(route('admin.mail.state', [$box, 1, 'folder' => 'INBOX']), ['action' => 'trash'])->assertNotFound();
            $this->assertSame([], $this->fake->callsFor($box->address), "the provider was asked about {$role}");
        }
        $this->assertSame([], $this->fake->sent);
    }

    /** K: an id that is not a mailbox, or not one of this account's, answers exactly like a missing one. */
    public function test_k_a_guessed_mailbox_id_is_denied_without_revealing_anything(): void
    {
        $staff = $this->limitedStaff();
        $missing = $this->actingAs($staff)->get('/admin/mail/999999');
        $theirs = $this->actingAs($staff)->get(route('admin.mail.mailbox', $this->boxes['internal']));
        $missing->assertNotFound();
        $theirs->assertNotFound();
        $theirs->assertDontSee($this->boxes['internal']->address);
        $this->get('/admin/mail/abc')->assertNotFound();
    }

    /** L: a message id of another mailbox, used with the authorised one, is looked up only inside the authorised mailbox. */
    public function test_l_a_guessed_message_id_never_reads_another_mailbox(): void
    {
        $staff = $this->limitedStaff();
        $this->fake->addMessage($this->boxes['internal']->address, 'INBOX', 777, ['subject' => 'Private admin message']);

        $response = $this->actingAs($staff)->get(route('admin.mail.message', [$this->boxes['partnerships'], 777, 'folder' => 'INBOX']));

        $response->assertNotFound();
        $response->assertDontSee('Private admin message');
        $this->assertSame([], $this->fake->callsFor($this->boxes['internal']->address));
        // A folder the mailbox does not have is refused before the provider is asked for any message in it.
        $this->actingAs($staff)->get(route('admin.mail.mailbox', [$this->boxes['partnerships'], 'folder' => 'INBOX.Secret']))->assertNotFound();
        $this->actingAs($staff)->get(route('admin.mail.mailbox', [$this->boxes['partnerships'], 'folder' => ['INBOX']]))->assertNotFound();
    }

    public function test_u_revoking_access_takes_effect_on_the_next_request(): void
    {
        $role = $this->partnershipsRole();
        $staff = $this->staffWithRole($role);
        $this->actingAs($staff)->get(route('admin.mail.mailbox', $this->boxes['partnerships']))->assertOk();

        MailboxGrant::query()->where('role_id', $role->id)->delete();

        $this->actingAs($staff)->get(route('admin.mail.mailbox', $this->boxes['partnerships']))->assertNotFound();
        $this->actingAs($staff)->get(route('admin.mail.index'))->assertOk()->assertSee(__('admin.mail.empty.no_mailbox_title'));
    }

    public function test_removing_the_mail_access_permission_closes_the_mail_center_even_with_grants(): void
    {
        $role = $this->partnershipsRole();
        $staff = $this->staffWithRole($role);
        $role->permissions()->detach();

        $this->actingAs($staff->fresh())->get(route('admin.mail.index'))->assertForbidden();
        $this->actingAs($staff->fresh())->get(route('admin.mail.mailbox', $this->boxes['partnerships']))->assertForbidden();
    }

    public function test_a_disabled_mailbox_disappears_for_everyone(): void
    {
        $staff = $this->limitedStaff();
        $this->boxes['partnerships']->forceFill(['enabled' => false])->save();

        $this->actingAs($staff)->get(route('admin.mail.mailbox', $this->boxes['partnerships']))->assertNotFound();
        $this->actingAs($this->superAdmin())->get(route('admin.mail.mailbox', $this->boxes['partnerships']))->assertNotFound();
    }

    public function test_v_super_admin_sees_every_enabled_mailbox(): void
    {
        $owner = $this->superAdmin();
        $page = $this->actingAs($owner)->get(route('admin.mail.mailbox', $this->boxes['internal']));
        $page->assertOk();
        foreach ($this->boxes as $box) {
            $page->assertSee($box->address);
        }
        $this->actingAs($owner)->get(route('admin.mail.message', [$this->boxes['security'], 1, 'folder' => 'INBOX']))->assertOk()->assertSee('Hello security');
    }

    public function test_mail_manage_all_is_the_same_as_super_admin_for_mail_and_nothing_else(): void
    {
        $all = $this->staffWithRole($this->roleWith(['mail.manage_all'], name: 'All mailboxes'));
        $this->actingAs($all)->get(route('admin.mail.mailbox', $this->boxes['support']))->assertOk()->assertSee('Hello support');
        $this->actingAs($all)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($all)->get(route('admin.mail.mailboxes.index'))->assertForbidden();
    }

    public function test_capabilities_are_per_mailbox_read_only_cannot_write_or_change_state(): void
    {
        $staff = $this->limitedStaff(['read' => true]);
        $box = $this->boxes['partnerships'];

        $this->actingAs($staff)->get(route('admin.mail.message', [$box, 1, 'folder' => 'INBOX']))->assertOk()
            ->assertDontSee(route('admin.mail.reply', [$box, 1, 'folder' => 'INBOX']))
            ->assertDontSee(route('admin.mail.forward', [$box, 1, 'folder' => 'INBOX']));
        $this->actingAs($staff)->get(route('admin.mail.compose', $box))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.mail.reply', [$box, 1, 'folder' => 'INBOX']))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.mail.reply.send', [$box, 1, 'folder' => 'INBOX']), ['to' => 'x@example.org', 'subject' => 'Re', 'body' => 'b', 'form_key' => (string) \Illuminate\Support\Str::uuid()])->assertForbidden();
        $this->actingAs($staff)->post(route('admin.mail.forward.send', [$box, 1, 'folder' => 'INBOX']), ['to' => 'x@example.org', 'subject' => 'Fwd', 'form_key' => (string) \Illuminate\Support\Str::uuid()])->assertForbidden();
        $this->actingAs($staff)->post(route('admin.mail.state', [$box, 1, 'folder' => 'INBOX']), ['action' => 'trash'])->assertForbidden();
        $this->assertSame([], $this->fake->sent);
    }

    public function test_a_send_only_grant_can_compose_but_cannot_read(): void
    {
        $staff = $this->limitedStaff(['send' => true]);
        $box = $this->boxes['partnerships'];

        $this->actingAs($staff)->get(route('admin.mail.mailbox', $box))->assertOk()->assertSee(__('admin.mail.empty.send_only_title'))->assertDontSee('Hello partnerships');
        $this->actingAs($staff)->get(route('admin.mail.message', [$box, 1, 'folder' => 'INBOX']))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.mail.compose', $box))->assertOk();
    }

    public function test_a_direct_user_grant_works_like_a_role_grant_and_grants_combine(): void
    {
        $staff = $this->limitedStaff(['read' => true]);
        $this->grantUser($staff, $this->boxes['partnerships'], ['send' => true]);
        $this->actingAs($staff)->get(route('admin.mail.compose', $this->boxes['partnerships']))->assertOk();

        $other = $this->staffWithRole($this->roleWith(['mail.access'], name: 'Support desk'));
        $this->grantUser($other, $this->boxes['support'], ['read' => true]);
        $this->actingAs($other)->get(route('admin.mail.mailbox', $this->boxes['support']))->assertOk();
        $this->actingAs($other)->get(route('admin.mail.mailbox', $this->boxes['partnerships']))->assertNotFound();
    }

    public function test_an_account_without_mail_permissions_cannot_open_the_mail_center(): void
    {
        $editor = $this->staffWithRole($this->roleWith(['activities.view'], name: 'Editors'));
        $this->actingAs($editor)->get(route('admin.mail.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('admin.mail.unread'))->assertForbidden();
        $this->actingAs($editor)->get(route('admin.dashboard'))->assertOk()->assertDontSee(route('admin.mail.index'));
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get(route('admin.mail.index'))->assertRedirect(route('login'));
        $this->get(route('admin.mail.mailbox', $this->boxes['partnerships']))->assertRedirect(route('login'));
    }

    public function test_the_unread_badge_counts_only_the_accounts_own_mailboxes(): void
    {
        $staff = $this->limitedStaff();
        $this->fake->addMessage($this->boxes['partnerships']->address, 'INBOX', 2);

        $json = $this->actingAs($staff)->getJson(route('admin.mail.unread'))->assertOk()->json();

        $this->assertSame(2, $json['total']);
        $this->assertCount(1, $json['mailboxes']);
        $this->assertSame('partnerships@'.$this->domain, $json['mailboxes'][0]['address']);
    }
}
