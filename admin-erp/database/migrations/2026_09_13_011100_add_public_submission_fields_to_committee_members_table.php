<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A committee seat can now come from two places: the existing admin-picks-
 * an-existing-user flow (`user_id` + `position_id`, unchanged) or an
 * approved public CommitteeSubmission (`committee_submission_id` +
 * `committee_position_id`, new). `user_id` becomes nullable so the second
 * path never needs a `users` row. Both position columns coexist rather than
 * merging, so the existing org-unit-scoped position pool keeps working
 * exactly as before for the first path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('committee_members', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('committee_members', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreignId('committee_submission_id')->nullable()->after('position_id')
                ->constrained()->nullOnDelete();
            $table->foreignId('committee_position_id')->nullable()->after('committee_submission_id')
                ->constrained()->restrictOnDelete();
        });

        Schema::table('committee_members', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('committee_members', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['committee_submission_id']);
            $table->dropForeign(['committee_position_id']);
            $table->dropColumn(['committee_submission_id', 'committee_position_id']);
        });

        Schema::table('committee_members', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
