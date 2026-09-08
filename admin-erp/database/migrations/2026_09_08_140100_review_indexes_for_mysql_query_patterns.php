<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL index review (2026-09-08). Each composite below replaces a
 * single-column `status` index that left MySQL doing a filesort for the
 * ORDER BY, and each is backed by a query that actually exists in the code:
 *
 *   Api\V1\ActivityController::index      status='published' ORDER BY start_datetime DESC
 *   Admin\ActivityController::index       status=?           ORDER BY start_datetime DESC
 *   Api\V1\JobPostingController::index    status='open'      ORDER BY published_at DESC
 *   Admin\MembershipController::index     status=?           ORDER BY created_at DESC
 *
 * The old single-column indexes are dropped rather than kept alongside:
 * `(status, x)` already serves `WHERE status = ?` via its leftmost prefix,
 * so keeping both would just be write overhead.
 *
 * membership_applications had no status index at all, unlike every sibling
 * table — that gap is closed here.
 *
 * Deliberately NOT indexed: sort_order and slug-ordering columns on the
 * small lookup tables (activity_types, membership_types). They hold a
 * handful of rows and are always read in full; an index there is cost
 * without benefit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex('activities_status_index');
            $table->index(['status', 'start_datetime'], 'activities_status_start_datetime_index');
        });

        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropIndex('job_postings_status_index');
            $table->index(['status', 'published_at'], 'job_postings_status_published_at_index');
        });

        Schema::table('membership_applications', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'membership_applications_status_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex('activities_status_start_datetime_index');
            $table->index('status', 'activities_status_index');
        });

        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropIndex('job_postings_status_published_at_index');
            $table->index('status', 'job_postings_status_index');
        });

        Schema::table('membership_applications', function (Blueprint $table) {
            $table->dropIndex('membership_applications_status_created_at_index');
        });
    }
};
