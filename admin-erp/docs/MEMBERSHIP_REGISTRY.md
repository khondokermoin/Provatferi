# Membership: application → review → approval → Member Registry

Membership Registry task 2 (2026-10-06). Builds on the fee policies of task 1 (`docs/MEMBERSHIP_FEE_POLICIES.md`).
Code: `App\Services\MembershipApprovalService`, `App\Http\Controllers\Admin\MembershipController` (applications),
`App\Http\Controllers\Admin\MemberController` (registry), `App\Support\MembershipPaymentState`,
`App\Support\MembershipHistory`. Tests: `tests/Feature/Admin/MembershipApprovalFlowTest.php`,
`MemberRegistryTest.php`, `MembershipManagementTest.php`.

## The three records

| Record | What it is | Created by |
|---|---|---|
| `MembershipApplication` | what a person submitted on the public form, with the fee it was **quoted** that day | the public form |
| `Member` | the person: contact and profile details, the member-portal account, the public profile switches | approval (or an existing account is linked) |
| `Membership` | the registry row: member number, type, joining date, registry status | approval only — never by hand |

## Application workflow

`pending → under_review → (need_information ⇄ under_review) → approved | rejected | cancelled`.
`approved`, `rejected` and `cancelled` are final (`MembershipApplication::TRANSITIONS`). On the review page each move is
its own button: **Start review**, **Request information** (a message *to the applicant*, required, e-mailed),
**Approve**, **Reject** (a reason *to the applicant*, required, e-mailed), **Cancel** (no e-mail), and **Add internal
note** at any time. Internal notes are never e-mailed and never public. Every move, note and payment step is written
to `approval_history` with who and when.

Asking for information sends the request by e-mail; the applicant answers by replying. There is no applicant-side
correction form for membership applications yet (see "Not built").

## Approval — the exact rules

Approval runs in one database transaction with the application row locked (`SELECT … FOR UPDATE`).

1. **Idempotent.** An application that is already approved returns the membership it produced and writes nothing. Two
   simultaneous approvals, a double click, or a retried request create exactly one member, one membership, one account
   and one "approved" history entry. `memberships.membership_application_id` is `UNIQUE` as a database backstop.
2. **Status.** Only from `under_review` or `need_information`.
3. **Payment rule** (unchanged from before this task; `MembershipPaymentState`):
   - the application's **quoted** registration fee is `0` (e.g. Student under today's policy) → **no payment needed**;
     approval is allowed at once and no payment record is ever created;
   - the quoted fee is above `0` (General ৳100, Lifetime ৳500 today) → approval stays blocked until **every** recorded
     payment is **paid and verified** by an admin (`payments.approve`), or the fee is **waived** with a written reason;
     recording cash (`payments.create`) and verifying it are two separate actions;
   - no fee quoted at all (a type with no policy that day) → treated like a fee-bearing one, never as free.
   If the verified amount received is less than the quoted fee, the review page shows a warning; approval itself
   follows the rule above. Payment state is shown everywhere as one of: *no fee due, paid (verified), waived, awaiting
   verification, unpaid, no fee recorded* — a zero fee is never shown as unpaid.
4. **Who is this person? (duplicate safety)** Existing member accounts are looked up by e-mail and by mobile (mobiles
   compared in one form: `+880 1712-345678`, `8801712345678`, `01712345678` are the same — `App\Support\PhoneNumber`):

   | Match | Result |
   |---|---|
   | none | a new member account is created |
   | one account matches **both** e-mail and mobile | the membership is linked to it |
   | one account matches **one** of them | blocked until an admin ticks "same person" for that account, then linked |
   | e-mail → one account, mobile → another | blocked (conflict) — nothing is merged |
   | the matching account was removed | blocked (conflict) |
   | the person already holds an **active or suspended** membership | blocked (conflict) — no second membership |

   A conflict is explained on the review page with the details side by side; the way forward is to ask for
   information, reject or cancel.
5. **Member number** (since task 3, `docs/MEMBERSHIP_NUMBERING.md`): `PLCC-{type code}-{year}-{nnnn}`, e.g.
   `PLCC-LM-2026-0001`, from the counter of the type for the year of approval, taken inside the transaction — never a
   table id, never reused, never changed (the year and the joining date use the organisation's calendar, Asia/Dhaka). A
   type without a code cannot be approved until it has one. A new account also gets the number as its own
   `members.member_code`.
6. **Carried onto the member**: address, profession/education and institution (optional fields of the public form);
   for a linked account only **empty** fields are filled — nothing already recorded is overwritten. After the
   transaction the application photo is copied as a resized **private** JPEG (`uploads_private/members/…`, ≤ 480 px).
7. **Account**: a new account gets a random password nobody knows. After the response, the applicant gets the status
   e-mail (with the member number) and a **password-setup link** made by the existing `members` password broker — the
   same token table, expiry (60 minutes) and reset page as "forgot password"; the e-mail says to use "forgot password"
   if the link has expired. A linked account that has never signed in gets the link too. No password is ever sent or
   stored in plain text.

## Member Registry (Admin → Membership → Member Registry)

List: photo, name, member number, mobile, e-mail, type, joining date, profession/institution, registration fee and its
state, public-profile state, status. Search: name, mobile (any spelling), e-mail, member number. Filters: status, type,
fee state, public profile, joining date from/to; 15/30/50 per page.

Member page: identity, contact & profile, the membership (source application, the fee quoted at application, payment
state and records, approval), other memberships of the same person, public profile (member's switch + admin approval,
pending versions to approve/reject), portal account (sign-in allowed/blocked, last sign-in, link sent), and one history
timeline (application, membership, account and profile events — admin-only).

**Status actions** (`membership.approve`), the only way a status changes after approval — each recorded with a reason:

| Action | From | To | Reason |
|---|---|---|---|
| Activate | inactive, expired | active | optional |
| Suspend | active | suspended | **required** |
| Reactivate | suspended, archived | active | optional |
| Archive | active, suspended, inactive, expired | archived | **required** |

Nothing deletes a member. The portal account follows: it may sign in only while a membership is active; suspending or
archiving revokes every portal token at once (and the API refuses any token of a non-active member).

**Editing** (`membership.update`): name, e-mail (also the portal login; unique), mobile (unique, normalised), address,
profession, institution, and the membership's internal notes and term end. Every change is recorded with the old and
the new value. Changing the membership **type** is not available (a later task).

## Public profile

Unchanged moderation: visible only when the member turned it on **and** an admin approved it **and** the account is
active. Contact details, member number, address and the private photo are never public. The public site caches the
directory under the `members` tag; a suspension, archive, profile decision or name change sends a signed revalidation
(`App\Observers\MemberPublicSiteObserver`), so the directory follows at once.

## Production acceptance kit

`deploy/qa/membership-registry-qa.php` (snapshot · season-open · season-close · inspect · invite-link · seed · cleanup ·
sweep) and `institutional/scripts/membership-registry-qa.mjs` (phases apply · review · registry · portal). QA rows are
marked "QA REGISTRY TEST" / `khondokermoin2k23+qareg…`; `cleanup` removes them and nothing else.

## Monthly dues

Task 4 added the monthly contribution ledger: a "Monthly contribution" column and filter in the registry, a Monthly
contributions card on the member page, and dues pausing / resuming with every status action — see
`docs/MEMBERSHIP_DUES.md`.

## Not built (by design, later tasks)

Membership type change, an applicant-side correction form for "information needed", online payment. (Receipts are task 5 — `docs/MEMBERSHIP_RECEIPTS.md`. The
member-number redesign was task 3 — `docs/MEMBERSHIP_NUMBERING.md`; monthly dues task 4 — `docs/MEMBERSHIP_DUES.md`.)
