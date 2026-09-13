<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §38's private-original/public-approved-derivative split was missed when
 * this table was first created — `photo_path` alone would have meant a
 * member's pending (not-yet-approved) profile photo already sitting at a
 * publicly-guessable path before any admin reviewed it, exactly what §38
 * forbids. `photo_path` is now always the private original (as it already
 * is for CommitteeSubmission); this new column is set only by
 * approveAndPublish(), mirroring CommitteeSubmissionController::approve().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_member_profile_versions', function (Blueprint $table) {
            $table->string('photo_approved_path')->nullable()->after('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('public_member_profile_versions', function (Blueprint $table) {
            $table->dropColumn('photo_approved_path');
        });
    }
};
