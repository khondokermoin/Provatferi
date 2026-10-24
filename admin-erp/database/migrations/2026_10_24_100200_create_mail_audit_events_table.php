<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Mail Center's audit trail (docs/MAIL_CENTER.md, "Audit"): mailbox opened, message opened, sent, replied, forwarded,
 * attachment downloaded, message state changed, a send that failed or was stopped as a duplicate, and every change to the
 * registry and to mailbox access. Append-only (App\Services\Mail\MailAuditLogger).
 *
 * It identifies a message only by its folder and UID. It never holds a token, a password, an authorization header, a
 * message body, a subject or an attachment's content: `context` carries counts, flags and result codes, and the logger
 * refuses any key that could carry content or a credential.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('mailbox_id')->nullable()->constrained('mailboxes')->nullOnDelete();
            $table->string('action', 60);
            $table->string('folder', 191)->nullable();
            $table->unsignedBigInteger('message_uid')->nullable();
            // A change of access names whose access changed: role:<id> or user:<id>.
            $table->string('subject_type', 10)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['mailbox_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_audit_events');
    }
};
