<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommitteeMember extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'committee_id', 'user_id', 'position_id', 'committee_submission_id', 'committee_position_id',
        'serial_no', 'start_date', 'end_date', 'status',
    ];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }

    public function committee(): BelongsTo
    {
        return $this->belongsTo(Committee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(OrganizationalPosition::class, 'position_id');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(CommitteeSubmission::class, 'committee_submission_id');
    }

    /** The committee-scoped position (§21), distinct from the org-unit-scoped `position()` above. */
    public function committeePosition(): BelongsTo
    {
        return $this->belongsTo(CommitteePosition::class, 'committee_position_id');
    }

    /** §32: the seat's display name/photo, whichever identity path (§0) produced it. */
    public function displayName(): string
    {
        return $this->submission?->full_name ?? $this->user?->name ?? '';
    }

    public function positionTitle(): string
    {
        return $this->committeePosition?->name ?? $this->position?->name ?? '';
    }
}
