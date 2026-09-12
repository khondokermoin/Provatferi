<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared, append-only audit trail for both MembershipApplication and
 * CommitteeSubmission review workflows (§28) — one table, not one per
 * subject type, since the shape (who did what, when, with what note) is
 * identical. Never exposed publicly (§28, §30) and never mutated after
 * insert, hence no `updated_at`. `actor` is polymorphic-by-hand rather than
 * a real morph to `users`, because the actor can also be "system" (e.g. an
 * automatic season-close) or "applicant" (a resubmission), not only staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_history', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id');
            $table->string('action', 40); // submitted|correction_requested|resubmitted|approved|rejected|unpublished|note
            $table->string('actor_type', 20)->default('admin'); // admin|system|applicant
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_history');
    }
};
