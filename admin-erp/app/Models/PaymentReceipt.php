<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The official receipt of one VERIFIED payment (Membership task 5, 2026-10-08; docs/MEMBERSHIP_RECEIPTS.md).
 *
 * Written once, by App\Services\PaymentReceiptService, in the transaction that verifies the payment — and never again:
 * the number is permanent (issued from the `receipt:{year}` counter, never from an id), and every fact the printed
 * receipt shows is stored here as it was at that moment, so editing the member, the membership type or the fee policy
 * afterwards cannot change an issued receipt. The model refuses any change and any deletion; the payment it belongs to
 * cannot be deleted from under it (RESTRICT). A future reversal or refund is a separate document, not an edit of this.
 *
 * `purpose`: registration | monthly | advance | voluntary | other. `applied_amount` + `credit_amount` = `amount`:
 * what went to the purpose itself (the registration fee; the months in `lines`; the contribution) and what was held as
 * advance credit when the receipt was issued. `lines` are the months this payment was applied to AT ISSUE — credit
 * applied to later months as their dues are created is a ledger event, not part of this receipt.
 */
class PaymentReceipt extends Model
{
    /** Append-only: there is no updated_at. */
    public const UPDATED_AT = null;

    public const PURPOSES = ['registration', 'monthly', 'advance', 'voluntary', 'other'];

    protected $fillable = [
        'receipt_no', 'payment_id', 'purpose', 'amount', 'applied_amount', 'credit_amount', 'lines',
        'payment_date', 'method', 'reference',
        'payer_name', 'member_code', 'application_no', 'membership_type_name', 'membership_type_name_en',
        'received_by_name', 'verified_by_name', 'institution', 'issued_by', 'issued_at', 'issued_via',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'applied_amount' => 'decimal:2',
            'credit_amount' => 'decimal:2',
            'lines' => 'array',
            'institution' => 'array',
            'payment_date' => 'date',
            'issued_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $receipt): void {
            if ($receipt->isDirty()) {
                throw new LogicException("Receipt {$receipt->getOriginal('receipt_no')} is permanent: an issued receipt cannot be changed.");
            }
        });
        static::deleting(function (self $receipt): void {
            throw new LogicException("Receipt {$receipt->receipt_no} cannot be deleted: an issued receipt is a permanent record.");
        });
    }

    /** Routes address a receipt by its number, e.g. /admin/membership/receipts/PLCC-RCT-2026-000001. */
    public function getRouteKeyName(): string
    {
        return 'receipt_no';
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** The months this receipt's payment was applied to, as 'Y-m' keys, oldest first. @return array<int, string> */
    public function periods(): array
    {
        return array_values(array_map(fn (array $line) => (string) $line['period'], $this->lines ?? []));
    }
}
