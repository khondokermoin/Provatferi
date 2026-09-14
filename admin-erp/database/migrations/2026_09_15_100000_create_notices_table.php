<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A notice board separate from recruitment: most institutional notices
 * (urgent, tender, result, office order...) have nothing to do with a job.
 * A recruitment-derived notice links back through job_posting_id instead of
 * duplicating the posting, so the structured recruitment record stays the
 * single source for its employment terms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('notice_type', 30);
            $table->string('summary', 500)->nullable();
            $table->mediumText('body');
            $table->string('status', 20)->default('draft'); // draft|scheduled|published|archived
            $table->timestamp('published_at')->nullable();
            // Set the first time a notice is actually live. Once set, the notice
            // can only be archived (never deleted) and its public URL is locked.
            $table->timestamp('first_published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->foreignId('organization_unit_id')->nullable()->constrained('organizational_units')->nullOnDelete();
            $table->string('cover_image_path')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->string('action_url', 500)->nullable();
            $table->string('action_label', 100)->nullable();
            $table->foreignId('job_posting_id')->nullable()->unique()->constrained('job_postings')->nullOnDelete();
            $table->boolean('syncs_from_job_posting')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index('notice_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
    }
};
