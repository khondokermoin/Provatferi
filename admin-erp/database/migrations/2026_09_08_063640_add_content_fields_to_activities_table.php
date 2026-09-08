<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Institutional-depth fields for the public evidence-card/detail-page model.
 * `gallery` and `related_links` are JSON arrays — empty by default, never
 * pre-filled with placeholder entries. `status` itself is unchanged as a
 * column (still a plain string), but its *vocabulary* moves from an event-
 * lifecycle meaning (planned/ongoing/completed) to a publication-workflow
 * meaning (draft/published/archived) — enforced at the app layer only, since
 * no existing rows or code referenced the old values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('summary', 500)->nullable()->after('title');
            $table->string('hero_image_path')->nullable()->after('address');
            $table->text('what_happened')->nullable()->after('objective');
            $table->text('outcomes')->nullable()->after('what_happened');
            $table->json('gallery')->nullable()->after('outcomes');
            $table->json('related_links')->nullable()->after('gallery');
            $table->string('facebook_post_url')->nullable()->after('related_links');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['summary', 'hero_image_path', 'what_happened', 'outcomes', 'gallery', 'related_links', 'facebook_post_url']);
        });
    }
};
