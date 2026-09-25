<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 2 bilingual groundwork — see 2026_09_25_060001's docblock. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->string('title_en')->nullable()->after('title');
            $table->string('summary_en', 500)->nullable()->after('summary');
            $table->text('description_en')->nullable()->after('description');
            $table->text('requirements_en')->nullable()->after('requirements');
        });
    }

    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropColumn(['title_en', 'summary_en', 'description_en', 'requirements_en']);
        });
    }
};
