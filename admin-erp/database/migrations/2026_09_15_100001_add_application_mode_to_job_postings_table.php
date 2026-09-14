<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "rolling" is an ongoing call with no closing date (e.g. a standing volunteer
 * call). Existing postings default to "fixed", which keeps their current
 * deadline behaviour unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->string('application_mode', 20)->default('fixed')->after('opening_date');
        });
    }

    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropColumn('application_mode');
        });
    }
};
