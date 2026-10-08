<?php

namespace App\Support;

use App\Models\MembershipApplication;
use App\Models\PaymentReceipt;

/**
 * Puts an issued receipt into words for one language (bn | en) — everything the receipt document shows, from the
 * receipt's own snapshot. The document is rendered under that language (App::setLocale around the render, like the
 * recruitment document), so __() and the AdminTime helpers (digits, month names, Dhaka clock) follow it.
 *
 * Member-entered text — the payer's name, the reference — is shown exactly as it was entered, never translated.
 * Raw enum values (monthly_contribution, bank_transfer …) never reach the page: every one goes through a label.
 */
final class ReceiptPresenter
{
    public function __construct(public readonly PaymentReceipt $receipt, public readonly string $lang)
    {
    }

    public function purposeLabel(): string
    {
        return __('admin.receipt.purpose.'.$this->receipt->purpose);
    }

    /** The membership type's name in the receipt's language; its Bangla name when no English one was ever written. */
    public function typeName(): ?string
    {
        $bn = $this->receipt->membership_type_name;
        $en = $this->receipt->membership_type_name_en;

        return $this->lang === 'en' ? ($en !== null && trim($en) !== '' ? $en : $bn) : $bn;
    }

    /**
     * The member number. The receipt of a registration fee is issued BEFORE approval, when no member number exists yet,
     * so it was stored without one; once the application is approved, the number it was given (permanent: member numbers
     * never change) is shown. Nothing else on the receipt is read from live data.
     */
    public function memberCode(): ?string
    {
        if ($this->receipt->member_code !== null) {
            return $this->receipt->member_code;
        }
        $payable = $this->receipt->payment?->payable;

        return $payable instanceof MembershipApplication ? $payable->membership?->member_code : null;
    }

    public function methodLabel(): ?string
    {
        $method = $this->receipt->method;
        if ($method === null || $method === '') {
            return null;
        }
        $key = 'admin.dues.method.'.$method;
        $label = __($key);

        return $label === $key ? ucfirst(str_replace('_', ' ', $method)) : $label;
    }

    /** The months the payment was applied to, as the receipt lists them. @return array<int, array{label: string, amount: string}> */
    public function lines(): array
    {
        return array_map(
            fn (array $line) => ['label' => calendar_month_year($line['period'].'-01'), 'amount' => bn_money($line['amount'])],
            $this->receipt->lines ?? [],
        );
    }

    /** The one month a monthly payment went to, when it went to exactly one (shown as "For the month"). */
    public function singleMonth(): ?string
    {
        $lines = $this->receipt->lines ?? [];

        return $this->receipt->purpose === 'monthly' && count($lines) === 1 ? calendar_month_year($lines[0]['period'].'-01') : null;
    }

    /** True for the purposes that are paid against months (a monthly payment, an advance) — they show how it was applied. */
    public function showsApplication(): bool
    {
        return in_array($this->receipt->purpose, ['monthly', 'advance'], true);
    }

    public function hasCredit(): bool
    {
        return Money::isPositive((string) $this->receipt->credit_amount);
    }

    public function institutionNameBn(): string
    {
        return (string) ($this->receipt->institution['name_bn'] ?? '');
    }

    /** "Provatferi Literary and Cultural Center (PLCC)". */
    public function institutionNameEn(): string
    {
        $name = (string) ($this->receipt->institution['name_en'] ?? '');
        $acronym = (string) ($this->receipt->institution['acronym'] ?? '');

        return $acronym !== '' ? "{$name} ({$acronym})" : $name;
    }

    /** Phone · e-mail · website, as stored on the receipt (Latin digits, as written). */
    public function contactLine(): string
    {
        return implode('  ·  ', array_filter([
            $this->receipt->institution['phone'] ?? null,
            $this->receipt->institution['email'] ?? null,
            $this->receipt->institution['website'] ?? null,
        ], fn ($part) => is_string($part) && trim($part) !== ''));
    }

    public function address(): ?string
    {
        $address = $this->receipt->institution['address'] ?? null;

        return is_string($address) && trim($address) !== '' ? $address : null;
    }
}
