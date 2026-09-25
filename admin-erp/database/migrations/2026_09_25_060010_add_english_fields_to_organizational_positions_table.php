<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 bilingual groundwork — see 2026_09_25_060001's docblock.
 * organizational_positions.name is surfaced via CommitteeMember::positionTitle()
 * (a derived gap, found by reading that method — not in the original
 * explicit table list, but real: this is one of the two tables
 * positionTitle() can read from, the other being committee_positions,
 * covered separately below).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizational_positions', function (Blueprint $table) {
            $table->string('name_en')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('organizational_positions', function (Blueprint $table) {
            $table->dropColumn('name_en');
        });
    }
};
