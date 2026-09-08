<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL-only audit (2026-09-08): every delete rule below was inherited from
 * the Phase 1A default of `cascadeOnDelete()` rather than chosen. On SQLite
 * this was mostly invisible; on MySQL it is a live data-loss path.
 *
 * In each case the controller ALREADY blocks the delete with a friendly
 * message (ActivityTypeController, MembershipTypeController,
 * RecruitmentController, PositionController), so the database was silently
 * contradicting the application's stated intent. RESTRICT makes the database
 * a safety net behind the guard instead of a trapdoor underneath it.
 *
 * `activities.organization_unit_id` is the one exception: it became nullable
 * in Phase 1C, and an activity is a historical record that must outlive the
 * unit that ran it — so it follows job_postings and uses SET NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropForeign(['activity_type_id']);
            $table->foreign('activity_type_id')->references('id')->on('activity_types')->restrictOnDelete();

            $table->dropForeign(['organization_unit_id']);
            $table->foreign('organization_unit_id')->references('id')->on('organizational_units')->nullOnDelete();
        });

        Schema::table('membership_applications', function (Blueprint $table) {
            $table->dropForeign(['membership_type_id']);
            $table->foreign('membership_type_id')->references('id')->on('membership_types')->restrictOnDelete();
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropForeign(['membership_type_id']);
            $table->foreign('membership_type_id')->references('id')->on('membership_types')->restrictOnDelete();
        });

        Schema::table('job_applications', function (Blueprint $table) {
            $table->dropForeign(['job_posting_id']);
            $table->foreign('job_posting_id')->references('id')->on('job_postings')->restrictOnDelete();
        });

        Schema::table('committee_members', function (Blueprint $table) {
            $table->dropForeign(['position_id']);
            $table->foreign('position_id')->references('id')->on('organizational_positions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropForeign(['activity_type_id']);
            $table->foreign('activity_type_id')->references('id')->on('activity_types')->cascadeOnDelete();

            $table->dropForeign(['organization_unit_id']);
            $table->foreign('organization_unit_id')->references('id')->on('organizational_units')->cascadeOnDelete();
        });

        Schema::table('membership_applications', function (Blueprint $table) {
            $table->dropForeign(['membership_type_id']);
            $table->foreign('membership_type_id')->references('id')->on('membership_types')->cascadeOnDelete();
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropForeign(['membership_type_id']);
            $table->foreign('membership_type_id')->references('id')->on('membership_types')->cascadeOnDelete();
        });

        Schema::table('job_applications', function (Blueprint $table) {
            $table->dropForeign(['job_posting_id']);
            $table->foreign('job_posting_id')->references('id')->on('job_postings')->cascadeOnDelete();
        });

        Schema::table('committee_members', function (Blueprint $table) {
            $table->dropForeign(['position_id']);
            $table->foreign('position_id')->references('id')->on('organizational_positions')->cascadeOnDelete();
        });
    }
};
