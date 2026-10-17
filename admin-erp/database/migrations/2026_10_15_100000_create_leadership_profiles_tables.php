<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leadership / Key Persons (2026-10-15; docs/LEADERSHIP_SYSTEM.md): the institution's permanent, core people (founder,
 * executive director, director, advisor, …) as a domain of their own. It is NOT the Committee system: a committee is
 * term-bound and its members are tied to a term and a position; a leadership profile outlives every committee, so it has
 * its own tables and shares nothing with `committees`, `committee_members` or `organizational_positions`.
 *
 * Bangla is the base language (name and designation are required in Bangla); every `_en` column is optional and a
 * missing one is never invented — the public site shows the Bangla text with its "translation not available" note.
 * Text is plain text (paragraph breaks only), never HTML.
 *
 * status: active (public) | inactive (hidden, editable) | archived (hidden, kept for the record; restorable). There is no
 * delete: a profile is archived, so its public address and its history stay intact. display_order is the one canonical
 * order (ties broken by id, so it is always deterministic); `featured` only chooses the profiles shown prominently, it
 * never changes the order.
 *
 * The photo is stored twice on the public disk (a card size and a page size, both JPEG, metadata removed); the columns
 * hold the two paths and the size of the page-size image, so the public page can reserve its space.
 *
 * leadership_profile_slugs keeps every slug a profile has given up (written when an admin changes a slug), so an old
 * shared link keeps resolving — the same mechanism as job_posting_slugs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadership_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();

            $table->string('name_bn');
            $table->string('name_en')->nullable();
            $table->string('designation_bn');
            $table->string('designation_en')->nullable();

            $table->text('short_bio_bn')->nullable();
            $table->text('short_bio_en')->nullable();
            $table->text('full_bio_bn')->nullable();
            $table->text('full_bio_en')->nullable();
            $table->text('quote_bn')->nullable();
            $table->text('quote_en')->nullable();
            $table->string('expertise_bn')->nullable();
            $table->string('expertise_en')->nullable();
            $table->unsignedSmallInteger('associated_since_year')->nullable();

            $table->string('photo_path')->nullable();
            $table->string('photo_sm_path')->nullable();
            $table->unsignedSmallInteger('photo_width')->nullable();
            $table->unsignedSmallInteger('photo_height')->nullable();

            // Optional and public by design: shown on the profile page only when filled in.
            $table->string('public_email')->nullable();
            $table->string('public_phone', 40)->nullable();
            $table->string('facebook_url', 500)->nullable();
            $table->string('linkedin_url', 500)->nullable();
            $table->string('youtube_url', 500)->nullable();
            $table->string('website_url', 500)->nullable();

            $table->string('status', 16)->default('inactive'); // active | inactive | archived
            $table->boolean('featured')->default(false);
            $table->unsignedInteger('display_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'display_order']);
            $table->index(['status', 'featured', 'display_order']);
        });

        Schema::create('leadership_profile_slugs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leadership_profile_id')->constrained('leadership_profiles')->cascadeOnDelete();
            $table->string('slug', 80)->unique();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadership_profile_slugs');
        Schema::dropIfExists('leadership_profiles');
    }
};
