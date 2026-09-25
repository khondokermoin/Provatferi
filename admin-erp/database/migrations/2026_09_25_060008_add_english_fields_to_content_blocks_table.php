<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 bilingual groundwork — see 2026_09_25_060001's docblock. Covers
 * the about.mission / about.vision content_blocks rows (SettingsController's
 * about() reads both by key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_blocks', function (Blueprint $table) {
            $table->longText('body_en')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('content_blocks', function (Blueprint $table) {
            $table->dropColumn('body_en');
        });
    }
};
