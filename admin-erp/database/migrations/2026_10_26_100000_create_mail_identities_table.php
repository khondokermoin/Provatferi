<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The institutional IDENTITIES of a mail account (docs/MAIL_CENTER.md, "Mail account and identities").
 *
 * WHY THIS TABLE EXISTS. provatferi.org has exactly ONE real mailbox — the internal administration mailbox — and the
 * other institutional addresses (info@, support@, security@, no-reply@, partnerships@) are ALIASES that the provider
 * delivers into it. Proven on 2026-10-24 by delivery-status reports ("alias expanded"), and again on 2026-10-26 by the
 * send matrix. Registering an alias as its own `mailboxes` row would be a lie twice over: the provider has no mailbox to
 * open for it (the Mail API would answer "not in scope"), and the same physical inbox would appear five times.
 *
 * So: one `mailboxes` row = one PROVIDER MAILBOX that is really opened and read. One row here = one ADDRESS that mailbox
 * sends as and receives for. Identities carry no credential — sending as one is authorised by the account's grant on the
 * mailbox plus this row's own `can_send_manual`, never by anything secret stored here.
 *
 * An identity is NOT created at the provider by the ERP: the address must already exist there as a mailbox or an alias.
 * This table only records which of them the Mail Center offers, and how.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();
            $table->string('address', 191);        // always lower-case; never changes once registered
            $table->string('display_name', 120);   // "Provatferi Partnerships" — the name recipients see
            // administration | general | support | security | partnerships | automated | other. What the address is FOR;
            // it drives the wording in the panel, never an authorisation decision.
            $table->string('purpose', 30)->default('other');
            $table->string('description', 255)->nullable();
            // Mail addressed to this identity is shown under its filter.
            $table->boolean('can_receive')->default(true);
            // May a person pick this identity in Compose / Reply / Forward? False for an automated sender such as
            // no-reply@, which the application's own notifications use and which nobody answers.
            $table->boolean('can_send_manual')->default(true);
            $table->boolean('is_automated')->default(false);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One address is an identity of one mailbox at most: two rows for the same address would make "which
            // identity received this message" ambiguous.
            $table->unique('address');
            $table->index(['mailbox_id', 'enabled', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_identities');
    }
};
