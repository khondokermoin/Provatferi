@props(['payment'])

{{-- The receipt of a payment: its number and the two ways to open it (Membership task 5). Printed ONLY for a payment that
     has one — verified money; a pending, cancelled or waived payment shows nothing here, never a receipt or a "receipt" word.
     Opens in a new tab: the print view is its own page, closed with its Close button. --}}
@if ($payment->receipt)
    @can('payments.view')
        <div class="mt-1" data-testid="receipt-links" data-receipt-no="{{ $payment->receipt->receipt_no }}">
            <span class="d-block fs-12 text-muted"><i class="ti ti-receipt" aria-hidden="true"></i> {{ __('admin.receipt.actions.receipt') }}: <span class="text-body fw-semibold" data-testid="receipt-no">{{ $payment->receipt->receipt_no }}</span></span>
            <a href="{{ route('admin.membership.receipts.show', $payment->receipt) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary mt-1" data-testid="receipt-view"><i class="ti ti-eye me-1" aria-hidden="true"></i>{{ __('admin.receipt.actions.view') }}</a>
            <a href="{{ route('admin.membership.receipts.pdf', $payment->receipt) }}" class="btn btn-sm btn-outline-secondary mt-1" data-testid="receipt-pdf"><i class="ti ti-file-download me-1" aria-hidden="true"></i>{{ __('admin.receipt.actions.download') }}</a>
        </div>
    @endcan
@endif
