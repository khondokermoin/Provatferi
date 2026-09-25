<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A committee's own position list — independent of any other committee's (§21). */
class CommitteePosition extends Model
{
    protected $fillable = ['committee_id', 'name', 'name_en', 'slug', 'display_order', 'allow_duplicates', 'status'];

    protected function casts(): array
    {
        return ['allow_duplicates' => 'boolean'];
    }

    public function committee(): BelongsTo
    {
        return $this->belongsTo(Committee::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(CommitteeSubmission::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(CommitteeMember::class, 'committee_position_id');
    }

    /** §23: warn (not block) when duplicates are disabled and a seat is already filled. */
    public function isOccupied(): bool
    {
        if ($this->allow_duplicates) {
            return false;
        }

        return $this->members()->where('status', 'active')->exists();
    }
}
