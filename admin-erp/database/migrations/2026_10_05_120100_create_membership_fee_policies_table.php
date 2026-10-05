<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Effective-dated, append-only fee policies for membership types (Membership Registry, task 1, 2026-10-05).
 *
 * WHY A TABLE AND NOT A COLUMN. A fee kept in one mutable column rewrites history the moment it is edited: every
 * application, membership and payment ever recorded would suddenly "owe" the new amount. Instead every change is a
 * NEW ROW; rows are never edited (only `effective_until` is maintained by MembershipFeePolicyService so the
 * timeline stays contiguous, and a policy that has not started yet may be cancelled). The fee that applies on a given
 * date is the active row whose period contains that date.
 *
 * MONEY: decimal(10,2), never floating point. Zero is a valid fee (a free tier); negative is refused by the service.
 *
 * DATES are the organisation's calendar days (Asia/Dhaka — see config/membership.php), stored as plain DATEs, both
 * ends inclusive: [effective_from, effective_until]; a NULL effective_until means "until further notice".
 *
 * `active` = false means CANCELLED (a mistaken future policy). The row is kept for the audit trail and is ignored by
 * every lookup.
 *
 * INTEGRITY. No overlap, contiguity and "no change to a policy that already took effect" are enforced in
 * MembershipFeePolicyService, inside one transaction that first locks the membership type's row — every fee change
 * for a type is serialised on that lock. Beneath that, the database itself refuses the simplest violation, two ACTIVE
 * policies for the same type starting on the same day: `active_effective_from` is a generated column that equals
 * `effective_from` while the row is active and NULL once it is cancelled, and (membership_type_id,
 * active_effective_from) is UNIQUE — NULLs never collide, so a cancelled row does not block re-creating that date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_fee_policies', function (Blueprint $table) {
            $table->id();
            // RESTRICT, like every other relation that guards membership history: a type that still has policies cannot be
            // deleted by accident. The admin "delete type" action removes an UNUSED type's policies explicitly first.
            $table->foreignId('membership_type_id')->constrained('membership_types')->restrictOnDelete();
            $table->decimal('registration_fee', 10, 2);
            $table->decimal('monthly_contribution', 10, 2);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();

            $table->date('active_effective_from')->nullable()
                ->virtualAs('CASE WHEN active = 1 THEN effective_from ELSE NULL END');

            $table->unique(['membership_type_id', 'active_effective_from'], 'mfp_one_active_start_per_type');
            $table->index(['membership_type_id', 'active', 'effective_from'], 'mfp_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_fee_policies');
    }
};
