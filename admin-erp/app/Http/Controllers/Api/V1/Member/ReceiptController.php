<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\PaymentReceiptPdfService;
use App\Services\PaymentReceiptService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A member's own receipt as a PDF (Membership task 5; docs/MEMBERSHIP_RECEIPTS.md).
 *
 * Server-to-server like the rest of the member API: the Next.js site calls it with the member's Bearer token (kept in
 * its HttpOnly cookie) and passes the PDF on. Behind `auth:sanctum` + `member.auth` — an admin's token, a suspended or
 * archived member, no token: all refused before this runs.
 *
 * OWNERSHIP is the query, not a check afterwards: the receipt is looked up ONLY among the member's own
 * (PaymentReceiptService::forMember), so another member's receipt number — or a number that does not exist — is the same
 * 404; nothing here can confirm that some other member's receipt exists. The PDF is built per request and never stored.
 */
class ReceiptController extends Controller
{
    public function pdf(Request $request, string $receiptNo, PaymentReceiptService $receipts, PaymentReceiptPdfService $documents): Response
    {
        /** @var Member $member */
        $member = $request->user();

        $receipt = $receipts->forMember($member)->where('receipt_no', $receiptNo)->first();
        abort_if($receipt === null, 404);

        $lang = PaymentReceiptPdfService::language($request->query('lang'), 'bn');
        $disposition = $request->query('disposition') === 'inline' ? 'inline' : 'attachment';

        return response($documents->pdf($receipt, $lang), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.PaymentReceiptPdfService::filename($receipt).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
