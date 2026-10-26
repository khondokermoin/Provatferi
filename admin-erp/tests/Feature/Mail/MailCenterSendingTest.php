<?php

namespace Tests\Feature\Mail;

use App\Models\MailAuditEvent;
use App\Services\Mail\MailProviderException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Writing mail (docs/MAIL_CENTER.md, "Compose, reply, forward" and "Limits and duplicate protection"): a message always
 * leaves from the mailbox the request is authorised for, under its registered name; recipients and subject refuse line
 * breaks; a form is sent at most once; per-account, per-mailbox, per-action limits apply.
 */
class MailCenterSendingTest extends MailCenterTestCase
{
    public function test_c_the_limited_staff_sends_from_partnerships_and_nothing_else_can_be_the_sender(): void
    {
        $staff = $this->limitedStaff();
        $box = $this->boxes['partnerships'];

        $this->actingAs($staff)->get(route('admin.mail.compose', $box))->assertOk()->assertSee('partnerships@'.$this->domain)->assertDontSee('name="from"', false);

        $response = $this->actingAs($staff)->post(route('admin.mail.send', $box), [
            'to' => 'Partner Org <partner@example.org>, second@example.org',
            'cc' => 'copy@example.org',
            'subject' => 'Partnership meeting',
            'body' => "Dear partner,\n\nLet us meet on Monday.",
            'identity_id' => $this->ownIdentity($box)->id, 'form_key' => (string) Str::uuid(),
            // R: every way a browser could try to choose the sender is ignored.
            'from' => $this->boxes['internal']->address,
            'from_address' => $this->boxes['internal']->address,
            'sender' => $this->boxes['internal']->address,
            'reply_to' => $this->boxes['security']->address,
            'display_name' => 'Spoofed name',
            'mailbox_id' => $this->boxes['internal']->id,
        ]);

        $response->assertRedirect()->assertSessionHas('success', __('admin.mail.send.done_send'));
        $this->assertCount(1, $this->fake->sent);
        $sent = $this->fake->sent[0];
        $this->assertSame('partnerships@'.$this->domain, $sent['address'], 'sent from the authorised mailbox');
        $this->assertSame('Provatferi / Partnerships', $sent['message']->displayName, 'under the registered sender name');
        $this->assertSame(['partner@example.org', 'second@example.org'], $sent['message']->to);
        $this->assertSame(['copy@example.org'], $sent['message']->cc);
        $this->assertSame('Partnership meeting', $sent['message']->subject);
        $this->assertStringContainsString('Let us meet on Monday.', $sent['message']->text);
        $this->assertStringContainsString('<p>Let us meet on Monday.</p>', $sent['message']->html);
        $this->assertNull($sent['message']->inReplyTo);
        $this->assertDatabaseHas('mail_audit_events', ['action' => MailAuditEvent::MESSAGE_SENT, 'mailbox_id' => $box->id, 'actor_id' => $staff->id]);
        $this->assertSame([], $this->fake->callsFor($this->boxes['internal']->address));
    }

    public function test_d_a_reply_threads_on_the_original_quotes_it_and_goes_to_its_sender(): void
    {
        $staff = $this->limitedStaff();
        $box = $this->boxes['partnerships'];
        $this->fake->addMessage($box->address, 'INBOX', 30, ['subject' => 'Collaboration', 'from' => ['partner@example.org', 'Partner'], 'to' => [[$box->address, null], ['colleague@example.org', null]], 'cc' => [['boss@example.org', null]]], "Line one\nLine two");

        $form = $this->actingAs($staff)->get(route('admin.mail.reply', [$box, 30, 'folder' => 'INBOX']));
        $form->assertOk()->assertSee('value="partner@example.org"', false)->assertSee('Re: Collaboration');

        $all = $this->actingAs($staff)->get(route('admin.mail.reply', [$box, 30, 'folder' => 'INBOX', 'all' => 1]));
        $all->assertSee('value="colleague@example.org, boss@example.org"', false)->assertDontSee('value="'.$box->address, false);

        $this->actingAs($staff)->post(route('admin.mail.reply.send', [$box, 30, 'folder' => 'INBOX']), [
            'to' => 'partner@example.org', 'subject' => 'Re: Collaboration', 'body' => 'Thank you.', 'include_quote' => '1', 'identity_id' => $this->ownIdentity($box)->id, 'form_key' => (string) Str::uuid(),
        ])->assertRedirect(route('admin.mail.message', [$box, 30, 'folder' => 'INBOX']));

        $message = $this->fake->sent[0]['message'];
        $this->assertSame(['uid' => 30, 'folder' => 'INBOX'], $message->inReplyTo, 'the provider sets In-Reply-To/References from the original');
        $this->assertNull($message->forwardOf);
        $this->assertStringContainsString("Thank you.\n\n", $message->text);
        $this->assertStringContainsString("> Line one\n> Line two", $message->text);
        $this->assertStringContainsString('<blockquote', $message->html);
        $this->assertDatabaseHas('mail_audit_events', ['action' => MailAuditEvent::MESSAGE_REPLIED, 'mailbox_id' => $box->id, 'message_uid' => 30]);
        $this->assertTrue($this->fake->message($box, 'INBOX', 30)->isAnswered());
    }

    public function test_the_quoted_original_comes_from_the_mailbox_not_from_the_form(): void
    {
        $staff = $this->limitedStaff();
        $box = $this->boxes['partnerships'];
        $this->fake->addMessage($box->address, 'INBOX', 31, ['subject' => 'Original'], 'The real original text');

        $this->actingAs($staff)->post(route('admin.mail.reply.send', [$box, 31, 'folder' => 'INBOX']), [
            'to' => 'partner@example.org', 'subject' => 'Re: Original', 'body' => 'Answer', 'quote' => '> forged quote', 'original_text' => 'forged', 'identity_id' => $this->ownIdentity($box)->id, 'form_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $text = $this->fake->sent[0]['message']->text;
        $this->assertStringContainsString('> The real original text', $text);
        $this->assertStringNotContainsString('forged', $text);
    }

    public function test_e_forward_marks_the_content_and_attaches_only_what_was_ticked(): void
    {
        $staff = $this->limitedStaff();
        $box = $this->boxes['partnerships'];
        $this->fake->addMessage($box->address, 'INBOX', 40, ['subject' => 'Budget', 'from' => ['partner@example.org', 'Partner']], 'Budget attached.', '', [
            ['filename' => 'budget.xlsx', 'contentType' => 'application/vnd.ms-excel', 'content' => 'SHEET'],
            ['filename' => 'notes.txt', 'contentType' => 'text/plain', 'content' => 'NOTES'],
        ]);

        $this->actingAs($staff)->get(route('admin.mail.forward', [$box, 40, 'folder' => 'INBOX']))->assertOk()
            ->assertSee('Fwd: Budget')->assertSee('budget.xlsx')->assertSee(__('admin.mail.compose.forward_attachments_help'));

        $this->actingAs($staff)->post(route('admin.mail.forward.send', [$box, 40, 'folder' => 'INBOX']), [
            'to' => 'board@example.org', 'subject' => 'Fwd: Budget', 'body' => 'For your review.', 'forward_attachments' => ['1'], 'identity_id' => $this->ownIdentity($box)->id, 'form_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $message = $this->fake->sent[0]['message'];
        $this->assertSame(['uid' => 40, 'folder' => 'INBOX'], $message->forwardOf);
        $this->assertNull($message->inReplyTo);
        $this->assertStringContainsString(__('admin.mail.compose.forwarded_marker'), $message->text);
        $this->assertStringContainsString('Partner <partner@example.org>', $message->text);
        $this->assertStringContainsString('Budget attached.', $message->text);
        $this->assertCount(1, $message->attachments, 'only the ticked attachment travels');
        $this->assertSame('notes.txt', $message->attachments[0]->filename);
        $this->assertSame('NOTES', $message->attachments[0]->content);
        $this->assertDatabaseHas('mail_audit_events', ['action' => MailAuditEvent::MESSAGE_FORWARDED, 'mailbox_id' => $box->id, 'message_uid' => 40]);
    }

    public function test_forward_needs_its_own_permission(): void
    {
        $staff = $this->limitedStaff(['read' => true, 'reply' => true]);
        $box = $this->boxes['partnerships'];

        $this->actingAs($staff)->get(route('admin.mail.forward', [$box, 1, 'folder' => 'INBOX']))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.mail.forward.send', [$box, 1, 'folder' => 'INBOX']), ['to' => 'x@example.org', 'subject' => 'Fwd', 'identity_id' => $this->ownIdentity($box)->id, 'form_key' => (string) Str::uuid()])->assertForbidden();
        $this->assertSame([], $this->fake->sent);
    }

    /** Q: a line break, a NUL or another control character in any header-bound field is refused; nothing is sent. */
    public function test_q_header_injection_in_recipients_or_subject_is_refused(): void
    {
        $staff = $this->limitedStaff();
        $box = $this->boxes['partnerships'];
        $attacks = [
            ['to' => "victim@example.org\r\nBcc: everyone@example.org"],
            ['to' => "victim@example.org\nBcc: everyone@example.org"],
            ['cc' => "copy@example.org\r\nX-Injected: yes"],
            ['bcc' => "hid\0den@example.org"],
            ['to' => "victim@example.org,\x0Bother@example.org"],
            ['subject' => "Hello\r\nBcc: everyone@example.org"],
            ['subject' => "Hello\nContent-Type: text/html"],
            ['to' => 'not-an-address'],
            ['to' => 'a@b'],
            ['to' => '"quoted"@example.org'],
            ['to' => 'x@example.org <y@example.org> <z@example.org'],
        ];
        foreach ($attacks as $attack) {
            $response = $this->actingAs($staff)->from(route('admin.mail.compose', $box))->post(route('admin.mail.send', $box), $attack + [
                'to' => 'ok@example.org', 'subject' => 'Fine', 'body' => 'Body', 'identity_id' => $this->ownIdentity($box)->id, 'form_key' => (string) Str::uuid(),
            ]);
            $this->assertSame(route('admin.mail.compose', $box), $response->headers->get('Location'), 'not refused: '.json_encode($attack));
            $response->assertSessionHasErrors();
        }
        $this->assertSame([], $this->fake->sent, 'no attack produced a message');

        // Control characters at the very END of a field are removed by the framework (TrimStrings) before anything else
        // sees them: what is sent is only the clean address, never a second header.
        $this->actingAs($staff)->post(route('admin.mail.send', $box), [
            'to' => "ok@example.org\r\n", 'bcc' => "hidden@example.org\0", 'subject' => "Fine\r\n", 'body' => 'Body', 'identity_id' => $this->ownIdentity($box)->id, 'form_key' => (string) Str::uuid(),
        ])->assertSessionHas('success');
        $this->assertSame(['ok@example.org'], $this->fake->sent[0]['message']->to);
        $this->assertSame(['hidden@example.org'], $this->fake->sent[0]['message']->bcc);
        $this->assertSame('Fine', $this->fake->sent[0]['message']->subject);
    }

    public function test_t_the_same_form_is_sent_once_however_often_it_is_submitted(): void
    {
        $staff = $this->limitedStaff();
        $box = $this->boxes['partnerships'];
        $form = ['to' => 'partner@example.org', 'subject' => 'Once', 'body' => 'Only once', 'identity_id' => $this->ownIdentity($box)->id, 'form_key' => (string) Str::uuid()];

        $this->actingAs($staff)->post(route('admin.mail.send', $box), $form)->assertSessionHas('success');
        $again = $this->actingAs($staff)->post(route('admin.mail.send', $box), $form);
        $again->assertRedirect(route('admin.mail.mailbox', $box))->assertSessionHas('warning', __('admin.mail.send.duplicate_sent'));

        $this->assertCount(1, $this->fake->sent);
        $this->assertDatabaseHas('mail_audit_events', ['action' => MailAuditEvent::SEND_DUPLICATE_BLOCKED, 'mailbox_id' => $box->id]);
        // A new form (a new key) is a new message.
        $this->actingAs($staff)->post(route('admin.mail.send', $box), ['identity_id' => $this->ownIdentity($box)->id, 'form_key' => (string) Str::uuid()] + $form)->assertSessionHas('success');
        $this->assertCount(2, $this->fake->sent);
    }

    public function test_an_unknown_outcome_keeps_the_form_from_being_resent_and_a_refusal_frees_it(): void
    {
        $staff = $this->limitedStaff();
        $box = $this->boxes['partnerships'];

        $this->fake->failOn['send'] = MailProviderException::of(MailProviderException::UNAVAILABLE);
        $form = ['to' => 'partner@example.org', 'subject' => 'Timeout', 'body' => 'x', 'identity_id' => $this->ownIdentity($box)->id, 'form_key' => (string) Str::uuid()];
        $this->actingAs($staff)->post(route('admin.mail.send', $box), $form)->assertSessionHas('warning', __('admin.mail.send.unknown'));
        unset($this->fake->failOn['send']);
        $this->actingAs($staff)->post(route('admin.mail.send', $box), $form)->assertSessionHas('warning', __('admin.mail.send.duplicate_unknown'));
        $this->assertSame([], $this->fake->sent, 'never resent automatically');

        $this->fake->failOn['send'] = MailProviderException::of(MailProviderException::REJECTED, 422);
        $refused = ['identity_id' => $this->ownIdentity($box)->id, 'form_key' => (string) Str::uuid()] + $form;
        $this->actingAs($staff)->from(route('admin.mail.compose', $box))->post(route('admin.mail.send', $box), $refused)->assertSessionHas('error', __('admin.mail.send.failed'));
        unset($this->fake->failOn['send']);
        $this->actingAs($staff)->post(route('admin.mail.send', $box), $refused)->assertSessionHas('success');
        $this->assertCount(1, $this->fake->sent);
        $this->assertDatabaseHas('mail_audit_events', ['action' => MailAuditEvent::SEND_FAILED, 'mailbox_id' => $box->id]);
    }

    public function test_s_limits_are_per_account_mailbox_and_action_not_per_ip_alone(): void
    {
        config(['mail_center.rate_limits.outgoing_burst' => [3, 60]]);
        $staff = $this->limitedStaff();
        $colleague = $this->staffWithRole($staff->roles->first());
        $box = $this->boxes['partnerships'];
        $send = fn ($who) => $this->actingAs($who)->from(route('admin.mail.compose', $box))->post(route('admin.mail.send', $box), ['to' => 'p@example.org', 'subject' => 'n', 'body' => 'b', 'identity_id' => $this->ownIdentity($box)->id, 'form_key' => (string) Str::uuid()]);

        foreach (range(1, 3) as $i) {
            $send($staff)->assertSessionHas('success');
        }
        $send($staff)->assertSessionHas('error');
        $this->assertCount(3, $this->fake->sent);

        // Same IP, other account: its own budget.
        $send($colleague)->assertSessionHas('success');
        $this->assertCount(4, $this->fake->sent);
        $this->assertTrue(RateLimiter::tooManyAttempts('mail-center:outgoing:'.$staff->id.':'.$box->id, 3));
    }

    public function test_the_mailbox_wide_hourly_limit_protects_the_sender_reputation(): void
    {
        config(['mail_center.rate_limits.mailbox_hourly' => [2, 3600]]);
        $box = $this->boxes['partnerships'];
        $role = $this->partnershipsRole();
        foreach (range(1, 3) as $i) {
            $this->actingAs($this->staffWithRole($role))->from(route('admin.mail.compose', $box))
                ->post(route('admin.mail.send', $box), ['to' => 'p@example.org', 'subject' => 'n', 'body' => 'b', 'identity_id' => $this->ownIdentity($box)->id, 'form_key' => (string) Str::uuid()]);
        }
        $this->assertCount(2, $this->fake->sent);
    }

    public function test_blocked_attachment_types_and_oversized_attachments_are_refused(): void
    {
        $staff = $this->limitedStaff();
        $box = $this->boxes['partnerships'];
        $base = ['to' => 'p@example.org', 'subject' => 's', 'body' => 'b'];

        $this->actingAs($staff)->from(route('admin.mail.compose', $box))->post(route('admin.mail.send', $box), $base + [
            'identity_id' => $this->ownIdentity($box)->id, 'form_key' => (string) Str::uuid(), 'attachments' => [UploadedFile::fake()->create('setup.exe', 10)],
        ])->assertSessionHasErrors('attachments');

        config(['mail_center.compose.max_total_attachment_kb' => 30]);
        $this->actingAs($staff)->from(route('admin.mail.compose', $box))->post(route('admin.mail.send', $box), $base + [
            'identity_id' => $this->ownIdentity($box)->id, 'form_key' => (string) Str::uuid(), 'attachments' => [UploadedFile::fake()->create('a.pdf', 20), UploadedFile::fake()->create('b.pdf', 20)],
        ])->assertSessionHasErrors('attachments');
        $this->assertSame([], $this->fake->sent);

        $this->actingAs($staff)->post(route('admin.mail.send', $box), $base + [
            'identity_id' => $this->ownIdentity($box)->id, 'form_key' => (string) Str::uuid(), 'attachments' => [UploadedFile::fake()->create('brief.pdf', 10, 'application/pdf')],
        ])->assertSessionHas('success');
        $this->assertSame('brief.pdf', $this->fake->sent[0]['message']->attachments[0]->filename);
    }

    public function test_message_state_actions_move_and_flag_within_the_mailbox_only(): void
    {
        $staff = $this->limitedStaff();
        $box = $this->boxes['partnerships'];
        $this->fake->addMessage($box->address, 'INBOX', 50, ['subject' => 'To archive']);
        $this->fake->addMessage($box->address, 'INBOX', 51, ['subject' => 'To trash']);

        $this->actingAs($staff)->post(route('admin.mail.state', [$box, 50, 'folder' => 'INBOX']), ['action' => 'flag'])->assertSessionHas('success');
        $this->assertTrue($this->fake->message($box, 'INBOX', 50)->isFlagged());
        $this->actingAs($staff)->post(route('admin.mail.state', [$box, 50, 'folder' => 'INBOX']), ['action' => 'archive'])->assertSessionHas('success');
        $this->actingAs($staff)->post(route('admin.mail.state', [$box, 51, 'folder' => 'INBOX']), ['action' => 'trash'])->assertSessionHas('success');
        $this->assertContains('move '.$box->address.' INBOX 50 INBOX.Archive', $this->fake->calls);
        $this->assertContains('move '.$box->address.' INBOX 51 INBOX.Trash', $this->fake->calls);
        $this->assertSame(2, MailAuditEvent::query()->where('action', MailAuditEvent::STATE_CHANGED)->where('mailbox_id', $box->id)->whereIn('message_uid', [50, 51])->where('context->action', '!=', 'flag')->count());

        // No permanent delete exists; an unknown action is refused.
        $this->actingAs($staff)->post(route('admin.mail.state', [$box, 1, 'folder' => 'INBOX']), ['action' => 'delete'])->assertSessionHasErrors('action');
        $this->assertNotContains('delete', array_map(fn ($c) => strtok($c, ' '), $this->fake->calls));
    }
}
