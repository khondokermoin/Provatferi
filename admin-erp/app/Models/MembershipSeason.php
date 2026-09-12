<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MembershipSeason extends Model
{
    use SoftDeletes;

    public const CAMPAIGN_TYPES = ['regular' => 'নিয়মিত', 'special' => 'বিশেষ'];

    public const STATUSES = [
        'draft' => 'খসড়া',
        'scheduled' => 'নির্ধারিত',
        'open' => 'চলমান',
        'closed' => 'বন্ধ',
        'archived' => 'আর্কাইভ',
    ];

    protected $fillable = [
        'name', 'name_en', 'slug', 'campaign_type', 'opens_at', 'closes_at',
        'membership_period_months', 'description', 'status', 'form_config',
        'cash_payment_instructions', 'public_profile_opt_in', 'display_order', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'form_config' => 'array',
            'public_profile_opt_in' => 'boolean',
        ];
    }

    public function membershipTypes(): BelongsToMany
    {
        return $this->belongsToMany(MembershipType::class, 'membership_season_types');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(MembershipApplication::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Status is the admin's authoritative word — dates never silently flip
     * it (§3: "do not silently perform destructive business transitions").
     * This only tells the PUBLIC application form whether to accept
     * submissions right now; it never rewrites `status` itself.
     */
    public function acceptsApplicationsNow(): bool
    {
        if ($this->status !== 'open') {
            return false;
        }
        $now = now();
        if ($this->opens_at && $now->lt($this->opens_at)) {
            return false;
        }
        if ($this->closes_at && $now->gt($this->closes_at)) {
            return false;
        }

        return true;
    }

    /** A schedule-based suggestion for the admin UI only — never auto-applied (§3). */
    public function suggestedStatusFromDates(): ?string
    {
        if (!in_array($this->status, ['scheduled', 'open'], true)) {
            return null;
        }
        $now = now();
        if ($this->status === 'scheduled' && $this->opens_at && $now->gte($this->opens_at)) {
            return 'open';
        }
        if ($this->status === 'open' && $this->closes_at && $now->gt($this->closes_at)) {
            return 'closed';
        }

        return null;
    }
}
