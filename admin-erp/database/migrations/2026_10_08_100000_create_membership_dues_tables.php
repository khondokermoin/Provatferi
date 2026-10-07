<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Membership task 4 (2026-10-08): the monthly membership dues ledger.
 *
 * membership_dues — one row per membership per calendar month (Asia/Dhaka) in which a monthly contribution is owed.
 * `amount` is assessed once, from the fee policy that applied (`membership_fee_policy_id`), and never recalculated:
 * a later fee change creates no difference here. `paid_amount` is VERIFIED money allocated to the month,
 * `waived_amount` what an admin waived with a reason; `outstanding_amount` is computed by the database from the three.
 * `status` is due / partially_paid / paid / waived — "overdue" is not stored, it follows from `due_date` (the last day
 * of the month) and today. UNIQUE(membership_id, period_year, period_month): one due per membership per month, however
 * often or concurrently generation runs. CHECK: the amounts are never negative and never exceed what was assessed.
 *
 * membership_due_allocations — where verified monthly money went: each row moves part of one payment onto one due,
 * either as that payment's own month (`kind` payment) or as advance credit applied later (`kind` credit). A payment's
 * unallocated remainder is the member's advance credit; nothing is ever silently lost.
 *
 * Additive only: two new tables. Production had no memberships when this was written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_dues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained()->restrictOnDelete();
            $table->foreignId('membership_fee_policy_id')->constrained('membership_fee_policies')->restrictOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->date('due_date');
            $table->decimal('amount', 10, 2);
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->decimal('waived_amount', 10, 2)->default(0);
            $table->decimal('outstanding_amount', 10, 2)->storedAs('amount - paid_amount - waived_amount');
            $table->string('status', 20)->default('due');
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['membership_id', 'period_year', 'period_month'], 'membership_dues_one_per_month');
            $table->index(['due_date', 'status']);
        });

        Schema::create('membership_due_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained()->restrictOnDelete();
            $table->foreignId('membership_due_id')->constrained('membership_dues')->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('kind', 20);
            $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('allocated_at')->nullable();
            $table->timestamps();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE membership_dues ADD CONSTRAINT membership_dues_amounts_valid CHECK (amount > 0 AND paid_amount >= 0 AND waived_amount >= 0 AND paid_amount + waived_amount <= amount AND period_month BETWEEN 1 AND 12)');
            DB::statement('ALTER TABLE membership_due_allocations ADD CONSTRAINT membership_due_allocations_positive CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_due_allocations');
        Schema::dropIfExists('membership_dues');
    }
};
