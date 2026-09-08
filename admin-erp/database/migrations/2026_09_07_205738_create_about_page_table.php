<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Singleton table (one row) for the About page's distinct named fields.
 * Deliberately separate columns rather than content_blocks rows, since these
 * fields are a fixed structured entity, not an open-ended key/value list.
 * Mission and Vision stay as individual content_blocks rows — they're each a
 * single long-form field with no internal structure of their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('about_page', function (Blueprint $table) {
            $table->id();
            $table->text('introduction')->nullable();
            $table->text('description')->nullable();
            $table->text('history')->nullable();
            $table->text('why_exists')->nullable();
            $table->text('identity_explanation')->nullable();
            $table->string('registration_status')->nullable();
            $table->boolean('is_published')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('about_page');
    }
};
