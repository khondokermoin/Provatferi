<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3: slug history for recruitment postings.
 *
 * Postings were slugged as Str::slug(title).'-'.Str::random(4) at creation and
 * were never editable, which is how the public URL became
 * "prvatfereer-swecchasebee-time-zukt-hoozar-ahwan-0rkb". Making the slug
 * editable is only safe if the old URL keeps working, so every slug a posting
 * has ever carried is recorded here and resolved on lookup.
 *
 * A history TABLE rather than a `previous_slug` column: a posting renamed
 * twice must keep BOTH earlier URLs alive, and a single column silently drops
 * the older one. "Do not break existing links" means all of them, not the most
 * recent.
 *
 * Purely additive — a new table, no change to job_postings, nothing dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_posting_slugs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();

            // Unique across history so a retired URL can never be handed to a
            // different posting later — that would silently redirect an old
            // shared link to unrelated content.
            $table->string('slug')->unique();

            // When the slug was retired. No updated_at: a history row is
            // written once and never edited.
            $table->timestamp('created_at')->nullable();

            $table->index('job_posting_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_posting_slugs');
    }
};
