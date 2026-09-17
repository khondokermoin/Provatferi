<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §19: whether a posting takes applications through the website form is an
 * admin decision per posting ("আবেদন গ্রহণ করা হবে"), not something a
 * developer hardcodes. The public contract derives the apply URL from the
 * posting's own slug when this is on, so a linked notice can resolve the
 * form without anyone writing a route into content.
 *
 * Defaults to false: an existing posting keeps behaving exactly as it did
 * until someone deliberately opens it for online applications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->boolean('accepts_applications')->default(false)->after('application_mode');
        });
    }

    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropColumn('accepts_applications');
        });
    }
};
