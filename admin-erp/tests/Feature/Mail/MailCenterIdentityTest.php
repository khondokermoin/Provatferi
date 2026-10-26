<?php

namespace Tests\Feature\Mail;

use App\Models\MailAuditEvent;
use App\Models\MailIdentity;
use App\Models\Mailbox;
use App\Services\Mail\Data\MailAddress;
use App\Services\Mail\Data\MailMessage;
use App\Services\Mail\MailIdentityResolver;
use App\Support\MailCenterIdentityMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * One physical mailbox, several institutional identities (docs/MAIL_CENTER.md, "Mail account and identities").
 *
 * The organisation has ONE real mailbox; info@, support@, security@, no-reply@ and partnerships@ are aliases the
 * provider delivers into it. These tests pin the three things that follow from that: an alias is an identity and not a
 * second mailbox, inbound mail is attributed to the address it really came through, and the sender of an outgoing
 * message is decided by the server from the registry — never by the form.
 */
class MailCenterIdentityTest extends MailCenterTestCase
{
    private Mailbox $account;

    private MailIdentity $partnerships;

    private MailIdentity $support;

    private MailIdentity $noReply;

    protected function setUp(): void
    {
        parent::setUp();

        // The real shape of provatferi.org: ONE physical mailbox, and the institutional addresses as its identities.
        // The shared fixture registers them as separate mailboxes (the arrangement this task replaces), so those rows
        // are removed here first — an address may belong to one mailbox only, which is the point being tested.
        $this->account = $this->boxes['internal'];
        foreach (['partnerships', 'support', 'noreply'] as $alias) {
            $this->boxes[$alias]->delete();
            unset($this->boxes[$alias]);
        }
        $this->partnerships = $this->addIdentity($this->account, 'partnerships@'.$this->domain, 'Provatferi Partnerships', [
            'purpose' => MailIdentity::PURPOSE_PARTNERSHIPS,
        ]);
        $this->support = $this->addIdentity($this->account, 'support@'.$this->domain, 'Provatferi Support', [
            'purpose' => MailIdentity::PURPOSE_SUPPORT,
        ]);
        $this->noReply = $this->addIdentity($this->account, 'no-reply@'.$this->domain, 'Provatferi', [
            'purpose' => MailIdentity::PURPOSE_AUTOMATED, 'is_automated' => true, 'can_send_manual' => false,
        ]);
    }

    private function owner(): \App\Models\User
    {
        return $this->superAdmin();
    }

    private function incoming(int $uid, array $to, array $cc = []): MailMessage
    {
        $address = fn (string $a) => new MailAddress(strtolower($a), null);

        return new MailMessage($uid, 'INBOX', CarbonImmutable::now(), 'Subject '.$uid,
            new MailAddress('outsider@example.org', 'An Outsider'),
            array_map($address, $to), array_map($address, $cc), [], [], true, 100, '<m'.$uid.'@example.org>', []);
    }

    // --- The model -------------------------------------------------------------------------------------------------

    public function test_an_alias_is_an_identity_of_one_mailbox_and_not_a_second_mailbox(): void
    {
        $this->assertSame(
            $this->account->getKey(),
            $this->partnerships->mailbox_id,
            'partnerships@ belongs to the physical mailbox; it is not a mailbox of its own.'
        );

        // Four identities on ONE registry row: the mailbox's own address plus the three added here.
        $this->assertSame(4, MailIdentity::query()->where('mailbox_id', $this->account->getKey())->count());
        $this->assertNull(Mailbox::query()->where('address', $this->partnerships->address)->first(),
            'Registering an alias must never create a second mailbox row for the same physical inbox.');
    }

    public function test_an_automated_identity_is_never_offered_for_a_message_a_person_writes(): void
    {
        $this->assertFalse($this->noReply->allowsManualSending());
        $this->assertTrue($this->partnerships->allowsManualSending());

        $sendable = $this->account->sendableIdentities()->pluck('address')->all();
        $this->assertContains($this->partnerships->address, $sendable);
        $this->assertNotContains($this->noReply->address, $sendable, 'no-reply@ is an automated sender.');
    }

    // --- Inbound attribution ---------------------------------------------------------------------------------------

    public function test_a_message_is_attributed_to_every_identity_it_was_addressed_to(): void
    {
        $resolver = app(MailIdentityResolver::class);

        $one = $resolver->identitiesFor($this->account, $this->incoming(1, [$this->partnerships->address]));
        $this->assertSame([$this->partnerships->address], array_map(fn ($i) => $i->address, $one));

        $both = $resolver->identitiesFor($this->account, $this->incoming(2, [$this->support->address], [$this->partnerships->address]));
        $this->assertEqualsCanonicalizing(
            [$this->support->address, $this->partnerships->address],
            array_map(fn ($i) => $i->address, $both),
            'A message addressed to two institutional addresses belongs to both.'
        );
    }

    public function test_a_message_that_names_no_institutional_address_stays_unclassified(): void
    {
        $resolver = app(MailIdentityResolver::class);

        $this->assertSame([], $resolver->identitiesFor($this->account, $this->incoming(3, ['someone@example.org'])),
            'A bare envelope delivery is never attributed to an identity it did not come through.');
    }

    public function test_an_envelope_header_names_the_identity_when_the_visible_recipients_do_not(): void
    {
        $resolver = app(MailIdentityResolver::class);
        $message = $this->incoming(4, ['undisclosed-recipients@example.org']);

        $envelope = MailIdentityResolver::envelopeAddressesFromSource(
            "Delivered-To: {$this->partnerships->address}\r\nSubject: x\r\n\r\nBody mentions support@{$this->domain} but that is not addressing."
        );

        $this->assertSame([$this->partnerships->address], $envelope);
        $this->assertSame(
            [$this->partnerships->address],
            array_map(fn ($i) => $i->address, $resolver->identitiesFor($this->account, $message, $envelope)),
            'Delivered-To settles which alias the message really arrived through.'
        );
    }

    public function test_the_body_cannot_fake_an_envelope_header(): void
    {
        $source = "Subject: x\r\n\r\nDelivered-To: {$this->partnerships->address}\r\n";

        $this->assertSame([], MailIdentityResolver::envelopeAddressesFromSource($source),
            'Only real headers count; the body may quote anything.');
    }

    // --- The list --------------------------------------------------------------------------------------------------

    public function test_all_mail_shows_everything_and_an_identity_filter_narrows_it_on_the_provider(): void
    {
        $this->fake->addMessage($this->account->address, 'INBOX', 21, [
            'subject' => 'To partnerships', 'to' => [[$this->partnerships->address]],
        ], 'x');

        $all = $this->actingAs($this->owner())->get(route('admin.mail.mailbox', $this->account))->assertOk();
        $all->assertSee(__('admin.mail.nav.all_identities'));
        $all->assertSee($this->partnerships->address);

        $this->actingAs($this->owner())
            ->get(route('admin.mail.mailbox', [$this->account, 'identity' => $this->partnerships->getKey()]))
            ->assertOk();

        // The narrowing is asked of the provider as a recipient criterion, not done by sifting a downloaded folder.
        $this->assertTrue(
            collect($this->fake->calls)->contains(fn ($call) => str_contains((string) $call, 'search')
                && str_contains(strtolower((string) $call), $this->partnerships->address)),
            'The identity filter must reach the provider as a search for that recipient.'
        );
    }

    public function test_an_identity_of_another_mailbox_cannot_be_used_as_a_filter(): void
    {
        $other = $this->addIdentity($this->boxes['security'], 'other-desk@'.$this->domain, 'Other desk');

        $this->actingAs($this->owner())
            ->get(route('admin.mail.mailbox', [$this->account, 'identity' => $other->getKey()]))
            ->assertOk()
            ->assertDontSee('other-desk@'.$this->domain);
    }

    // --- Sending ---------------------------------------------------------------------------------------------------

    public function test_the_sender_is_the_chosen_identity_and_a_forged_one_is_refused(): void
    {
        $owner = $this->owner();
        $form = fn (array $extra = []) => array_merge([
            'to' => 'partner@example.org', 'subject' => 'Hello', 'body' => 'Body',
            'form_key' => (string) Str::uuid(),
        ], $extra);

        // An identity of this mailbox that may be written as: accepted.
        $this->actingAs($owner)->post(route('admin.mail.send', $this->account), $form(['identity_id' => $this->partnerships->getKey()]))
            ->assertSessionHas('success');

        // The automated sender: refused, however the form was built.
        $this->actingAs($owner)->post(route('admin.mail.send', $this->account), $form(['identity_id' => $this->noReply->getKey()]))
            ->assertForbidden();

        // An identity belonging to a different mailbox: a 404, exactly like one that does not exist.
        $foreign = $this->addIdentity($this->boxes['security'], 'desk@'.$this->domain, 'Desk');
        $this->actingAs($owner)->post(route('admin.mail.send', $this->account), $form(['identity_id' => $foreign->getKey()]))
            ->assertNotFound();
        $this->actingAs($owner)->post(route('admin.mail.send', $this->account), $form(['identity_id' => 99999]))
            ->assertNotFound();
    }

    public function test_a_disabled_identity_stops_working_on_the_very_next_request(): void
    {
        $owner = $this->owner();
        $payload = fn () => ['to' => 'p@example.org', 'subject' => 's', 'body' => 'b',
            'identity_id' => $this->partnerships->getKey(), 'form_key' => (string) Str::uuid()];

        $this->actingAs($owner)->post(route('admin.mail.send', $this->account), $payload())->assertSessionHas('success');

        $this->partnerships->forceFill(['can_send_manual' => false])->save();

        $this->actingAs($owner)->post(route('admin.mail.send', $this->account), $payload())->assertForbidden();
    }

    public function test_an_alias_goes_out_over_smtp_and_not_through_the_provider_api(): void
    {
        $owner = $this->owner();
        $before = count($this->fake->calls);

        $this->actingAs($owner)->post(route('admin.mail.send', $this->account), [
            'to' => 'partner@example.org', 'subject' => 'Partnership enquiry', 'body' => 'Body',
            'identity_id' => $this->partnerships->getKey(), 'form_key' => (string) Str::uuid(),
        ])->assertSessionHas('success');

        // The provider API cannot set a sender, so an alias must not be handed to it.
        $sends = collect(array_slice($this->fake->calls, $before))->filter(fn ($c) => str_contains((string) $c, 'send'));
        $this->assertCount(0, $sends, 'An alias is never sent through the provider API, which would send it as the mailbox.');

        $event = MailAuditEvent::query()->where('action', MailAuditEvent::MESSAGE_SENT)->latest('id')->firstOrFail();
        $this->assertSame('smtp', $event->context['transport'] ?? null);
        $this->assertSame($this->partnerships->address, $event->context['identity'] ?? null);
    }

    public function test_the_mailboxs_own_address_goes_out_through_the_provider_api(): void
    {
        $owner = $this->owner();
        $own = $this->ownIdentity($this->account);

        $this->actingAs($owner)->post(route('admin.mail.send', $this->account), [
            'to' => 'partner@example.org', 'subject' => 'From the mailbox itself', 'body' => 'Body',
            'identity_id' => $own->getKey(), 'form_key' => (string) Str::uuid(),
        ])->assertSessionHas('success');

        $this->assertTrue(
            collect($this->fake->calls)->contains(fn ($call) => str_contains((string) $call, 'send')),
            'The mailbox\'s own address keeps the provider path, which files the copy in Sent itself.'
        );

        $this->assertDatabaseHas('mail_audit_events', ['action' => MailAuditEvent::MESSAGE_SENT, 'mailbox_id' => $this->account->getKey()]);
    }

    public function test_the_audit_records_which_identity_was_used_and_no_content(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post(route('admin.mail.send', $this->account), [
            'to' => 'partner@example.org', 'subject' => 'A revealing subject line', 'body' => 'Secret body',
            'identity_id' => $this->ownIdentity($this->account)->getKey(), 'form_key' => (string) Str::uuid(),
        ])->assertSessionHas('success');

        $event = MailAuditEvent::query()->where('action', MailAuditEvent::MESSAGE_SENT)->latest('id')->firstOrFail();
        $context = json_encode($event->context);

        $this->assertStringContainsString($this->account->address, $context, 'The identity used is part of the record.');
        $this->assertStringNotContainsString('A revealing subject line', $context);
        $this->assertStringNotContainsString('Secret body', $context);
    }

    // --- Replying --------------------------------------------------------------------------------------------------

    public function test_a_reply_defaults_to_the_identity_the_conversation_came_through(): void
    {
        $this->fake->addMessage($this->account->address, 'INBOX', 31, [
            'subject' => 'Partnership proposal',
            'from' => ['outsider@example.org'],
            'to' => [[$this->partnerships->address]],
        ], 'Please consider working together.');

        $page = $this->actingAs($this->owner())
            ->get(route('admin.mail.reply', [$this->account, 31, 'folder' => 'INBOX']))
            ->assertOk();

        // The From selector starts on partnerships@, not on the physical mailbox.
        $page->assertSee('value="'.$this->partnerships->getKey().'" selected', false);
    }

    public function test_a_reply_never_writes_back_to_one_of_the_mailboxs_own_addresses(): void
    {
        $this->fake->addMessage($this->account->address, 'INBOX', 32, [
            'subject' => 'Thread',
            'from' => ['outsider@example.org'],
            'to' => [[$this->partnerships->address], ['colleague@example.org']],
        ], 'Body');

        $page = $this->actingAs($this->owner())
            ->get(route('admin.mail.reply', [$this->account, 32, 'folder' => 'INBOX', 'all' => 1]))
            ->assertOk();

        $page->assertSee('outsider@example.org');
        $page->assertDontSee('value="'.$this->partnerships->address.'"', false);
    }
}
