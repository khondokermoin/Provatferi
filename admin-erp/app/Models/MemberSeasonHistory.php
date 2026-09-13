<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only — never overwritten when a new season opens (§15). */
class MemberSeasonHistory extends Model
{
    // Laravel's default pluralization would guess 'member_season_histories'; the
    // migration deliberately named it 'member_season_history' instead.
    protected $table = 'member_season_history';

    protected $fillable = ['member_id', 'membership_season_id', 'membership_application_id', 'joined_at'];

    protected function casts(): array
    {
        return ['joined_at' => 'date'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(MembershipSeason::class, 'membership_season_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(MembershipApplication::class);
    }
}
