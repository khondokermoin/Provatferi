# Member numbers and application numbers

Membership task 3 (2026-10-07). Code: `App\Services\NumberSequence` (the counters), `App\Services\MembershipNumbering`
(the formats), `App\Services\MembershipApprovalService` (approval), `Api\V1\Public\MembershipApplicationController` and
`MembershipApplication::booted()` (applications). Tests: `tests/Feature/Admin/MembershipNumberingTest.php`,
`MembershipNumberingConcurrencyTest.php` (real simultaneous processes, `tests/Support/numbering-worker.php`).

## The formats

| Number | Format | Example | Counter |
|---|---|---|---|
| Member number | `PLCC-{type code}-{year}-{nnnn}` | `PLCC-LM-2026-0001` | one per type and year: `member:LM:2026` |
| Application number | `APP-{year}-{nnnn}` | `APP-2026-0001` | one per year: `application:2026` |

- `PLCC` is `config('membership.number_prefix')`.
- `{type code}` is the membership type's own code (`membership_types.code`): LM, GM, ST today, and whatever code an
  admin gives a type later (for example `HM`). Nothing in the code knows any particular type.
- `{year}` is the year on the organisation's calendar (Asia/Dhaka, `config('membership.timezone')`) of the day the
  number is issued — the approval for a member number, the submission for an application number. At 00:01 on
  1 January in Dhaka (18:01 UTC on 31 December) numbers already start again at 0001.
- `{nnnn}` has at least four digits and simply grows past 9999.

## The rules

1. **From a counter, never from a table id.** Nothing uses `max(id)+1`, `count()+1` or AUTO_INCREMENT.
2. **Concurrency-safe.** `NumberSequence::next()` increments the counter's row with one atomic statement
   (`INSERT … ON DUPLICATE KEY UPDATE last_value = last_value + 1`) inside the transaction that stores the number; the
   row stays locked until that transaction ends, so simultaneous approvals or submissions are served one after the
   other and can never receive the same number. The unique indexes on `memberships.member_code` and
   `membership_applications.application_no` are the database's own backstop.
3. **Issued only with the record.** The number is taken in the same transaction that inserts the membership (or the
   application). If that transaction rolls back — the approval is refused, the insert fails — the increment rolls back
   with it: nothing was issued, nothing is skipped. A request refused by validation never takes a number.
4. **Never reused.** Nothing in the application lowers a counter. Archiving a membership, or deleting a membership or an
   application, does not free its number. A number some row already carries (an import, a record made by hand) is
   skipped, so it is never issued twice.
5. **Immutable.** `Membership::member_code`, `Member::member_code` and `MembershipApplication::application_no` cannot be
   changed once set — the models refuse it. Profile edits, status actions and anything else leave the number alone.
6. **Idempotent approval.** A retried or repeated approval returns the number already issued and takes nothing from the
   counter (the application row is locked; an approved application returns its membership before any number is taken).
7. **A type needs a code.** A type without a valid code cannot issue member numbers, so approval of its applications is
   refused with an explanation ("Member number" in the approval check, and the type's page says why), until an admin
   sets the code — once, it is permanent. Applications to such a type can still be received.

## Existing data

The migration (`2026_10_07_100000_create_number_sequences_table`) only adds the counter table. Each counter starts
above the highest number already issued in the new formats (`APP-{year}-{n}`, `PLCC-{code}-{year}-{n}`). Member numbers
in the old format (`PF-{year}-{nnnn}`) belong to no counter and stay exactly as issued. On production on 2026-10-07 there
were no applications and no memberships at all, so every counter starts at 0001.

## Search

The registry finds a member number whole (`PLCC-LM-2026-0001`), in part (`LM-2026`, `0001`, `plcc-st`) or written without
its leading zeros (`ST-2026-1` finds `PLCC-ST-2026-0001`); the applications list does the same for application numbers
(`2026-7` finds `APP-2026-0007`).

## Where the number is shown

Applications list and review page; Member Registry list (with search and filters) and member page; the approval e-mail
and the password-setup e-mail; the member portal dashboard. The public member directory never shows member numbers.

## Production acceptance without using up real numbers

A real-browser acceptance run on production necessarily takes real numbers (it must show `PLCC-ST-2026-0001` through the
real admin). The application never gives a number back; only the QA kit's `cleanup`
(`deploy/qa/membership-registry-qa.php`) may, and only when it can prove the numbers were QA's alone: with every counter
row locked, it maps every number held by any row (QA or not) and lowers a counter only through the top run of values held
by QA rows, stopping at the first value held by a real row or by no row at all, and deletes those QA rows in the same
transaction. Its output lists, per counter, the value before and after, how many values were released and why it stopped.
