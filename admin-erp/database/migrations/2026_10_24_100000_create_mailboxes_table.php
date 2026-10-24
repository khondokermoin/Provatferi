<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Mail Center's mailbox registry (docs/MAIL_CENTER.md, "Mailbox registry"): which institutional mailboxes the ERP
 * offers, under which name, in which order, and whether the provider can reach them. A row is created from the admin
 * panel; adding a mailbox never needs code.
 *
 * Operational metadata ONLY. There is no password, token or provider secret here, and there never may be: the provider
 * credential is the server's HOSTINGER_MAIL_API_TOKEN (.env). `provider_mailbox_id` is the provider's own, non-secret
 * identifier of the mailbox (the Mail API's resourceId), cached from the provider's account listing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();
            $table->string('address', 191)->unique(); // always lower-case; never changes once registered
            $table->string('display_name', 120);       // the sender name on mail sent from the Mail Center
            $table->string('description', 255)->nullable();
            $table->string('provider', 40);
            $table->string('provider_mailbox_id', 191)->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            // unchecked | connected | not_in_scope | provider_unconfigured | unavailable — set by the connection check.
            $table->string('status', 30)->default('unchecked');
            $table->timestamp('status_checked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['enabled', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailboxes');
    }
};
