<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobApplication extends Model
{
    public const STATUSES = ['submitted' => 'Submitted', 'shortlisted' => 'Shortlisted', 'rejected' => 'Rejected', 'selected' => 'Selected'];

    protected $fillable = [
        'application_no', 'job_posting_id', 'applicant_name', 'applicant_email', 'applicant_phone',
        'cv_path', 'photo_path', 'cover_note', 'status', 'interview_at', 'interview_location',
        'interview_notes', 'reviewed_by',
    ];

    protected function casts(): array
    {
        return ['interview_at' => 'datetime'];
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }
}
