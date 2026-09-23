<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-posting Required/Optional configuration for the volunteer application
 * form's non-core fields (profile photo, CV, availability, experience,
 * contribution, skills, district, current location, profession, preferred
 * contact). A single nullable JSON column, not ten booleans — one canonical
 * field list (JobPosting::CONFIGURABLE_APPLICATION_FIELDS) drives the admin
 * UI, the stored config and the generated validation rules alike.
 *
 * Nullable and additive: every existing posting has field_requirements NULL,
 * and JobPosting::resolvedFieldRequirements() merges that over
 * DEFAULT_FIELD_REQUIREMENTS — which mirrors today's hardcoded validation
 * exactly (photo/CV/availability/preferred_contact optional, everything else
 * required). No existing posting's behaviour changes until an admin
 * explicitly opens this posting's settings and changes something.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->json('field_requirements')->nullable()->after('accepts_applications');
        });
    }

    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropColumn('field_requirements');
        });
    }
};
