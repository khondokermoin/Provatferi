<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §14's moderation gate: a member editing an approved public field must not
 * immediately overwrite what's live. Each edit inserts a new row here
 * (status=pending); the version with `is_current_live=true` is what the
 * public directory actually renders, and it only moves to the newer row
 * once an admin approves it — never on submission alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_member_profile_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending'); // pending|approved|rejected
            $table->string('photo_path')->nullable();
            $table->text('bio')->nullable();
            $table->string('profession')->nullable();
            $table->string('facebook_url')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->string('website_url')->nullable();
            $table->boolean('is_current_live')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'is_current_live']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_member_profile_versions');
    }
};
