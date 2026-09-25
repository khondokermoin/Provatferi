<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 bilingual groundwork — see 2026_09_25_060001's docblock.
 * organizational_units.name is surfaced verbatim as `organization_unit` in
 * both JobPostingController::present() and NoticeController::show() — a
 * derived gap found by reading those controllers, not in the original
 * explicit table list, but real: without this, a fully-translated job
 * posting or notice would still show its org unit's name in Bangla only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizational_units', function (Blueprint $table) {
            $table->string('name_en')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('organizational_units', function (Blueprint $table) {
            $table->dropColumn('name_en');
        });
    }
};
