<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public committee-member application — mirrors job_applications'
 * proven shape (inline applicant identity, no required `users`/`members`
 * row) rather than inventing a new one. `member_id` is the OPTIONAL link an
 * admin may set after verifying the submitter is already an approved member
 * (§18) — never automatic, never required. Correction/resubmission reuses
 * the same signed+hashed-token pattern as committee_registration_links,
 * scoped to this one submission (§27); the previous token is overwritten
 * (not appended) whenever a new one is issued, satisfying "old token
 * invalidated when replaced". `photo_path` is the private original;
 * `photo_approved_path` is only ever set once, on approval, to the
 * public-safe derivative (§38) — never the reverse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('committee_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('committee_id')->constrained()->restrictOnDelete();
            $table->foreignId('committee_registration_link_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('committee_position_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();

            $table->string('full_name');
            $table->string('name_en')->nullable();
            $table->string('email');
            $table->string('phone');
            $table->string('photo_path')->nullable();
            $table->string('photo_approved_path')->nullable();
            $table->text('bio')->nullable();
            $table->text('provatferi_comment');
            $table->string('facebook_url')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->string('website_url')->nullable();
            $table->boolean('publishing_consent')->default(false);
            $table->boolean('accuracy_declaration')->default(false);

            $table->string('status', 20)->default('pending'); // pending|correction_requested|approved|rejected|unpublished
            $table->text('admin_note')->nullable();

            $table->string('correction_token_hash')->nullable()->unique();
            $table->timestamp('correction_expires_at')->nullable();
            $table->timestamp('correction_used_at')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('committee_submissions');
    }
};
