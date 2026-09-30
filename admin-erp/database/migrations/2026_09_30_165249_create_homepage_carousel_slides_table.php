<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: an admin-managed, ordered set of image slides shown in a new
 * carousel section at the top of the public homepage, above the existing
 * static hero (see institutional/app/[locale]/(site)/page.tsx — the hero
 * itself is unchanged). image_path lives on the 'public' disk (promoted via
 * PhotoUploadService, same as approved member/committee photos): a slide is
 * always meant to be public once created, unlike a Notice's cover image,
 * which can belong to an unpublished draft and so stays gated on the private
 * disk. link_url/link_label are optional together — a slide can be a pure
 * image, or a clickable one with a labelled destination.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homepage_carousel_slides', function (Blueprint $table) {
            $table->id();
            $table->string('image_path');
            $table->string('title')->nullable();
            $table->string('title_en')->nullable();
            $table->string('alt_text')->nullable();
            $table->string('alt_text_en')->nullable();
            $table->string('link_url', 500)->nullable();
            $table->string('link_label', 100)->nullable();
            $table->string('link_label_en', 100)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active'); // active|inactive
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homepage_carousel_slides');
    }
};
