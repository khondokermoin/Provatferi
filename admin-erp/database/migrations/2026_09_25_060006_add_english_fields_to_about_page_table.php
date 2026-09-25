<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 2 bilingual groundwork — see 2026_09_25_060001's docblock. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('about_page', function (Blueprint $table) {
            $table->text('introduction_en')->nullable()->after('introduction');
            $table->text('description_en')->nullable()->after('description');
            $table->text('history_en')->nullable()->after('history');
            $table->text('why_exists_en')->nullable()->after('why_exists');
            $table->text('identity_explanation_en')->nullable()->after('identity_explanation');
        });
    }

    public function down(): void
    {
        Schema::table('about_page', function (Blueprint $table) {
            $table->dropColumn(['introduction_en', 'description_en', 'history_en', 'why_exists_en', 'identity_explanation_en']);
        });
    }
};
