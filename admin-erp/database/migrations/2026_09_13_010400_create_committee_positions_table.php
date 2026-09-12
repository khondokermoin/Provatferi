<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deliberately separate from `organizational_positions`, which is shared
 * across every committee an organizational unit ever has. A committee's own
 * position list (§21) must not be fixed-size or shared — each committee
 * defines however many positions it needs, independent of any other
 * committee's list, without touching the existing org-chart feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('committee_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('committee_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('allow_duplicates')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['committee_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('committee_positions');
    }
};
