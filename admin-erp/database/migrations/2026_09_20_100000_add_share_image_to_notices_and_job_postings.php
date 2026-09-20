<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §12: a dedicated Open Graph / social-share image, separate from a notice's
 * existing `cover_image_path` (an in-page illustration, any aspect ratio) —
 * the share image is admin-instructed to be 1200x630 (1.91:1) specifically
 * for how link previews render, and job_postings never had an image field of
 * any kind before this.
 *
 * Purely additive: one nullable column on each of two existing tables,
 * nothing dropped, nothing backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notices', function (Blueprint $table) {
            $table->string('share_image_path')->nullable()->after('cover_image_path');
        });

        Schema::table('job_postings', function (Blueprint $table) {
            $table->string('share_image_path')->nullable()->after('salary_range');
        });
    }

    public function down(): void
    {
        Schema::table('notices', function (Blueprint $table) {
            $table->dropColumn('share_image_path');
        });

        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropColumn('share_image_path');
        });
    }
};
