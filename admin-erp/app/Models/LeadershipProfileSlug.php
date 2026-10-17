<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per slug a LeadershipProfile has ever given up — never its current slug, only retired ones. Written by
 * LeadershipProfileController at the moment an admin changes a profile's slug, and read by the public API when no
 * profile has the requested slug, so an old shared link keeps resolving (the site answers it with a permanent redirect
 * to the current address). Same mechanism as JobPostingSlug.
 */
class LeadershipProfileSlug extends Model
{
    /** Written once and never edited. */
    public const UPDATED_AT = null;

    protected $fillable = ['leadership_profile_id', 'slug'];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(LeadershipProfile::class, 'leadership_profile_id');
    }
}
