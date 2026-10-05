# Membership types and effective-dated fee policies

Membership Registry, task 1 (2026-10-05). This is the reference for what a membership type is, where its price lives, and
why changing a price can never rewrite history. Code: `app/Services/MembershipFeePolicyService.php` (the rules),
`app/Models/MembershipFeePolicy.php`, `app/Http/Controllers/Admin/MembershipTypeController.php` (the screens), the three
migrations dated `2026_10_05_12*`, and `tests/Feature/Admin/MembershipFeePolicyTest.php` (every rule below has a test).

## The model

A **membership type** is metadata. Its columns, and the names the owner used for them:

| Owner's name | Column | Notes |
|---|---|---|
| name_bn / name_en | `name` / `name_en` | the tabbed BN/EN editor |
| description_bn / description_en | `description` / `description_en` | |
| **code** | `code` (new) | `LM`, `GM`, `ST` … short, upper-case, unique, **permanent once set**. Business logic keys off this, never off a display name |
| active | `status` (`active` / `inactive`) | inactive = off every public surface; nothing already recorded is touched |
| self_apply_enabled | `is_public_self_apply` | may a visitor choose it on the application form |
| **public_visible** | `is_public_visible` (new) | shown on the public membership page and form at all |
| display_order | `sort_order` | up/down on the list |

A type's **fees are not stored on it**. The old flat `membership_types.fee` column is **legacy**: it is left in place (no
destructive migration), nothing reads or writes it, and it is no longer fillable. What a type costs on a given day is
answered by its **fee policies** (`membership_fee_policies`): one row per version — `registration_fee`,
`monthly_contribution` (both `decimal(10,2)`, handled as strings, never floats), `effective_from`, `effective_until`
(nullable = until further notice), `active` (false = cancelled), `created_by`, `note`, and the cancellation audit fields.

## The rules (all enforced in `MembershipFeePolicyService`)

1. **Append-only.** A fee change creates a new row. A row's amounts are never edited.
2. **One policy per day.** The policy for a day is the *active* row whose period contains it. Periods cannot overlap, and
   two active policies cannot start on the same day (the database also refuses that: a unique index over a generated
   column that is `NULL` for cancelled rows).
3. **Contiguous timeline.** Each policy ends the day before the next one begins; the last is open-ended. Adding or
   cancelling a policy re-derives those end dates — the only thing ever changed on an existing row, and only for days
   that have not begun.
4. **No backdating.** A new policy starts today or later (that would rewrite what an elapsed day cost). Future-dated
   policies are supported.
5. **Zero is valid; negative is not.** An amount is a plain non-negative number with at most two decimals and at most
   eight integer digits; `1e3`, `-5`, `5,00`, `0.125` and the empty string are refused, not guessed at.
6. **Cancelling** is allowed only for a policy that has not started and that no application refers to. The row stays,
   marked cancelled with who/when/why; the previous policy continues. A policy that has started can never be cancelled.
7. **The organisation's calendar.** "Day" means a calendar day in `config('membership.timezone')` (**Asia/Dhaka**), not the
   server's UTC day: an application submitted at 01:00 on 1 December in Dhaka is quoted the 1 December policy.
8. Every change first locks the type's row, so concurrent fee changes for one type are serialised.

## Historical safety — what protects old records

* An **application** stores its own quote when it is created (`MembershipApplication::booted()`): `fee_policy_id` (an
  explicit reference, `RESTRICT` so a referenced policy can never be deleted), the exact `registration_fee_amount` and
  `monthly_contribution_amount` copied from it, `fee_snapshot_source` (`policy`, or `legacy_flat_fee` for an application
  older than fee policies) and `fee_effective_on` (the day the lookup used). Changing a fee later cannot change what it owes.
* **Approval, the waive action and the payment form read that quote**, never the type's current policy. Raising Student
  from 0 to 100 does not suddenly stop an application submitted under the free policy from being approved.
* An application **with no recorded fee** (its type had no policy in force that day) is **never treated as free**: it
  needs a verified payment or an explicit waiver like any fee-bearing one.
* A **payment** already stores `amount_expected`; **memberships** carry no fee state yet (the dues task will add its own
  ledger) and link to their application, which carries the quote.
* A type **without a policy in force today** is not offered publicly and cannot be applied to.

## The public contract

`GET /api/v1/membership-types` and `GET /api/v1/public/membership/campaigns/current` now carry, per type,
`registration_fee`, `monthly_contribution` (decimal strings — the policy in force today), `fee_effective_from`, `code`,
and `name_en` / `description_en`. `fee` remains as a **deprecated alias** of `registration_fee` so a build of the public
site from before this change (whose response check requires `fee`) keeps rendering. A type is listed only if it is
active, public-visible and has a policy in force.

The public site shows the two figures on every type card and, in the application form, for the type the visitor selects
(`lib/fees.ts`): "৳500" / "৳০" — Bengali digits on the Bangla site, "Registration fee" / "Monthly contribution" ("নিবন্ধন ফি" /
"মাসিক চাঁদা"), a free tier reads ৳0, never "Free". It is a quote only; nothing collects money.

## The one-time load of the owner-approved schedule

`php artisan membership:load-initial-policies` (a **dry run** unless `--apply`; idempotent; all-or-nothing) —
`app/Services/MembershipInitialPolicyLoader.php`. It matches the three owner types by seeded slug **and** Bangla name,
sets the code / English name / display order only where they are unset, and creates the first policy of every type that
has none, effective today:

| Type | Code | Registration | Monthly |
|---|---|---|---|
| Lifetime (`life`) | LM | 500 | 200 |
| General (`general`) | GM | 100 | 0 |
| Student (`student`) | ST | 0 | 0 |
| anything else (honorary) | — | carried over from its legacy flat fee (registration), monthly 0 — the status quo, not an owner-approved figure | |

These numbers are **data, not business logic**: after the load the database is the only source of truth and nothing in the
application reads the table in the loader. It refuses (writing nothing) when a type matches by slug but not by name, a code
is taken by another type, or an owner type is missing; it never creates a type.

## Operating it

* **Change a fee:** Admin ▸ Membership ▸ Membership Types ▸ the type ▸ *New fee policy* (amounts, effective date, reason).
* **Undo a mistaken future policy:** the *Cancel policy* button on its row in the history (a reason is required).
* **A policy in force is wrong:** it cannot be edited or cancelled — create a corrected one from the next day. Applications
  already quoted keep their quote by design.
* **Acceptance / re-proof on a deployed environment:** `deploy/qa/membership-fees-qa.php` (server side: snapshot with
  before/after checksums, a disposable open season, cleanup) and `institutional/scripts/membership-fees-qa.mjs` +
  `membership-form-fees-qa.mjs` (real Chrome: the Admin screens, the public cards and the application form).
