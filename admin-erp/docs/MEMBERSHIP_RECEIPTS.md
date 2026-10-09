# Official payment receipts

Membership task 5 (2026-10-08). Every **verified** membership payment — the registration fee, a monthly contribution, an
advance, a voluntary gift — has one permanent, numbered receipt that can be opened, printed and downloaded as a PDF in Bangla
or English, by the admins who may see payments and by the member it belongs to. Collection reports, online payment,
reversals and refunds are **not** built.

Code: `App\Models\PaymentReceipt` (the row), `App\Services\PaymentReceiptService` (when and what is issued; the member's own
receipts), `App\Services\ApplicationPaymentService` (verifying a registration fee — moved out of the controller to carry the
receipt), `App\Services\MembershipDueLedger::verifyPayment` (monthly money), `App\Services\MembershipNumbering`
(`issueReceiptNumber`), `App\Support\ReceiptPresenter`, `App\Services\PaymentReceiptPdfService` (engine: `App\Services\Pdf\PdfRenderer`),
`Admin\PaymentReceiptController`, `Api\V1\Member\ReceiptController`; the site's `lib/receipt-route.ts` and
`app/api/member/receipts/[number]/route.ts`. Tests: `tests/Feature/Admin/PaymentReceiptTest.php`,
`PaymentReceiptDocumentTest.php`, `PaymentReceiptConcurrencyTest.php` (real simultaneous processes), and on the site
`lib/api/__tests__/receipt-route.test.mts`, `member.test.mts`.

## What gets a receipt — and what never does

A receipt is issued **in the transaction that verifies the payment**, for a payment that is verified money: status `paid`,
`verified_at` set, an amount above zero, towards a membership or a membership application. Nothing else.

| Payment | Receipt |
|---|---|
| recorded, not yet verified | none — nothing is issued, no number is taken |
| cancelled before verification | none |
| **waiver** (a registration fee is stored as a payment row with status `waived` **and** a verification stamp, which is why "verified" alone is not the test; a due waiver has no payment row at all) | none — a waiver is not money |
| verification that fails or rolls back | none — the number goes back with the transaction |
| verified (registration, monthly, advance, voluntary) | one |

Verifying twice, two admins at once, a retried request: the payment is verified once; if the issuing code is reached again it
returns the existing receipt (`UNIQUE (payment_id)`), never a second number.

## The number

`PLCC-RCT-{year}-{nnnnnn}`, for example `PLCC-RCT-2026-000001` — from the `number_sequences` counter `receipt:{year}`
(`MembershipNumbering::issueReceiptNumber`), the same proven mechanism as member and application numbers
(`docs/MEMBERSHIP_NUMBERING.md`): one atomic increment inside the verifying transaction, never `max(id)+1` / `count()+1` /
the payment id; six digits at least, growing past 999999; unique index on `receipt_no`; never reused; immutable. The year is
the **Asia/Dhaka** year of the verification (a payment verified at 23:59 on 31 December Dhaka time is that year's; one at 00:00
is the next year's number 000001). `RCT` is a **reserved membership-type code**: no type can carry it, so a receipt number can
never be read as a member number.

## What the receipt stores (the snapshot)

`payment_receipts` is append-only (no `updated_at`; the model refuses any change and any deletion; the `payments` row cannot be
deleted from under it — `RESTRICT`). It holds what the printed receipt shows, **as it was at verification**:

| Column | |
|---|---|
| `receipt_no`, `payment_id` | unique each |
| `purpose` | `registration` · `monthly` · `advance` (a monthly payment with no month) · `voluntary` · `other` |
| `amount`, `applied_amount`, `credit_amount`, `lines` | `applied + credit = amount` (a database `CHECK`). `lines` = the months the payment was applied to **at issue**, summed per month; `credit` = what was held as advance credit then. Credit applied to later months, as their dues are created, is a ledger event — not an edit of this receipt |
| `payment_date` | the calendar date (a `DATE`, shown as stored — never converted) |
| `method`, `reference`, `payer_name`, `member_code`, `application_no`, `membership_type_name(_en)` | as they were |
| `received_by_name`, `verified_by_name` | staff names as they were |
| `institution` | the letterhead (names, phone, e-mail, address, website) as it was |
| `issued_by`, `issued_at`, `issued_via` | who, when (UTC; shown on the Dhaka clock), `verification` |

So renaming a member, changing the type's name or fee policy, editing the address or the reference afterwards leaves an issued
receipt untouched. **The one live line** is the member number of a *registration* receipt: it is issued when the fee is
verified, before approval, when no member number exists yet — it is then shown from the application's own (permanent)
membership once approved. Once a receipt exists, the **frozen fields of its payment** (amount, date, method, reference, status,
verification, category, month) cannot be changed either (`Payment::RECEIPT_FROZEN`).

## The document

One Blade file (`resources/views/admin/membership/receipts/document.blade.php`: tables and plain CSS, because mPDF has no
flexbox) is rendered twice — for the browser (print view) and for mPDF — so the two never drift apart:

- the letterhead: the logo, **প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র**, Provatferi Literary and Cultural Center (PLCC), phone,
  e-mail, website, address (from the snapshot);
- receipt number, payment date, issue date and time (Dhaka); the amount received, large, with the purpose;
- received from: name, member number, application number (registration), membership type;
- details: purpose, the month (for a single month), method, reference — every one through a label, never a raw value
  (`monthly_contribution`, `bank_transfer` never reach the page);
- for monthly payments and advances, how it was applied: one line per month, "applied to monthly contributions", "advance
  credit" and the total — they always add up. ৳600 across three months of ৳200 shows the three months; ৳500 against one owed
  month shows ৳200 applied and ৳300 advance credit with a note that it is held for the months ahead;
- received by / verified by, and the footer (computer-generated, valid without a signature).

Never printed: internal notes, audit comments, anything about accounts or passwords, the member's address or contact details.
The payer's name and the reference are shown exactly as entered, never translated. Digits, month names and times follow the
receipt's language and the Dhaka clock (`AdminTime`). The browser view has a toolbar — language (বাংলা / English), Download
PDF, Print, Close — and stacks into one column on a phone.

### The PDF

The one PDF engine of the admin (`App\Services\Pdf\PdfRenderer`, mPDF) — no second engine — generated **on demand on the server**,
never stored, never at a public URL. `Content-Disposition` `attachment` (or `?disposition=inline`), file name = the receipt
number, `Cache-Control: private, no-store`, `nosniff`.

**Bengali shaping needed work — twice.** Looking at the rendered page showed what no text-based test could: mPDF's shaping engine is
off unless the font asks for it, and without it the vowel signs sat on the wrong side of their consonants (পরিশোধ printed as
পরশিোধ) — in the recruitment application PDFs too, for as long as they existed. mPDF 8.3.1 cannot read the current Noto Sans
Bengali (3.x) with shaping on, so the Bengali comes from the 2.001 release of the same typeface and the Latin letters from the 3.x
files through glyph substitution. The second time (2026-10-10) shaping was on but not *consistently*: an mPDF cache flaw plus the
old unshaped fallback made the document after any failure come out unshaped. There is no fallback any more, the font cache is
verified before every document, and the shaping is checked pixel by pixel against HarfBuzz. Everything — the audit of all PDF
generators, the standard, the root cause, the checks — is in **`docs/PDF_BENGALI_STANDARD.md`**. Trade-off: in a shaped PDF the
text layer of Bengali words is fragments (conjuncts have no Unicode value); Latin text, numbers and amounts extract normally.
Guards: `tests/Feature/Pdf`, `PaymentReceiptDocumentTest::test_the_bengali_in_the_pdf_is_shaped_not_merely_drawn`,
`php artisan pdf:self-check`, `deploy/qa/pdf-shaping-qa.mjs`. `institutional/scripts/pdf-rasterize.mjs` renders a PDF's pages to PNG
for looking at them.

## Who can open it

- **Admins** with `payments.view`: `/admin/membership/receipts/{receipt_no}` (print view, `?lang=bn|en`, default the admin's own
  language) and `/admin/membership/receipts/{receipt_no}/pdf`. Opening, printing and downloading write nothing and add no audit
  entry. Offered on the application review page, the member page (registration payments and the monthly / voluntary payments
  table) as the receipt number, "View receipt" (new tab) and "Download PDF" — on verified payments only. The registry list is
  not crowded with them.
- **Members**: the portal dashboard lists the member's own receipts (number, purpose and months, date, amount, advance credit)
  with View / Download (PDF) / English. The links go to the site's own route (`/api/member/receipts/{number}`), which reads
  the member's session from the HttpOnly cookie and asks admin-erp (`GET /api/v1/member/receipts/{number}/pdf`) with it.
  Laravel looks the number up **only among that member's receipts** (`PaymentReceiptService::forMember`: payments towards their
  memberships and the registration fees on the applications those memberships came from): someone else's number and a number
  that does not exist are the same 404, so nothing reveals that a receipt exists. A signed-out visitor (or a revoked or
  suspended account) is sent to the login page. A string that is not a receipt number never reaches Laravel. The PDF is passed
  on unread-by-the-site, `private, no-store`, named by the validated number. Nothing about who verified it, no reference, no
  internal data goes to the portal's list.

## Audit

One history entry per issued receipt — `receipt_issued` on the application (registration) or the membership: number, payment,
purpose, amount, issued by, and when. Never one per view or download. Shown in the history in Bangla and English.

## Existing payments

Production had **no payments at all** when this shipped (checked read-only first by the QA kit's `audit`), so the counter starts
at `PLCC-RCT-2026-000001` and no existing payment needed a receipt. A deployment that already holds verified payments would
have them listed by the same audit (`real_verified_money_without_a_receipt`) before anything were issued for them; this
release deliberately has no backfill.

## Production acceptance kit

`deploy/qa/membership-registry-qa.php` modes `receipt-setup`, `receipt-invites`, `receipt-inspect` (plus `audit`, `snapshot` and
`cleanup`, which cover payments, receipts and the receipt counter) and `institutional/scripts/membership-receipts-qa.mjs`
(phases `approve`, `flow`, `views`, `portal`, `security`). The people: r1 and r2 (Lifetime, taken through the real screens) and
r3 (a disposable ৳200-a-month type, dated back four months). Scenarios: registration fee, monthly contribution, partial
payment, one payment over several months, an advance with credit left, a voluntary gift, an awaiting payment, a cancelled one,
two waivers (none gets a receipt), two simultaneous verifications (one receipt), the member portal (own receipts yes, another
member's no). `cleanup go` (the `go` is required) removes the QA receipts straight from the database (the model refuses deletion on purpose), then their
payments, and gives the receipt numbers back by the same guarded rule as every other number — only through the top run held by
QA rows alone, never past a real receipt — so the first real receipt is still `PLCC-RCT-2026-000001`.

### Production acceptance (2026-10-09)

Real Chrome against production, disposable QA data only: approve 12/12, flow 25/26 (the one "failure" was the script counting its
own `fetch(..., {redirect: "manual"})` race requests as aborted — now filtered), views 169/171 (the two were the script restoring
the admin's language from a page without a CSRF token — fixed), portal 17/17, security 14/14; server-side `receipt-inspect`: 11 QA
payments, 8 receipts numbered 000001-000008 with no gap and no duplicate, none for the awaiting / cancelled / waived payments, the
counter equal to the highest number, 8 `receipt_issued` history rows, and every attempt to change or delete a receipt refused.
Two simultaneous verification routes were exercised on the live host: three parallel `fetch` POSTs (one receipt) and two tabs
clicking at the same instant (the second was told "already verified, nothing changed"). The rendered PDFs (16, both languages)
were rasterised and read: Bengali shaped, Latin from the substitution font, logo crisp, totals reconciling. Evidence kept outside
the repo (Downloads/provatferi-receipts-qa-2026-10-09).

**The incident that cost an hour.** The QA rows kept vanishing one minute after `receipt-setup` made them. Nothing in the
application deletes them: a minutely `cleanup` cron created at the end of the previous task (its create call returned an empty
uid, so the panel never listed it and the API could not delete it) was still running `_qa_registry.php cleanup` every minute and
had been idle only because that file no longer existed. A watcher run from fresh connections every five seconds showed the rows
present until second 58 and gone at 61; uploading the kit under another name made them stay. Consequences, all now in the kit:
upload it under a fresh random name per session, never create a mutating cron as `* * * * *`, and `cleanup` refuses to run
unless it is asked for as `cleanup go`. The orphan job itself is still registered (the host offers no way to list or remove it
through the API; `crontab` is not available to PHP): it fires every minute against a file that does not exist, harmlessly, and can
be deleted in hPanel → Cron Jobs (the every-minute job ending in `_qa_registry.php cleanup`).

## Not built

Reversal or refund of a receipt (a separate document, never an edit of this one); amount in words; collection reports; online
payment; e-mailing a receipt; backfilling receipts for payments verified before this existed (there were none).
