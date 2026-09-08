<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->foreignId('organization_unit_id')->nullable()->constrained('organizational_units')->nullOnDelete();
            $table->string('department')->nullable();
            $table->text('description');
            $table->text('requirements')->nullable();
            $table->string('employment_type', 30)->nullable(); // full_time|part_time|volunteer|contract
            $table->string('salary_range')->nullable();
            $table->date('application_deadline')->nullable();
            $table->string('status', 30)->default('draft'); // draft|open|closed
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('job_applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_no')->unique();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->string('applicant_name');
            $table->string('applicant_email');
            $table->string('applicant_phone', 30)->nullable();
            $table->string('cv_path')->nullable();
            $table->string('photo_path')->nullable();
            $table->text('cover_note')->nullable();
            $table->string('status', 30)->default('submitted'); // submitted|shortlisted|rejected|selected
            $table->dateTime('interview_at')->nullable();
            $table->string('interview_location')->nullable();
            $table->text('interview_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_applications');
        Schema::dropIfExists('job_postings');
    }
};
