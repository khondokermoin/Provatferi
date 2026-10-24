<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who may do what with which mailbox (docs/MAIL_CENTER.md, "Permission model"). A grant belongs to a ROLE or to a single
 * USER — exactly one of role_id / user_id is set (enforced by App\Models\MailboxGrant and the controllers). What an account
 * may do with a mailbox is the union of its own grant and the grants of all its roles; it is read on every request, so a
 * revoked grant stops working on the very next one.
 *
 *   can_read          see the folders and messages, open them, download their attachments
 *   can_send          write a new message from the mailbox
 *   can_reply         answer a message of the mailbox (needs can_read)
 *   can_forward       forward a message of the mailbox (needs can_read)
 *   can_manage_state  read/unread, flag, archive, move to trash and back (needs can_read)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->boolean('can_read')->default(false);
            $table->boolean('can_send')->default(false);
            $table->boolean('can_reply')->default(false);
            $table->boolean('can_forward')->default(false);
            $table->boolean('can_manage_state')->default(false);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['mailbox_id', 'role_id']);
            $table->unique(['mailbox_id', 'user_id']);
            $table->index('role_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_grants');
    }
};
