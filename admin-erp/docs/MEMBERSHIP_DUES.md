# Monthly membership dues

Membership task 4 (2026-10-08). The monthly contribution ledger: which months a membership owes, the money received
against them, waivers, and where it is shown. Every verified payment now has an official receipt — `docs/MEMBERSHIP_RECEIPTS.md`.

Code: `App\Services\MembershipDueSchedule` (which months, which amount), `App\Services\MembershipDueLedger` (generation,
payments, credit, waivers, the summary), `App\Models\MembershipDue` / `MembershipDueAllocation`, the command
`membership:generate-dues`, `Admin\MemberDuesController` (admin actions), `Admin\MemberController` (registry + member
page), `Api\V1\Member\DashboardController` (member portal). Tests: `tests/Feature/Admin/MembershipDuesTest.php`,
`MembershipDuesPaymentsTest.php`, `MembershipDuesConcurrencyTest.php` (real simultaneous processes,
`tests/Support/dues-worker.php`), `MembershipTypeReadinessTest.php`.

## Where the amounts come from

Only from the membership type's effective-dated fee policies (`docs/MEMBERSHIP_FEE_POLICIES.md`) — data, never code.
Today: Lifetime (LM) ৳200 a month, General (GM) ৳0, Student (ST) ৳0. A policy change is a new policy row; it never
touches a due that already exists.

## Tables

`membership_dues` — one row per membership per month owed:

| Column | Meaning |
|---|---|
| `membership_id` | the membership |
| `membership_fee_policy_id` | the policy that set the amount (restrict: a policy that priced a due can never be deleted) |
| `period_year`, `period_month` | the calendar month (Asia/Dhaka) |
| `amount` | what the month was assessed — `decimal(10,2)`, never changed after creation (model guard) |
| `due_date` | the month's last day; owed after it = overdue |
| `paid_amount`, `waived_amount` | verified money applied, and amounts waived |
| `outstanding_amount` | stored generated column `amount − paid − waived` (indexed filters) |
| `status` | `due` · `partially_paid` · `paid` · `waived` (overdue is derived, see below) |
| `generated_at`, `paid_at`, `note` | when it was created, when it was fully paid, free note |

Database guards: `UNIQUE (membership_id, period_year, period_month)` — one due per member per month whatever happens;
`CHECK (amount > 0 AND paid ≥ 0 AND waived ≥ 0 AND paid + waived ≤ amount AND month 1–12)`.

`membership_due_allocations` — every piece of verified money applied to a due: due, payment, amount (> 0), kind
(`payment` = applied to its own month at verification, `credit` = advance credit applied later), who, when. A due's
`paid_amount` always equals the sum of its allocations (tested under concurrency).

`payments` (existing table, reused) gained `category` (`registration` · `monthly_contribution` · `voluntary` · `other`;
existing rows: application payments `registration`, anything else `other`), `membership_due_id` (the month a monthly
payment was recorded for; null = advance), and `cancelled_at` / `cancelled_by` / `cancellation_reason`. A monthly payment
keeps membership (`payable`), month, amount, received date, method, reference, `received_by`, `verified_at` and
`verified_by`. All amounts are integer paisa in PHP (`Money::toPaisa`/`fromPaisa`) and decimals in the database — never
floats.

## Which months are owed

- Months are calendar months on the organisation's calendar (`config('membership.timezone')` = Asia/Dhaka), never UTC
  months: 1 November begins at 18:00 UTC on 31 October.
- The **first** month is the joining month (the membership's start date), charged in full at the policy in force **on
  the joining date**. No prorating.
- Every later month is charged at the policy in force on **the first day of that month**. A fee change dated in the
  middle of a month therefore applies from the next month.
- A month whose monthly contribution is **zero** (or with no policy in force) has **no due** at all — never a ৳0 due and
  never a fake unpaid one. Example: Student ৳0 now, ৳50 from next March → no due before March, ৳50 dues from March.
- A month is owed only while the membership **accrues**: it was active at the first instant of the month, or it was
  activated / reactivated during the month. Suspended and archived memberships accrue nothing new; what they already
  owed stays owed. Reactivation resumes from the reactivation month; the months in between are **never** back-charged.
  The status timeline is read from the audited status events (`created`, `activated`, `reactivated`, `suspended`,
  `archived` in `approval_history`).
- Never a future month: the ledger stops at the current month. An existing due is never recalculated.

Worked example (LM): joined 15 January at ৳200; a ৳300 policy effective 1 March → January ৳200, February ৳200,
March ৳300. A ৳300 policy effective 20 March → March stays ৳200, April ৳300.

## Generation

`MembershipDueLedger::generateFor()` creates every missing due from the joining month through the current month, then
applies waiting credit. It locks the membership's row first (`SELECT … FOR UPDATE`) and the UNIQUE index backs it up, so
it is **idempotent and concurrency-safe**: twice, ten times, from two processes at once — the same ledger.

It runs from:

1. **Approval** — the joining month's due is created right after an application is approved (a failure is logged and
   never undoes the approval; the next run creates it).
2. **The daily command** `php artisan membership:generate-dues [--membership=<id>]` — every membership, one transaction
   each; prints JSON (`memberships_checked`, `dues_created`). On Hostinger it runs from one cron job at **18:05 UTC
   = 00:05 Asia/Dhaka**, just after each new Dhaka day (and month) begins:
   `php /home/u951246149/domains/provatferi.org/laravel-admin/artisan membership:generate-dues`. No queue worker.
3. **The admin** — "Generate / update dues" on the registry (all members) and on a member's page (that member).
4. **Reading** — the member page and the member portal reconcile that member's missing dues before showing them, so a
   new month is never missing even if the cron has not run yet.

## Statuses

| State | Meaning |
|---|---|
| `due` | nothing paid yet (this month, or a past month — then shown as overdue) |
| `partially_paid` | part verified, the rest still owed |
| `paid` | fully covered by verified money (`paid_at` set) |
| `waived` | fully covered by a waiver with no money paid |
| `overdue` (derived) | the month's last day has passed (Dhaka) and something is still owed |

A membership whose contribution is zero shows **"No monthly contribution due" / "মাসিক চাঁদা প্রযোজ্য নয়"** — never
paid, never unpaid.

## Payments

- **Record** (`payments.create`) and **verify** (`payments.approve`) are separate actions. Recording changes no due; an
  unverified payment never makes anything paid. An unverified entry recorded in error is **cancelled** with a reason
  (kept, never deleted); verified money is never cancelled away.
- A payment is recorded **for a month** (at most what that month still owes, unless the admin ticks "keep the rest as
  advance credit"), **as an advance**, or as a **voluntary** contribution (verified, never applied to dues).
- **Verification** applies the payment to its own month (at most what it still owes); the rest is advance credit.
  Verifying twice, or two admins at once, applies it once.
- **Partial payments**: ৳200 due → ৳100 verified → partially paid → another ৳100 verified → paid.
- **Advance credit** = verified monthly money not yet applied. It is applied automatically, deterministically: the
  **oldest outstanding month first, oldest money first**, at every verification and every generation — so it never
  waits while a month is owed, pays future months as their dues are created, and is never lost (every taka is either
  on a due or still credit). No future due is ever created to absorb it.

## Waivers

`payments.approve`, a reason is required, full (blank amount = everything still owed) or partial. It raises
`waived_amount`; it is **not a payment** and creates no payment row. Recorded on the due's own history with the amount,
reason, admin and time.

## Audit

One history entry per meaningful event, never per month in a loop: `dues_generated` (the months and amounts created in
one run), `monthly_payment_recorded`, `monthly_payment_verified`, `monthly_payment_cancelled`, `credit_applied`,
`waived` (on the due), and with every status change `dues_paused` / `dues_resumed` naming the month it takes effect.
All appear in the member page's history (admin-only), in Bangla or English.

## Where it is shown

- **Member Registry**: a "Monthly contribution" column (where the member stands: overdue · partially paid · due ·
  current · no monthly contribution, plus outstanding total and overdue months) and a filter with those five values.
  The registration-fee column and filter are unchanged.
- **Member page**: a Monthly contributions card — monthly amount, this month's state, outstanding, overdue months,
  advance credit, next month's amount (or "paused" while not active); every month with assessed / paid / waived /
  outstanding / state; record, verify, cancel and waive; the monthly and voluntary payments with the months they paid.
- **Member portal** (`/member/dashboard`, Bangla): the current monthly contribution, this month's state, total
  outstanding, overdue months and the last 12 months. A zero contribution says plainly that nothing is required. No
  reference, internal note, verifier or admin name is ever sent to the portal.

## Membership type readiness

A type that cannot produce a valid member number (no valid code — today the Honorary type) is not offered for new
self-service applications: the public campaign list leaves it out, the application endpoint refuses it, the public type
list still shows it but not as open for self-application (`is_public_self_apply: false`), and the admin type list/page
shows **"Configuration incomplete" / "কনফিগারেশন অসম্পূর্ণ"** with the reasons (no code, no
fee policy in force). Nothing about the type is changed automatically.

## Production acceptance kit

`deploy/qa/membership-registry-qa.php` modes `dues-setup`, `dues-invites`, `dues-inspect` (plus `snapshot`/`cleanup`,
which cover the dues tables, policies and types) and `institutional/scripts/membership-dues-qa.mjs` (phases `approve`,
`flow`). Disposable types `QD` (৳200) and `QZ` (৳0) with slug `qa-dues-test-…` are created by the kit and removed by
`cleanup` together with every policy they ever had.

## Not built

Online payment; refunds of verified money; changing an existing due's amount (by design: a mistake is
waived and, if needed, a new payment recorded).
