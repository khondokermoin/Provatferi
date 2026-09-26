<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified admin notification feed (bell icon): new volunteer applications,
 * new membership applications, incoming mail, and other system events.
 *
 * Broadcast-style, not per-user rows: one notification can be relevant to
 * every admin holding a given permission (e.g. every mail.view holder should
 * see "new message from ..."). Per-user read state lives in the separate
 * admin_notification_reads table instead of duplicating a row per recipient,
 * so "mark all read" and the unread count are both a single indexed query.
 *
 * `required_permission` is nullable: null means visible to any authenticated
 * admin (e.g. a system-wide notice); a slug like 'mail.view' or
 * 'recruitment.view' scopes it to holders of that permission, so a Membership
 * Admin never sees "new email" badges they have no mailbox access to act on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type', 60); // 'mail.received' | 'recruitment.application' | 'membership.application' | 'system'
            // Nullable: AdminNotificationService never sets this. Title/body
            // are resolved PER VIEWER at display time from type+meta (see
            // AdminNotification::resolvedTitle()) — this column only backs
            // that method's fallback path, for a genuinely one-off notice
            // created without a lang-catalogue type template.
            $table->string('title')->nullable();
            // Deliberately NOT a rendered email body/preview — see
            // HostingerMailWebhookController's own docblock on why mail
            // content is never persisted here.
            $table->string('body', 500)->nullable();
            $table->string('link')->nullable(); // route()-generated, relative
            $table->string('required_permission', 60)->nullable();
            $table->json('meta')->nullable(); // e.g. {"mailbox": "...", "uid": 123} — identifiers only, never message content
            $table->timestamps();

            $table->index(['required_permission', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notifications');
    }
};
