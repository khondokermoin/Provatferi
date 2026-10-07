<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part of one VERIFIED monthly payment applied to one monthly due (Membership task 4). `kind`: `payment` — the payment
 * was recorded for this month; `credit` — advance credit (money a payment's own month could not take, or an advance)
 * applied to this month later. Written only by App\Services\MembershipDueLedger, under the membership's row lock.
 */
class MembershipDueAllocation extends Model
{
    protected $fillable = ['membership_id', 'membership_due_id', 'payment_id', 'amount', 'kind', 'allocated_by', 'allocated_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'allocated_at' => 'datetime'];
    }

    public function due(): BelongsTo
    {
        return $this->belongsTo(MembershipDue::class, 'membership_due_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function allocator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }
}
