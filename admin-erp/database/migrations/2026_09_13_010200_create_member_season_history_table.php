<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A member's participation in a season is historical and additive — a new
 * season never overwrites an old row here. One row per (member, season):
 * the member's own original join season, plus one more row for every later
 * renewal/special-campaign participation. This table alone answers "which
 * seasons has this member ever registered in" — it is not membership status
 * (see `memberships.status`) and a season closing never expires a member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_season_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_season_id')->constrained()->restrictOnDelete();
            $table->foreignId('membership_application_id')->nullable()->constrained()->nullOnDelete();
            $table->date('joined_at');
            $table->timestamps();

            $table->unique(['member_id', 'membership_season_id'], 'member_season_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_season_history');
    }
};
