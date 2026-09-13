<?php

use App\Models\Committee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Every other public content type in this app (activities, job_postings,
 * organizational_units, membership_seasons, committee_positions) has a slug
 * for public URLs — committees was the one outlier, needed now for §29's
 * per-committee public detail pages. Backfilled here rather than left null,
 * since committees already exist in production from Phase 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('committees', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
        });

        Committee::query()->whereNull('slug')->orderBy('id')->each(function (Committee $committee) {
            $base = Str::slug($committee->name).'-'.$committee->id;
            $committee->newQuery()->where('id', $committee->id)->update(['slug' => $base]);
        });

        Schema::table('committees', function (Blueprint $table) {
            $table->string('slug')->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('committees', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
