<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentReceipt;
use App\Services\PaymentReceiptPdfService;
use App\Support\AdminLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;

/**
 * An issued receipt, for the admins who may see payments (`payments.view`): the print view and the PDF
 * (Membership task 5; docs/MEMBERSHIP_RECEIPTS.md).
 *
 * Nothing here writes anything: a receipt is read-only by design (PaymentReceipt), and opening, printing or downloading
 * it any number of times changes no row and adds no audit entry. The receipt is addressed by its number; there is no
 * public URL — both actions sit behind the admin login and the permission, and the PDF is built per request, never kept.
 * ?lang=bn|en picks the receipt's language (default: the admin's own panel language).
 */
class PaymentReceiptController extends Controller
{
    public function __construct(private readonly PaymentReceiptPdfService $documents)
    {
    }

    /** The print view: the receipt in the browser, with Print, Download PDF and the language choice. */
    public function show(Request $request, PaymentReceipt $receipt): Response
    {
        $lang = PaymentReceiptPdfService::language($request->query('lang'), App::getLocale());
        $links = collect(AdminLocale::codes())->mapWithKeys(fn (string $code) => [$code => route('admin.membership.receipts.show', [$receipt, 'lang' => $code])])->all();

        return response($this->documents->printHtml($receipt, $lang, route('admin.membership.receipts.pdf', [$receipt, 'lang' => $lang]), $links), 200, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** The PDF. ?disposition=inline opens it in the browser's viewer; the default downloads it. */
    public function pdf(Request $request, PaymentReceipt $receipt): Response
    {
        $lang = PaymentReceiptPdfService::language($request->query('lang'), App::getLocale());
        $disposition = $request->query('disposition') === 'inline' ? 'inline' : 'attachment';

        return response($this->documents->pdf($receipt, $lang), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.PaymentReceiptPdfService::filename($receipt).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
