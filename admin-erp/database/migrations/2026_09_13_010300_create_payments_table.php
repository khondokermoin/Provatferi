<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash-only for this phase (see MembershipSeason/§8) — `method` exists as a
 * column, not a gateway integration, so online payment can be added later
 * without a schema change. Polymorphic `payable` covers both a pending
 * MembershipApplication (money collected before approval) and an active
 * Membership (renewal payments after approval). A structured status/receipt
 * trail, not free-text — see `verified_at`/`verified_by` for the explicit
 * two-person check (recorded-by vs verified-by) §9 requires before approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->morphs('payable');
            $table->foreignId('membership_type_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount_expected', 10, 2)->default(0);
            $table->decimal('amount_received', 10, 2)->nullable();
            $table->string('method', 20)->default('cash');
            $table->date('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 20)->default('pending'); // pending|paid|waived
            $table->text('waiver_reason')->nullable();
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
