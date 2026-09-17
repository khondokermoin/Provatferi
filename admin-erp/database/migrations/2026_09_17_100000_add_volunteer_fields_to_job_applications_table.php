<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The volunteer interest form writes into the EXISTING job_applications
 * table rather than a second application system: an applicant is already
 * modelled inline here (name/email/phone, no users row), and a volunteer
 * call is already a job_posting. Only the fields a volunteer form collects
 * and recruitment never did are added, all nullable so every existing row
 * stays valid.
 *
 * The status vocabulary also widens (§6): submitted → under_review →
 * contacted → shortlisted → accepted / not_selected, plus withdrawn and
 * archived. The two legacy values are renamed in place, since they mean
 * exactly the new ones and nothing should be left speaking the old
 * vocabulary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $table->string('district', 120)->nullable()->after('applicant_phone');
            $table->string('current_location')->nullable()->after('district');
            $table->string('profession')->nullable()->after('current_location');
            $table->text('experience')->nullable()->after('profession');
            // Selected skill keys (JobApplication::SKILLS). Stored as JSON
            // rather than a pivot table: this is a self-declared interest
            // list on one application, never an entity of its own.
            $table->json('skills')->nullable()->after('experience');
            $table->text('other_skills')->nullable()->after('skills');
            $table->text('contribution')->nullable()->after('other_skills');
            $table->string('linkedin_url')->nullable()->after('contribution');
            $table->string('facebook_url')->nullable()->after('linkedin_url');
            $table->string('portfolio_url')->nullable()->after('facebook_url');
            $table->string('availability', 150)->nullable()->after('portfolio_url');
            $table->string('preferred_contact', 30)->nullable()->after('availability');
            $table->boolean('accuracy_declaration')->default(false)->after('preferred_contact');
            $table->boolean('privacy_consent')->default(false)->after('accuracy_declaration');
            $table->boolean('contact_consent')->default(false)->after('privacy_consent');
            // Admin-only, never part of any public contract.
            $table->text('internal_note')->nullable()->after('interview_notes');
            $table->timestamp('submitted_at')->nullable()->after('internal_note');

            $table->index('district');
        });

        DB::table('job_applications')->where('status', 'rejected')->update(['status' => 'not_selected']);
        DB::table('job_applications')->where('status', 'selected')->update(['status' => 'accepted']);
    }

    public function down(): void
    {
        DB::table('job_applications')->where('status', 'not_selected')->update(['status' => 'rejected']);
        DB::table('job_applications')->where('status', 'accepted')->update(['status' => 'selected']);

        Schema::table('job_applications', function (Blueprint $table) {
            $table->dropIndex(['district']);
            $table->dropColumn([
                'district', 'current_location', 'profession', 'experience', 'skills', 'other_skills',
                'contribution', 'linkedin_url', 'facebook_url', 'portfolio_url', 'availability',
                'preferred_contact', 'accuracy_declaration', 'privacy_consent', 'contact_consent',
                'internal_note', 'submitted_at',
            ]);
        });
    }
};
