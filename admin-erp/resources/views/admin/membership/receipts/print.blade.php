@extends('layouts.print')

{{-- The browser's print view of a receipt: the same document the PDF is made from, with the toolbar of layouts.print (receipt
     language, Print, Close) and a link to the PDF. --}}
@push('print_actions')
    <a href="{{ $pdfUrl }}">{{ __('admin.receipt.actions.download') }}</a>
@endpush

{{-- On a phone the A4-wide letterhead and the title/number box would be squeezed side by side into a few words a line (the
     number broke into four). The document is tables for mPDF's sake, so here — on screen, and only for a narrow one — the
     cells simply stack. Not part of the PDF: mPDF is given the document partial alone, never this layout. --}}
@push('print_styles')
    <style>
        @media screen and (max-width: 640px) {
            .print-page { margin: 10px 8px 24px; padding: 16px 14px; }
            /* Child combinators: the number box inside the title row is a table of its own and keeps its two columns. */
            .print-page table.rc-head, .print-page table.rc-head > tbody, .print-page table.rc-head > tbody > tr, .print-page table.rc-head > tbody > tr > td,
            .print-page table.rc-title-row, .print-page table.rc-title-row > tbody, .print-page table.rc-title-row > tbody > tr, .print-page table.rc-title-row > tbody > tr > td { display: block; width: auto !important; }
            .print-page table.rc-head > tbody > tr > td img { margin-bottom: 8px; }
            .print-page table.rc-title-row > tbody > tr > td + td { margin-top: 10px; }
            .print-page .rc-meta .number { word-break: break-all; }
            .print-page table.rc-amount .rc-amount-figure { font-size: 24pt; }
        }
    </style>
@endpush

@section('content')
    @include('admin.membership.receipts.document', ['p' => $p, 'logoSrc' => $logoSrc])
@endsection
