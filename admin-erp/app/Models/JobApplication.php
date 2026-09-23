<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class JobApplication extends Model
{
    /**
     * §6: a volunteer interest form is not an employment contract, so the
     * vocabulary is about contact and consideration, not hiring verdicts.
     * Keys are the stored values; labels are what an admin reads.
     */
    public const STATUSES = [
        'submitted' => 'জমাকৃত',
        'under_review' => 'পর্যালোচনাধীন',
        'contacted' => 'যোগাযোগ করা হয়েছে',
        'shortlisted' => 'বাছাইকৃত',
        'accepted' => 'গৃহীত',
        'not_selected' => 'নির্বাচিত হয়নি',
        'withdrawn' => 'প্রত্যাহৃত',
        'archived' => 'সংরক্ষিত',
    ];

    /**
     * §4: the skill catalogue the public form offers as checkboxes, so an
     * applicant is never reduced to one free-text box. Keys are stored;
     * labels are shown. 'other' pairs with the other_skills free text.
     * Kept here rather than in a table — this is a fixed vocabulary the
     * form and the admin filter must agree on, not user-managed data.
     */
    public const SKILLS = [
        'ngo_liaison' => 'NGO / Foundation যোগাযোগ',
        'fundraising' => 'Fundraising / Donation / Sponsorship',
        'proposal_writing' => 'Proposal Writing',
        'report_writing' => 'Report Writing',
        'project_planning' => 'Project Planning',
        'project_management' => 'Project Management',
        'team_management' => 'Team Management',
        'training' => 'Training / Workshop',
        'event_management' => 'Event Management',
        'cultural_program' => 'Cultural Program',
        'media_content' => 'Media / Content',
        'graphic_design' => 'Graphic Design',
        'photography' => 'Photography / Video',
        'it_web' => 'IT / Website / Software',
        'social_media' => 'Social Media',
        'documentation' => 'Documentation',
        'partnership' => 'Partnership / Institutional Communication',
        'other' => 'অন্যান্য',
    ];

    public const PREFERRED_CONTACTS = [
        'phone' => 'ফোন কল',
        'whatsapp' => 'WhatsApp',
        'email' => 'ই-মেইল',
    ];

    /** Statuses after which a fresh application from the same person is a genuine re-application, not a duplicate. */
    public const REAPPLY_ALLOWED_AFTER = ['not_selected', 'withdrawn', 'archived'];

    protected $fillable = [
        'application_no', 'job_posting_id', 'applicant_name', 'applicant_email', 'applicant_phone',
        'district', 'current_location', 'profession', 'experience', 'skills', 'other_skills',
        'contribution', 'linkedin_url', 'facebook_url', 'portfolio_url', 'availability',
        'preferred_contact', 'accuracy_declaration', 'privacy_consent', 'contact_consent',
        'cv_path', 'photo_path', 'cover_note', 'status', 'interview_at', 'interview_location',
        'interview_notes', 'internal_note', 'submitted_at', 'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'interview_at' => 'datetime',
            'submitted_at' => 'datetime',
            'skills' => 'array',
            'accuracy_declaration' => 'boolean',
            'privacy_consent' => 'boolean',
            'contact_consent' => 'boolean',
        ];
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Mirrors MembershipApplication::generateApplicationNo()'s shape so every
     * identifier in this app reads the same way. Never used for
     * authorization — no public route resolves an application at all.
     */
    public static function generateApplicationNo(): string
    {
        $next = (self::query()->max('id') ?? 0) + 1;

        return 'VOL-'.now()->format('Y').'-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** @return array<int, string> */
    public function skillLabels(): array
    {
        return collect($this->skills ?? [])
            ->map(fn (string $key) => self::SKILLS[$key] ?? $key)
            ->values()
            ->all();
    }

    /**
     * Whether a photo genuinely exists RIGHT NOW on the private disk — not
     * merely whether photo_path is set in the database. These can disagree:
     * found 2026-09-24 that every deploy before this date's release-manager.php
     * fix silently orphaned previously-uploaded files on each atomic release
     * switch, leaving photo_path/cv_path intact in the DB while the file
     * itself was gone. Every view that conditionally renders a photo/CV
     * MUST call this (or cvFileExists()) rather than check the raw column —
     * checking the column alone is exactly what rendered a real <img> tag
     * pointing at a 404, i.e. a broken image, for those orphaned rows.
     */
    public function photoFileExists(): bool
    {
        return $this->photo_path !== null && Storage::disk('uploads_private')->exists($this->photo_path);
    }

    public function cvFileExists(): bool
    {
        return $this->cv_path !== null && Storage::disk('uploads_private')->exists($this->cv_path);
    }
}
