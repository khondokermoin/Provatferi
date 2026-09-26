<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\MailWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class HostingerMailWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.hostinger_mail.webhook_secret', 'test-secret-value');
    }

    public function test_missing_bearer_token_is_rejected(): void
    {
        $response = $this->postJson('/api/webhooks/hostinger-mail', ['id' => 'evt_1']);

        $response->assertStatus(401);
        $this->assertDatabaseCount('mail_webhook_events', 0);
        $this->assertDatabaseCount('admin_notifications', 0);
    }

    public function test_wrong_bearer_token_is_rejected(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer not-the-secret')
            ->postJson('/api/webhooks/hostinger-mail', ['id' => 'evt_1']);

        $response->assertStatus(401);
        $this->assertDatabaseCount('mail_webhook_events', 0);
    }

    public function test_valid_delivery_is_recorded_and_creates_a_notification(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer test-secret-value')
            ->postJson('/api/webhooks/hostinger-mail', [
                'id' => 'evt_123',
                'accountResourceId' => 'AC5804faf062c6d1aa21776e452e7f',
                'event' => 'message.received',
                'data' => ['folder' => 'INBOX', 'uid' => 42],
            ]);

        $response->assertStatus(200);
        $response->assertJson(['ok' => true]);

        $this->assertDatabaseCount('mail_webhook_events', 1);
        $event = MailWebhookEvent::first();
        $this->assertSame('evt_123', $event->hostinger_event_id);
        $this->assertSame('AC5804faf062c6d1aa21776e452e7f', $event->mailbox_resource_id);
        $this->assertSame('INBOX', $event->folder);
        $this->assertSame(42, $event->message_uid);
        $this->assertNotNull($event->processed_at);

        $this->assertDatabaseCount('admin_notifications', 1);
        $notification = AdminNotification::first();
        $this->assertSame('mail_received', $notification->type);
        $this->assertSame('mail.view', $notification->required_permission);
    }

    public function test_redelivery_of_the_same_event_id_is_a_no_op(): void
    {
        $payload = [
            'id' => 'evt_dup',
            'accountResourceId' => 'AC5804faf062c6d1aa21776e452e7f',
            'data' => ['folder' => 'INBOX', 'uid' => 7],
        ];

        $this->withHeader('Authorization', 'Bearer test-secret-value')
            ->postJson('/api/webhooks/hostinger-mail', $payload)
            ->assertStatus(200);

        $response = $this->withHeader('Authorization', 'Bearer test-secret-value')
            ->postJson('/api/webhooks/hostinger-mail', $payload);

        $response->assertStatus(200);
        $response->assertJson(['ok' => true, 'duplicate' => true]);

        // Still exactly one of each — the second delivery did nothing.
        $this->assertDatabaseCount('mail_webhook_events', 1);
        $this->assertDatabaseCount('admin_notifications', 1);
    }

    public function test_payload_with_no_event_id_still_dedupes_via_content_hash(): void
    {
        $payload = ['data' => ['folder' => 'INBOX', 'uid' => 99], 'accountResourceId' => 'ACxyz'];

        $this->withHeader('Authorization', 'Bearer test-secret-value')
            ->postJson('/api/webhooks/hostinger-mail', $payload)
            ->assertStatus(200);

        $this->withHeader('Authorization', 'Bearer test-secret-value')
            ->postJson('/api/webhooks/hostinger-mail', $payload)
            ->assertStatus(200)
            ->assertJson(['duplicate' => true]);

        $this->assertDatabaseCount('mail_webhook_events', 1);
    }

    public function test_no_message_content_fields_are_ever_persisted(): void
    {
        $this->withHeader('Authorization', 'Bearer test-secret-value')
            ->postJson('/api/webhooks/hostinger-mail', [
                'id' => 'evt_content_test',
                'accountResourceId' => 'ACxyz',
                'data' => [
                    'folder' => 'INBOX',
                    'uid' => 1,
                    'subject' => 'This must never be stored',
                    'bodyPreview' => 'Neither must this',
                ],
            ])
            ->assertStatus(200);

        $event = MailWebhookEvent::first();
        $this->assertNotNull($event);

        // Only the known, declared columns exist on the row at all — a
        // stray subject/bodyPreview key in the payload has nowhere to land.
        $this->assertEquals(
            ['id', 'hostinger_event_id', 'mailbox_resource_id', 'event_type', 'folder', 'message_uid', 'processed_at', 'created_at', 'updated_at'],
            array_keys($event->getAttributes())
        );
    }
}
