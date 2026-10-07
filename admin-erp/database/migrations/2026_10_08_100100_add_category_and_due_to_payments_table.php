<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Membership task 4 (2026-10-08): the existing payments table carries monthly contributions too — no second financial
 * system.
 *
 *   category            registration (the application's registration fee — every payment so far), monthly_contribution,
 *                       voluntary, other
 *   membership_due_id   the month a monthly payment was recorded for (NULL: an advance, or not a monthly payment)
 *   cancelled_*         an unverified monthly entry recorded in error can be cancelled (it is never deleted)
 *
 * Additive only. Existing rows keep every value; the new `category` column is filled from what the row already is: a
 * payment of a membership application is a registration payment, anything else "other".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('category', 30)->default('registration')->after('payable_id');
            $table->foreignId('membership_due_id')->nullable()->after('category')->constrained('membership_dues')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('verified_by');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            $table->index(['category', 'status']);
        });

        DB::table('payments')->where('payable_type', '!=', 'App\\Models\\MembershipApplication')->update(['category' => 'other']);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['category', 'status']);
            $table->dropForeign(['membership_due_id']);
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn(['category', 'membership_due_id', 'cancelled_at', 'cancelled_by', 'cancellation_reason']);
        });
    }
};
