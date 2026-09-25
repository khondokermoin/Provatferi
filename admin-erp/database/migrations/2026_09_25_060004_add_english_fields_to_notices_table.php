<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 2 bilingual groundwork — see 2026_09_25_060001's docblock. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notices', function (Blueprint $table) {
            $table->string('title_en')->nullable()->after('title');
            $table->string('summary_en', 500)->nullable()->after('summary');
            $table->mediumText('body_en')->nullable()->after('body');
            $table->string('action_label_en', 100)->nullable()->after('action_label');
        });
    }

    public function down(): void
    {
        Schema::table('notices', function (Blueprint $table) {
            $table->dropColumn(['title_en', 'summary_en', 'body_en', 'action_label_en']);
        });
    }
};
