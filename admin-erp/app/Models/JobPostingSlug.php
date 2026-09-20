<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §3: one row per slug a JobPosting has ever been given up — never its
 * current slug, only retired ones. Written by RecruitmentController at the
 * moment an admin changes a posting's slug, and read by
 * JobPostingController::show() when the current table has no direct match,
 * so an old shared link keeps resolving permanently rather than for as long
 * as someone remembers to configure a redirect.
 */
class JobPostingSlug extends Model
{
    /** Written once and never edited — there is no "updated" state for a retired slug. */
    public const UPDATED_AT = null;

    protected $fillable = ['job_posting_id', 'slug'];

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }
}
