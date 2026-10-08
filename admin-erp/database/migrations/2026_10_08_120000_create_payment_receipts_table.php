<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Membership task 5 (2026-10-08): the official receipt of a verified payment — docs/MEMBERSHIP_RECEIPTS.md.
 *
 * One row per receipt, written when (and only when) a payment is verified, and never changed afterwards: it carries the
 * receipt number AND everything the printed receipt shows, as it was at that moment (the payer's name, the membership
 * type's name, who received and verified it, where the money went, the institution's letterhead), so a receipt stays
 * historically correct whatever is edited later. Additive: nothing existing is altered.
 *
 * The database enforces what can be enforced: one receipt per payment (UNIQUE), one number per receipt (UNIQUE), no
 * receipt for a payment that is deleted-from-under-it (RESTRICT), and the totals always reconcile — what was applied to
 * the payment's purpose plus the advance credit held always equals the amount received.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no', 32)->unique();
            $table->foreignId('payment_id')->unique()->constrained()->restrictOnDelete();

            // registration | monthly | advance | voluntary | other — a word the receipt translates, never shown raw.
            $table->string('purpose', 20);
            $table->decimal('amount', 10, 2);
            // Applied to the purpose itself (the registration fee, the months in `lines`, the contribution) …
            $table->decimal('applied_amount', 10, 2);
            // … and the rest, held as advance credit when the receipt was issued.
            $table->decimal('credit_amount', 10, 2)->default(0);
            // Monthly contributions only: [{"period":"2026-01","amount":"200.00"}, …] as applied AT ISSUE.
            $table->json('lines')->nullable();

            // The calendar day the money was received (a date, never converted) and how.
            $table->date('payment_date');
            $table->string('method', 30)->nullable();
            $table->string('reference', 255)->nullable();

            // Who paid, as they were named then.
            $table->string('payer_name', 255);
            $table->string('member_code', 40)->nullable();
            $table->string('application_no', 40)->nullable();
            $table->string('membership_type_name', 255)->nullable();
            $table->string('membership_type_name_en', 255)->nullable();

            // Who handled it.
            $table->string('received_by_name', 255)->nullable();
            $table->string('verified_by_name', 255)->nullable();
            $table->json('institution');

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            // An explicit default, on purpose: the first TIMESTAMP column of a table declared bare (NOT NULL, no default)
            // silently becomes "DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP" on MariaDB before 10.10 — the issue
            // time of a receipt would then move whenever the row is touched (even by the SET NULL of a deleted user).
            $table->timestamp('issued_at')->useCurrent();
            $table->string('issued_via', 20)->default('verification');
            $table->timestamp('created_at')->nullable();

            $table->index('issued_at');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE payment_receipts ADD CONSTRAINT payment_receipts_amounts_reconcile CHECK (amount > 0 AND applied_amount >= 0 AND credit_amount >= 0 AND applied_amount + credit_amount = amount)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
    }
};
