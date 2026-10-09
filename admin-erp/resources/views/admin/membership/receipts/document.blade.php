{{--
    THE OFFICIAL PAYMENT RECEIPT (Membership task 5; docs/MEMBERSHIP_RECEIPTS.md).

    Rendered twice from this one file, so the two never drift apart: by PaymentReceiptController::show() for the browser
    (print view) and by PaymentReceiptPdfService for mPDF. That is why it is tables and plain CSS only — mPDF has no
    flexbox or grid.

    Everything shown comes from the receipt's own snapshot ($p->receipt: PaymentReceipt), set when the payment was
    verified — never from today's member or type record; the one derived line is the member number of a registration
    receipt (see ReceiptPresenter::memberCode()). It is rendered under the RECEIPT's language ($p->lang), independent of
    the admin's own: the caller sets App::setLocale() before rendering, so __() and the date/number helpers follow it.
    The payer's name and the reference are shown exactly as entered, never translated. No internal note, audit comment,
    account or contact detail of the member is ever printed.

    $p         App\Support\ReceiptPresenter
    $logoSrc   src of the institution's logo: "var:logo" (an image handed to PdfRenderer) in the PDF, a URL in the browser
--}}
<style>
@include('admin.pdf._typography')
    .rc-head { width: 100%; border-collapse: collapse; }
    .rc-head td { vertical-align: middle; }
    .rc-org-bn { font-weight: bold; font-size: 15pt; color: #201B17; line-height: 1.3; }
    .rc-org-en { font-size: 9.5pt; color: #4A4038; margin-top: 1px; }
    .rc-contact { font-size: 8.5pt; color: #6B5F53; margin-top: 4px; }
    /* A <div> with a top border, not an <hr>: mPDF draws an <hr> in its own grey and ignores the border, the browser honours it. */
    .rc-rule { border-top: 2px solid #AC350A; margin: 9px 0 13px; height: 0; font-size: 0; line-height: 0; }

    .rc-title-row { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    .rc-title-row td { vertical-align: top; }
    .rc-title { font-weight: bold; font-size: 18pt; color: #AC350A; line-height: 1.25; }
    .rc-title-sub { font-size: 9pt; color: #6B5F53; margin-top: 2px; }
    .rc-meta { width: 100%; border-collapse: collapse; border: 1px solid #E6DFD5; background-color: #FAF7F2; }
    .rc-meta td { padding: 4px 9px; font-size: 9.5pt; }
    .rc-meta .k { color: #6B5F53; width: 38%; }
    .rc-meta .number { font-weight: bold; font-size: 11pt; color: #201B17; }

    .rc-amount { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    .rc-amount .rail { width: 5px; background-color: #AC350A; }
    .rc-amount .body { background-color: #FAF7F2; border: 1px solid #E6DFD5; border-left: none; padding: 9px 14px; }
    .rc-amount-label { font-size: 9pt; color: #6B5F53; }
    .rc-amount-figure { font-weight: bold; font-size: 22pt; color: #201B17; line-height: 1.2; }
    .rc-amount-purpose { font-size: 10pt; color: #4A4038; }

    .rc-section { font-weight: bold; font-size: 10.5pt; color: #AC350A; margin: 14px 0 5px; }

    table.rc-lines { width: 100%; border-collapse: collapse; }
    table.rc-lines td { border: 1px solid #E6DFD5; padding: 6px 9px; font-size: 10pt; }
    table.rc-lines td.amt { text-align: right; width: 32%; }
    table.rc-lines tr.sub td { background-color: #FAF7F2; color: #4A4038; }
    table.rc-lines tr.total td { background-color: #FAF7F2; font-weight: bold; font-size: 11pt; border-top: 2px solid #4A4038; }
    .rc-credit-note { font-size: 8.5pt; color: #6B5F53; margin-top: 3px; }

    .rc-sign { width: 100%; border-collapse: collapse; margin-top: 30px; }
    .rc-sign td { vertical-align: top; }
    td.rc-sign-name { font-size: 10.5pt; font-weight: bold; padding-top: 2px; }
    td.rc-sign-line { border-top: 1px solid #4A4038; padding-top: 4px; font-size: 8.5pt; color: #6B5F53; }

    .rc-footer { margin-top: 18px; padding-top: 8px; border-top: 1px solid #E6DFD5; font-size: 8.5pt; color: #6B5F53; }
    .rc-footer .id { color: #A89F94; margin-top: 3px; }
</style>

{{-- The letterhead, from the receipt's own snapshot — the logo once, beside both names of the institution. --}}
<table class="rc-head">
    <tr>
        <td style="width: 42mm;">
            @if ($logoSrc)
                <img src="{{ $logoSrc }}" style="height: 17mm;" alt="">
            @endif
        </td>
        <td>
            <div class="rc-org-bn">{{ $p->institutionNameBn() }}</div>
            <div class="rc-org-en">{{ $p->institutionNameEn() }}</div>
            @if ($p->contactLine() !== '')
                <div class="rc-contact">{{ $p->contactLine() }}</div>
            @endif
            @if ($p->address())
                <div class="rc-contact" style="margin-top: 1px;">{{ $p->address() }}</div>
            @endif
        </td>
    </tr>
</table>
<div class="rc-rule"></div>

<table class="rc-title-row">
    <tr>
        <td>
            <div class="rc-title">{{ __('admin.receipt.title') }}</div>
            <div class="rc-title-sub">{{ __('admin.receipt.official') }}</div>
        </td>
        <td style="width: 84mm;">
            <table class="rc-meta">
                <tr>
                    <td class="k">{{ __('admin.receipt.number') }}</td>
                    <td class="number" data-testid="receipt-number">{{ $p->receipt->receipt_no }}</td>
                </tr>
                <tr>
                    <td class="k">{{ __('admin.receipt.payment_date') }}</td>
                    <td data-testid="receipt-payment-date">{{ calendar_date($p->receipt->payment_date) }}</td>
                </tr>
                <tr>
                    <td class="k">{{ __('admin.receipt.issued_at') }}</td>
                    <td data-testid="receipt-issued-at">{{ admin_datetime($p->receipt->issued_at) }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<table class="rc-amount">
    <tr>
        <td class="rail"></td>
        <td class="body">
            <div class="rc-amount-label">{{ __('admin.receipt.amount_received') }}</div>
            <div class="rc-amount-figure" data-testid="receipt-amount">{{ bn_money((string) $p->receipt->amount) }}</div>
            <div class="rc-amount-purpose">{{ $p->purposeLabel() }}</div>
        </td>
    </tr>
</table>

<div class="rc-section">{{ __('admin.receipt.received_from') }}</div>
<table class="rc-fields">
    <tr><th>{{ __('admin.receipt.name') }}</th><td data-testid="receipt-payer">{{ $p->receipt->payer_name }}</td></tr>
    @if ($p->memberCode())
        <tr><th>{{ __('admin.receipt.member_no') }}</th><td data-testid="receipt-member-no">{{ $p->memberCode() }}</td></tr>
    @endif
    @if ($p->receipt->application_no)
        <tr><th>{{ __('admin.receipt.application_no') }}</th><td>{{ $p->receipt->application_no }}</td></tr>
    @endif
    @if ($p->typeName())
        <tr><th>{{ __('admin.receipt.membership_type') }}</th><td>{{ $p->typeName() }}</td></tr>
    @endif
</table>

<div class="rc-section">{{ __('admin.receipt.details') }}</div>
<table class="rc-fields">
    <tr><th>{{ __('admin.receipt.purpose_label') }}</th><td>{{ $p->purposeLabel() }}</td></tr>
    @if ($p->singleMonth())
        <tr><th>{{ __('admin.receipt.for_month') }}</th><td>{{ $p->singleMonth() }}</td></tr>
    @endif
    @if ($p->methodLabel())
        <tr><th>{{ __('admin.receipt.method') }}</th><td>{{ $p->methodLabel() }}</td></tr>
    @endif
    @if ($p->receipt->reference)
        <tr><th>{{ __('admin.receipt.reference') }}</th><td>{{ $p->receipt->reference }}</td></tr>
    @endif
</table>

{{-- How a monthly payment or an advance was applied AT ISSUE; the three figures always add up: applied + advance credit = received. --}}
@if ($p->showsApplication())
    <div class="rc-section">{{ __('admin.receipt.applied_to') }}</div>
    <table class="rc-lines" data-testid="receipt-application">
        @foreach ($p->lines() as $line)
            <tr data-testid="receipt-line">
                <td>{{ $line['label'] }}</td>
                <td class="amt">{{ $line['amount'] }}</td>
            </tr>
        @endforeach
        @if ($p->lines() !== [])
            <tr class="sub">
                <td>{{ __('admin.receipt.applied_dues') }}</td>
                <td class="amt" data-testid="receipt-applied">{{ bn_money((string) $p->receipt->applied_amount) }}</td>
            </tr>
        @endif
        @if ($p->hasCredit())
            <tr class="sub">
                <td>{{ __('admin.receipt.credit') }}</td>
                <td class="amt" data-testid="receipt-credit">{{ bn_money((string) $p->receipt->credit_amount) }}</td>
            </tr>
        @endif
        <tr class="total">
            <td>{{ __('admin.receipt.total') }}</td>
            <td class="amt">{{ bn_money((string) $p->receipt->amount) }}</td>
        </tr>
    </table>
    @if ($p->hasCredit())
        <div class="rc-credit-note">{{ __('admin.receipt.credit_note') }}</div>
    @endif
@endif

{{-- Who took the money and who verified it: a rule, the role in small type, the name under it. Two rows, so the rule is the
     cell's own border and spans the whole column. No signature is asked for — the footer says the receipt is valid without. --}}
<table class="rc-sign">
    <tr>
        <td class="rc-sign-line" style="width: 46%;">{{ __('admin.receipt.received_by') }}</td>
        <td style="width: 8%;"></td>
        <td class="rc-sign-line" style="width: 46%;">{{ __('admin.receipt.verified_by') }}</td>
    </tr>
    <tr>
        <td class="rc-sign-name">{{ $p->receipt->received_by_name ?: '—' }}</td>
        <td></td>
        <td class="rc-sign-name">{{ $p->receipt->verified_by_name ?: '—' }}</td>
    </tr>
</table>

<div class="rc-footer">
    {{ __('admin.receipt.footer') }}
    <div class="id">{{ $p->receipt->receipt_no }}</div>
</div>
