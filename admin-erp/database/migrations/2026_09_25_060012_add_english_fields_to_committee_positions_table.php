<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 bilingual groundwork — see 2026_09_25_060001's docblock.
 * committee_positions.name is the other half of CommitteeMember::positionTitle()
 * (alongside organizational_positions, covered separately) — a member's
 * displayed position title comes from whichever of the two tables actually
 * applies to that seat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('committee_positions', function (Blueprint $table) {
            $table->string('name_en')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('committee_positions', function (Blueprint $table) {
            $table->dropColumn('name_en');
        });
    }
};
