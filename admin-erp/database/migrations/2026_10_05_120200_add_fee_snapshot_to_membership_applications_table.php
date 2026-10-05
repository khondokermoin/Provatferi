<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fee snapshot on a membership application (Membership Registry, task 1, 2026-10-05).
 *
 * An application records WHAT IT WAS QUOTED when it was submitted, in its own columns, so changing a fee later can
 * never make an old application owe a different amount — approval, the waive action and the payment form all read
 * these columns, never the type's current policy.
 *
 *   fee_policy_id ................ the policy that applied on the day of the application (explicit reference; RESTRICT
 *                                  so a referenced policy can never be deleted)
 *   registration_fee_amount ...... the exact amounts, copied. Redundant with the policy row ON PURPOSE: the application
 *   monthly_contribution_amount    stays correct even if a policy row were ever lost or altered by hand.
 *   fee_snapshot_source .......... 'policy' (taken from a fee policy) or 'legacy_flat_fee' (an application older than
 *                                  fee policies: the type's flat `fee` of that time, no monthly figure was ever recorded)
 *   fee_effective_on ............. the organisation-calendar date the lookup used (the submission date)
 *
 * Existing applications are backfilled from the flat `fee`, so none is left without a snapshot (an application WITHOUT
 * one is never treated as free — see MembershipController::paymentSatisfied()). Production held zero applications on
 * 2026-10-05, so there it is a no-op; it keeps every other environment consistent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_applications', function (Blueprint $table) {
            $table->foreignId('fee_policy_id')->nullable()->after('membership_season_id')
                ->constrained('membership_fee_policies')->restrictOnDelete();
            $table->decimal('registration_fee_amount', 10, 2)->nullable()->after('fee_policy_id');
            $table->decimal('monthly_contribution_amount', 10, 2)->nullable()->after('registration_fee_amount');
            $table->string('fee_snapshot_source', 20)->nullable()->after('monthly_contribution_amount');
            $table->date('fee_effective_on')->nullable()->after('fee_snapshot_source');
        });

        // +6 hours = Asia/Dhaka (UTC+6, no daylight saving), the organisation's calendar; created_at is stored in UTC.
        DB::statement(
            "UPDATE membership_applications a
             JOIN membership_types t ON t.id = a.membership_type_id
             SET a.registration_fee_amount = t.fee,
                 a.fee_snapshot_source = 'legacy_flat_fee',
                 a.fee_effective_on = DATE(DATE_ADD(a.created_at, INTERVAL 6 HOUR))
             WHERE a.registration_fee_amount IS NULL"
        );
    }

    public function down(): void
    {
        Schema::table('membership_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fee_policy_id');
            $table->dropColumn(['registration_fee_amount', 'monthly_contribution_amount', 'fee_snapshot_source', 'fee_effective_on']);
        });
    }
};
