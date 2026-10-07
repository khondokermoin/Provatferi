<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * A row of the member registry: one person's membership, created only by approving a MembershipApplication
 * (App\Services\MembershipApprovalService). The person — contact details, portal account, public profile — is the
 * linked Member; this row carries the membership itself: its number, type, joining date and registry status.
 */
class Membership extends Model
{
    public const STATUSES = [
        'active' => 'সক্রিয়', 'inactive' => 'নিষ্ক্রিয়', 'suspended' => 'স্থগিত', 'expired' => 'মেয়াদোত্তীর্ণ', 'archived' => 'সংরক্ষিত',
    ];

    /**
     * The registry's status actions — the ONLY way a membership's status changes after approval (each one is recorded
     * in the history with who, when and why: `event` is its approval_history action). `from`: the statuses it may be
     * taken from; `reason`: whether a reason is required. Nothing ever deletes a membership: "archived" keeps the row
     * and its whole history, and can be reactivated.
     */
    public const STATUS_ACTIONS = [
        'activate' => ['from' => ['inactive', 'expired'], 'to' => 'active', 'reason' => false, 'event' => 'activated'],
        'suspend' => ['from' => ['active'], 'to' => 'suspended', 'reason' => true, 'event' => 'suspended'],
        'reactivate' => ['from' => ['suspended', 'archived'], 'to' => 'active', 'reason' => false, 'event' => 'reactivated'],
        'archive' => ['from' => ['active', 'suspended', 'inactive', 'expired'], 'to' => 'archived', 'reason' => true, 'event' => 'archived'],
    ];

    protected $fillable = [
        'membership_application_id', 'user_id', 'member_id', 'membership_type_id', 'member_code', 'start_date', 'expiry_date',
        'status', 'notes', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'start_date' => 'date', 'expiry_date' => 'date'];
    }

    /**
     * A member number is issued once, at approval (App\Services\MembershipNumbering), and never changes afterwards —
     * not with an edit, a status change, a profile change or anything else. Changing it is refused here.
     */
    protected static function booted(): void
    {
        static::updating(function (self $membership): void {
            $issued = $membership->getOriginal('member_code');
            if ($membership->isDirty('member_code') && is_string($issued) && $issued !== '') {
                throw new LogicException("A member number is permanent once issued ({$issued}).");
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** The account holder's display name, regardless of which identity path (§0) produced this membership. */
    public function holderName(): string
    {
        return $this->member?->name ?? $this->user?->name ?? '';
    }

    public function holderEmail(): string
    {
        return $this->member?->email ?? $this->user?->email ?? '';
    }

    public function membershipType(): BelongsTo
    {
        return $this->belongsTo(MembershipType::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(MembershipApplication::class, 'membership_application_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** The monthly dues ledger of this membership, one row per month owed (Membership task 4). */
    public function dues(): HasMany
    {
        return $this->hasMany(MembershipDue::class);
    }

    /** Money towards this membership itself: monthly contributions (and advances) and voluntary contributions. */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    /** Status actions and edits of this registry row (never shown publicly). */
    public function history(): HasMany
    {
        return $this->hasMany(ApprovalHistory::class, 'subject_id')->where('subject_type', self::class);
    }

    /** @return array<int, string> the status actions this membership's current status allows */
    public function availableStatusActions(): array
    {
        return array_keys(array_filter(self::STATUS_ACTIONS, fn (array $rule) => in_array($this->status, $rule['from'], true)));
    }
}
