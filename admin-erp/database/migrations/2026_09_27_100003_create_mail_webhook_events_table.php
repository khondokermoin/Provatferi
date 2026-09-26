<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency + audit trail for inbound Hostinger Mail webhook deliveries.
 *
 * Hostinger's own webhook docs make no exactly-once delivery guarantee, so
 * `hostinger_event_id` is unique — a redelivered/replayed payload is detected
 * and short-circuited before it can create a duplicate notification.
 *
 * This table stores IDENTIFIERS ONLY (event id, mailbox, folder, uid,
 * received-at) — never the message subject or body. It is the audit trail
 * for "did we receive and process this webhook", not a copy of mail content;
 * the Mail Center always re-fetches the real message from the Mail API when
 * an admin opens it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('hostinger_event_id')->unique();
            $table->string('mailbox_resource_id', 60);
            $table->string('event_type', 60);
            $table->string('folder', 120)->nullable();
            $table->unsignedBigInteger('message_uid')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('mailbox_resource_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_webhook_events');
    }
};
