<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    public const STATUSES = ['pending' => 'অপেক্ষমাণ', 'paid' => 'পরিশোধিত', 'waived' => 'মওকুফ'];

    protected $fillable = [
        'payable_type', 'payable_id', 'membership_type_id', 'amount_expected', 'amount_received',
        'method', 'received_at', 'received_by', 'reference', 'note', 'status',
        'waiver_reason', 'waived_by', 'verified_at', 'verified_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_expected' => 'decimal:2',
            'amount_received' => 'decimal:2',
            'received_at' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
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
