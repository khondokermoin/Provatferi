<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of a membership type's fees, valid for a period of organisation-calendar days (both ends inclusive).
 *
 * APPEND-ONLY. A fee change is a new row; the amounts of a row are never edited. The only writes after creation are
 * (a) `effective_until`, maintained by MembershipFeePolicyService so the timeline stays contiguous, and (b) cancelling
 * a policy that has not started yet. All of that lives in the service — nothing should `update()` this model directly.
 *
 * Amounts are decimal(10,2) strings ("500.00"); see App\Support\Money. Dates are plain Y-m-d days in
 * config('membership.timezone'); compare them as strings via fromDate()/untilDate(), never by converting timezones.
 */
class MembershipFeePolicy extends Model
{
    protected $fillable = [
        'membership_type_id', 'registration_fee', 'monthly_contribution', 'effective_from', 'effective_until',
        'active', 'created_by', 'note', 'cancelled_at', 'cancelled_by', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'registration_fee' => 'decimal:2',
            'monthly_contribution' => 'decimal:2',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'active' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    public function membershipType(): BelongsTo
    {
        return $this->belongsTo(MembershipType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Applications that were quoted this policy. A referenced policy is part of financial history. */
    public function applications(): HasMany
    {
        return $this->hasMany(MembershipApplication::class, 'fee_policy_id');
    }

    /** First day this policy applies, as 'Y-m-d'. */
    public function fromDate(): string
    {
        return $this->effective_from->toDateString();
    }

    /** Last day this policy applies, as 'Y-m-d', or null while it is open-ended. */
    public function untilDate(): ?string
    {
        return $this->effective_until?->toDateString();
    }

    /** Whether the policy covers the given organisation-calendar day (a cancelled policy covers none). */
    public function coversDate(string $date): bool
    {
        return $this->active
            && $this->fromDate() <= $date
            && ($this->untilDate() === null || $this->untilDate() >= $date);
    }

    /**
     * 'cancelled' | 'scheduled' (not started) | 'current' (covers today) | 'past' (ended before today).
     * Y-m-d strings compare correctly as strings.
     */
    public function statusOn(string $today): string
    {
        if (! $this->active) {
            return 'cancelled';
        }
        if ($this->fromDate() > $today) {
            return 'scheduled';
        }
        if ($this->untilDate() !== null && $this->untilDate() < $today) {
            return 'past';
        }

        return 'current';
    }
}
