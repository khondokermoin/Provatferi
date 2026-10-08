<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Money received (or, for a registration fee only, waived), recorded by one admin and verified by another action.
 *
 * `category` (Membership task 4): registration — an application's registration fee (payable: the application);
 * monthly_contribution — towards the monthly dues of a membership (payable: the membership; `membership_due_id` the
 * month it was recorded for, NULL for an advance); voluntary — a gift towards a membership, never applied to dues;
 * other. A monthly payment counts only once VERIFIED; recording it changes nothing on any due. An unverified monthly
 * entry recorded in error is cancelled (status cancelled), never deleted.
 */
class Payment extends Model
{
    public const STATUSES = ['pending' => 'অপেক্ষমাণ', 'paid' => 'পরিশোধিত', 'waived' => 'মওকুফ', 'cancelled' => 'বাতিল'];

    public const CATEGORY_REGISTRATION = 'registration';

    public const CATEGORY_MONTHLY = 'monthly_contribution';

    public const CATEGORY_VOLUNTARY = 'voluntary';

    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [self::CATEGORY_REGISTRATION, self::CATEGORY_MONTHLY, self::CATEGORY_VOLUNTARY, self::CATEGORY_OTHER];

    /** How money was received — always recorded by hand: there is no online payment. */
    public const METHODS = ['cash', 'bank_transfer', 'mobile_banking'];

    protected $fillable = [
        'payable_type', 'payable_id', 'category', 'membership_due_id', 'membership_type_id', 'amount_expected', 'amount_received',
        'method', 'received_at', 'received_by', 'reference', 'note', 'status',
        'waiver_reason', 'waived_by', 'verified_at', 'verified_by', 'cancelled_at', 'cancelled_by', 'cancellation_reason',
    ];

    /**
     * What an issued receipt has fixed (Membership task 5): once a payment has a receipt, none of this can change —
     * the receipt carries these facts, and the payment must not drift away from them.
     */
    public const RECEIPT_FROZEN = [
        'payable_type', 'payable_id', 'category', 'membership_due_id', 'amount_received', 'received_at', 'method', 'reference',
        'status', 'verified_at', 'verified_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_expected' => 'decimal:2',
            'amount_received' => 'decimal:2',
            'received_at' => 'date',
            'verified_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $payment): void {
            if ($payment->isDirty(self::RECEIPT_FROZEN) && $payment->receipt()->exists()) {
                throw new LogicException("Payment {$payment->id} has an official receipt: its amount, date, method, reference and status can no longer change.");
            }
        });
    }

    /** The official receipt issued when this payment was verified; null for a payment that is not (yet) verified money. */
    public function receipt(): HasOne
    {
        return $this->hasOne(PaymentReceipt::class);
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /** The month a monthly payment was recorded for (null for an advance or any other payment). */
    public function due(): BelongsTo
    {
        return $this->belongsTo(MembershipDue::class, 'membership_due_id');
    }

    /** Where this payment's verified money went, month by month. */
    public function allocations(): HasMany
    {
        return $this->hasMany(MembershipDueAllocation::class);
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Recorded, not yet verified, not cancelled. */
    public function isAwaitingVerification(): bool
    {
        return $this->status === 'paid' && $this->verified_at === null;
    }

    public function membershipType(): BelongsTo
    {
        return $this->belongsTo(MembershipType::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isSatisfied(): bool
    {
        return $this->status === 'waived' || ($this->status === 'paid' && $this->verified_at !== null);
    }
}
