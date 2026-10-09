<?php

namespace App\Services;

use App\Models\PaymentReceipt;
use App\Services\Pdf\PdfImagePreparer;
use App\Services\Pdf\PdfRenderer;
use App\Support\ReceiptPresenter;
use Illuminate\Support\Facades\App;

/**
 * The official receipt as a PDF and as the browser's print view (Membership task 5; docs/MEMBERSHIP_RECEIPTS.md).
 *
 * Generated on demand, never stored: a PDF of a member's financial record is not kept anywhere that a URL could reach
 * (nothing under public/ or the public disk) — every delivery is an authenticated response built from the receipt row,
 * so asking again returns the same facts (the snapshot never changes) and costs no audit entry, no number, no row.
 *
 * One PDF engine for the whole admin: App\Services\Pdf\PdfRenderer (mPDF with Noto Sans Bengali, which shapes Bengali
 * conjuncts correctly — docs/PDF_BENGALI_STANDARD.md). The logo is handed to it as an image, not written into the HTML
 * as base64 — no remote image, no browser font.
 */
final class PaymentReceiptPdfService
{
    public const LANGUAGES = ['bn', 'en'];

    public function __construct(private readonly PdfRenderer $pdf)
    {
    }

    /** The requested receipt language when it is one of ours, else $fallback. */
    public static function language(mixed $requested, string $fallback = 'bn'): string
    {
        return is_string($requested) && in_array($requested, self::LANGUAGES, true) ? $requested : (in_array($fallback, self::LANGUAGES, true) ? $fallback : 'bn');
    }

    /** The file name of a receipt's PDF: the receipt number. */
    public static function filename(PaymentReceipt $receipt): string
    {
        return $receipt->receipt_no.'.pdf';
    }

    /** The receipt as PDF bytes, in $lang (bn | en). */
    public function pdf(PaymentReceipt $receipt, string $lang): string
    {
        $logo = PdfImagePreparer::logo();

        $html = $this->inLocale($lang, fn () => view('admin.membership.receipts.document', [
            'p' => new ReceiptPresenter($receipt, $lang),
            'logoSrc' => $logo !== null ? 'var:logo' : null,
        ])->render());

        return $this->pdf->render($html, $receipt->receipt_no, $logo !== null ? ['logo' => $logo] : []);
    }

    /**
     * The browser's print view (toolbar, then the document) in $lang. $pdfUrl and $languageLinks (lang => url) are the
     * links the toolbar shows.
     *
     * @param  array<string, string>  $languageLinks
     */
    public function printHtml(PaymentReceipt $receipt, string $lang, string $pdfUrl, array $languageLinks): string
    {
        return $this->inLocale($lang, fn () => view('admin.membership.receipts.print', [
            'title' => $receipt->receipt_no,
            'docLocale' => $lang,
            'docLocaleLinks' => $languageLinks,
            'pdfUrl' => $pdfUrl,
            'p' => new ReceiptPresenter($receipt, $lang),
            'logoSrc' => asset('brand/provatferi-logo-light.png'),
        ])->render());
    }

    /**
     * Renders under the receipt's language and restores the request's own afterwards. A View is otherwise rendered
     * lazily, after the caller returns — too late for the locale to reach it — hence ->render() inside the switch.
     */
    private function inLocale(string $lang, callable $render): string
    {
        $original = App::getLocale();
        App::setLocale($lang);
        try {
            return $render();
        } finally {
            App::setLocale($original);
        }
    }
}
