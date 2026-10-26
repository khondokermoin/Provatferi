<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the Mail Center sent under an institutional identity (docs/MAIL_CENTER.md, "Sent mail").
 *
 * WHY THIS TABLE EXISTS. The provider API files its own copy of anything it sends into the mailbox's Sent folder. SMTP
 * — the only way to send as an ALIAS, because the API has no sender field — leaves no copy, and the API offers no way
 * to append one. Mirroring the message back into the mailbox by Bcc was tried first and rejected: it lands in the
 * INBOX, where it inflates unread counts, shows up under the identity filters as though it had been received, and
 * clutters the very conversation it belongs to. So the ERP keeps the RECORD of the send instead.
 *
 * It is a record, not a copy. Enough to answer "what went out, as whom, to whom, when, and did it leave" — and nothing
 * more: no body, no subject, no attachment, so the Mail Center still stores no message content at rest.
 *
 * Append-only by intent: a row is written once when a send succeeds and is never edited afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_sent_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();
            // The identity it went out as. Kept even if that identity is later withdrawn, so the history stays true.
            $table->foreignId('mail_identity_id')->nullable()->constrained('mail_identities')->nullOnDelete();
            $table->string('identity_address', 191);
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            // send | reply | forward
            $table->string('kind', 20);
            // provider_api | smtp — which route carried it, so a copy in Sent can be expected or not.
            $table->string('transport', 20);
            // Addresses only: who it went to. No subject, no body, no attachment.
            $table->json('recipients');
            $table->unsignedSmallInteger('recipient_count')->default(0);
            $table->unsignedSmallInteger('attachment_count')->default(0);
            // The transport's own Message-ID where it reports one, and the message answered or forwarded.
            $table->string('message_id', 998)->nullable();
            $table->string('in_reply_to_message_id', 998)->nullable();
            $table->string('source_folder', 191)->nullable();
            $table->unsignedBigInteger('source_uid')->nullable();
            // accepted = the transport took it. The Mail Center never claims delivery it cannot observe.
            $table->string('status', 20)->default('accepted');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['mailbox_id', 'sent_at']);
            $table->index(['mail_identity_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_sent_messages');
    }
};
