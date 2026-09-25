<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (bilingual public site, provatferi.org/en) — Increment 1: silent DB
 * groundwork. See plan §C. Purely additive nullable `_en` siblings, same
 * base type as each Bangla column — the exact pattern already used by
 * membership_seasons.name_en and committee_submissions.name_en, extended
 * here rather than reinvented. NULL is a valid, expected, permanent state
 * (a record with no English translation yet, or ever) — never backfilled,
 * never required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('title_en')->nullable()->after('title');
            $table->string('summary_en', 500)->nullable()->after('summary');
            $table->text('description_en')->nullable()->after('description');
            $table->text('objective_en')->nullable()->after('objective');
            $table->text('what_happened_en')->nullable()->after('what_happened');
            $table->text('outcomes_en')->nullable()->after('outcomes');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['title_en', 'summary_en', 'description_en', 'objective_en', 'what_happened_en', 'outcomes_en']);
        });
    }
};
