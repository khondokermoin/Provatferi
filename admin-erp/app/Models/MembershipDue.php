<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One month of a membership's monthly contribution (Membership task 4; docs/MEMBERSHIP_DUES.md).
 *
 * The amount was assessed once, from the fee policy that applied to the month, and is never recalculated: a later fee
 * change, a new policy, a re-run of generation — none of them touch an existing row's amount, month, membership or
 * policy (refused below). What changes over time is how much of it is settled: `paid_amount` (verified money,
 * allocated by App\Services\MembershipDueLedger) and `waived_amount` (an admin's waiver with a reason). Rows are
 * written only by that service, inside a transaction holding the membership's row lock.
 *
 * `status` is due / partially_paid / paid / waived. "Overdue" is not stored: a due is overdue once its month has ended
 * on the organisation's calendar (today is after `due_date`, the month's last day) with something still outstanding.
 */
class MembershipDue extends Model
{
    public const STATUSES = ['due', 'partially_paid', 'paid', 'waived'];

    /** What a due can show: its stored status, or "overdue" when its month has passed with money outstanding. */
    public const DISPLAY_STATES = ['due', 'partially_paid', 'paid', 'waived', 'overdue'];

    private const ASSESSED = ['membership_id', 'membership_fee_policy_id', 'period_year', 'period_month', 'due_date', 'amount'];

    protected $fillable = [
        'membership_id', 'membership_fee_policy_id', 'period_year', 'period_month', 'due_date', 'amount',
        'paid_amount', 'waived_amount', 'status', 'generated_at', 'paid_at', 'note',
    ];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer', 'period_month' => 'integer', 'due_date' => 'date',
            'amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'waived_amount' => 'decimal:2', 'outstanding_amount' => 'decimal:2',
            'generated_at' => 'datetime', 'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $due): void {
            foreach (self::ASSESSED as $field) {
                if ($due->isDirty($field)) {
                    throw new LogicException("A monthly due is never reassessed ({$field} of due {$due->id}).");
                }
            }
        });
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function feePolicy(): BelongsTo
    {
        return $this->belongsTo(MembershipFeePolicy::class, 'membership_fee_policy_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(MembershipDueAllocation::class);
    }

    /** Payments recorded FOR this month (where the money actually went is in allocations). */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'membership_due_id');
    }

    /** "2026-10" — the month this due is for. */
    public function period(): string
    {
        return sprintf('%04d-%02d', $this->period_year, $this->period_month);
    }

    public function amountPaisa(): int
    {
        return Money::toPaisa((string) $this->amount) ?? 0;
    }

    public function paidPaisa(): int
    {
        return Money::toPaisa((string) $this->paid_amount) ?? 0;
    }

    public function waivedPaisa(): int
    {
        return Money::toPaisa((string) $this->waived_amount) ?? 0;
    }

    /** What is still owed, from the three amounts as they are in memory (never from a stale computed column). */
    public function outstandingPaisa(): int
    {
        return max(0, $this->amountPaisa() - $this->paidPaisa() - $this->waivedPaisa());
    }

    public function outstanding(): string
    {
        return Money::fromPaisa($this->outstandingPaisa());
    }

    /** Overdue: the month has ended on the organisation's calendar ($today 'Y-m-d' is after its last day) and money is owed. */
    public function isOverdue(string $today): bool
    {
        return $this->outstandingPaisa() > 0 && $this->due_date->toDateString() < $today;
    }

    /** The status to show: overdue when it is, otherwise the stored status. */
    public function displayState(string $today): string
    {
        return $this->isOverdue($today) ? 'overdue' : $this->status;
    }

    /**
     * Re-derives `status` and `paid_at` from the amounts. Fully settled: "waived" when no money was paid at all, else
     * "paid" (a part may have been waived). Not settled: "partially_paid" once some verified money is in, else "due".
     */
    public function settleStatus(): void
    {
        if ($this->outstandingPaisa() === 0) {
            $this->status = $this->paidPaisa() === 0 ? 'waived' : 'paid';
            if ($this->status === 'paid' && $this->paid_at === null) {
                $this->paid_at = now();
            }

            return;
        }

        $this->status = $this->paidPaisa() > 0 ? 'partially_paid' : 'due';
        $this->paid_at = null;
    }
}
