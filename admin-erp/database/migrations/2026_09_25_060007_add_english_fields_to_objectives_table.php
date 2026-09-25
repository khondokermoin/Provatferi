<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 2 bilingual groundwork — see 2026_09_25_060001's docblock. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('objectives', function (Blueprint $table) {
            $table->string('title_en')->nullable()->after('title');
            $table->text('body_en')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('objectives', function (Blueprint $table) {
            $table->dropColumn(['title_en', 'body_en']);
        });
    }
};
