# Committee application review: the approval and the applicant's photo

Production bugfix of 2026-10-18 ("Admin cannot approve a committee application — `…/committees/4/submissions/24/approve` answers
422 — and the applicant's photo is not visible in the review page"). Both defects were reproduced on production, on disposable
data, before any code changed; the fix was then proved the same way. Nothing in this document is applicant data.

## What was wrong (proven)

### 1. The 422 is the SECOND request of a decision

`CommitteeSubmissionController::assertTransitionAllowed()` was the only `422` on the approve path (`abort_unless(…, 422, …)`
when the application's status does not allow the move). Every other check on that path is a 404 or a 403, a validation error
that redirects back, or a 500.

A decision takes a few seconds: on production a single click took **3.4 s** with a real 2.3 MB photo (the photo is copied to the
public disk and, at the time, the e-mail to the applicant was sent *before* the answer). That is long enough for a second request
to arrive: a double click, a second tab, a page that was left open. The first request approves; the second finds `approved`,
which does not allow `approved` again, and answered `abort(422)`. Laravel had no `errors/422` view, so the browser got Symfony's
bare "Oops! An Error Occurred — The server returned a '422 Unprocessable Content'" page. The application **was approved**.

Reproduced on production with a QA committee (committee id 5 of that run), through the real public form and the real admin
buttons:

| Step | Result before the fix |
|---|---|
| a real double click (two press/release pairs) on Approve | the browser ended on the bare 422 page; the application was Approved with one audit row (the first request) |
| the review page open in a second tab, Approve in the first, then Approve in the second | the second tab ended on the bare 422 page, deterministically |
| one click | 3433 ms from the click to the review page, then correct |

Production submission #24 (read-only inspection, never touched): committee 4, status **approved**, approved 2026-10-18 09:38:49Z
by the owner's own account, **one** audit row, **one** seat, both photo files present and identical (`sha256` prefix equal). So
the 422 the owner saw was a repeated request on an application that the first request had already approved. The premise "it
cannot be approved" was not true for #24; nothing about #24 needs repair.

### 2. The photo was only ever shown from the public copy an approval makes

Every committee photo lives twice (`PhotoUploadService`): the **original** on the private disk (`uploads_private`, written when
the application is filed) and a **public copy** that `promoteToPublic()` makes *at approval*. The review page looked only at the
public copy (`photo_approved_path`), so while an application was waiting — the one time a reviewer needs the picture — the page
showed a grey placeholder. The original is private (no URL) and nothing streamed it to the admin, unlike the membership
application's photo (`admin.membership.photo`). After approval the public copy existed and the picture appeared, which is why
#24 shows its photo now.

Reproduced on production: a pending QA application filed with a 2.28 MB PNG showed only the placeholder (`photo: null`).
Not a storage problem: private and public files were present, correct and identical for #24; the private root is inside the
release tree but the release pipeline carries it over every switch (see "Persistence" below), the public root is outside it.

## The fix

### One decision, taken once (`CommitteeSubmissionController`)

Approve, reject and request-correction all go through `decide()`:

1. **A cheap pre-check** (reject and correction only, before their fields are validated): a stale page gets its message, not a
   field error.
2. **A transaction with a row lock.** The application is re-read `FOR UPDATE` *inside* the transaction and the state is judged
   from that row, not from the model the route handed over. Two requests for the same application are therefore served one after
   the other; the later one sees the earlier one's result.
3. **Outcomes** (all answered with a redirect to the review page and a plain message in the admin's language, never a 422):

   | Outcome | When | Flash |
   |---|---|---|
   | `done` | the move is allowed | success (or the existing single-seat warning) |
   | `already` | the application is already in the requested state | info: "already approved — by NAME on DATE. Nothing was changed." / "already rejected" / "a correction was already requested" |
   | `blocked` | the move is not allowed from where it is | error naming the state: "This application is now “Rejected”, so it can no longer be approved." |
   | `photo_missing` | approval, a photo is on record but no readable file is behind it | error: nothing was changed; ask the applicant for a correction |
   | `failed` | anything unexpected | error with a reference; everything rolled back |

4. **Compensation.** The public copy is a file, not a row: if the attempt does not complete it is deleted again, so a failed
   approval leaves nothing behind. After the copy the file is checked to exist (`Storage` is configured `throw => false`, so a
   failed write used to return a path to nothing).
5. **Idempotency.** A repeated approval changes nothing: one seat, one audit row, one public copy, one e-mail, the first
   decision stays the recorded one. `CommitteeMember::firstOrCreate` stays as a second guard; no unique index was added (no
   migration).
6. **E-mail after the answer.** The applicant's e-mail is sent with `defer()` — after the response, once, and only for a
   non-4xx/5xx — so it no longer holds the click for seconds. (Not `dispatch(...)->afterResponse()`: that is a terminating
   callback, and a process that handles several requests runs it again for each; PHPUnit does exactly that.) A mail failure is
   still caught and logged.
7. **Logging, metadata only.** `committee_review.conflict` (info) and `committee_review.failed` (error): submission id,
   committee id, actor id, action, state, an error code (`already_applied`, `transition_not_allowed`, `photo_missing`, or the
   exception's class name), time, and for a failure a short reference the admin was shown. Never a name, an address, a note, a
   file name or an exception message. The notification-failure log line lost its `$e->getMessage()` for the same reason.

The old admin path `abort(422)` is gone from this controller. Other `abort(422)` calls elsewhere are unrelated and were left
alone (see "Limitations").

### Approval uses the stored row, not today's form

Nothing in approval reads a form definition: it uses the submission row, its position (a restrictOnDelete foreign key) and its
stored photo path. A historical application — an old committee, a switched-off position, fields the form asks for today but the
row predates — approves exactly as before (`test_an_old_application_is_approved_from_its_own_row…`). No data repair or migration
is needed for existing submissions, and none was made.

### The photo (`CommitteeSubmissionPhoto`, route `admin.committees.submissions.photo`)

`GET /admin/organization/committees/{committee}/submissions/{submission}/photo`, middleware `permission:organization.view` (the
same permission as the review page, which shows the applicant's address and phone anyway).

* The review page shows the **private original** through this route (an `<img>` whose address is the route, never a `/storage/`
  address), falling back to the public copy when the original is gone.
* The route streams with `Content-Type` from the file's first bytes (JPEG, PNG or WebP only), `X-Content-Type-Options: nosniff`,
  `Content-Security-Policy: … sandbox`, `Cache-Control: private, no-store` (Cloudflare never caches it) and a generic inline file
  name (`applicant-photo-<id>.<ext>`): neither the stored name nor anything the applicant typed reaches a header.
* **A path read from the database is never trusted.** It is opened only if it has the exact shape this application writes: the
  private original `committee-submissions/<one plain file name>.<jpg|jpeg|png|webp>`, the public copy
  `committee/<one plain file name>.<…>`. `..`, a leading slash, backslashes, a NUL byte, a nested folder, another private folder
  (CVs live next door), a script name and an URL all fail and are shown as "photo not available" (the `lost` states below).
* Another committee's address for the same application is a 404; so is a non-numeric or unknown submission.
* The whole private upload directory is **not** exposed: the only way to a file is this route, per application, per reviewer.

The three states of the picture (`data-photo-state` on the page, for QA): `available` (image), `none` (no photo was ever
submitted: placeholder and "No photo provided"), `missing` (a path is on record but no readable image: placeholder and "It was
uploaded, but the file is no longer available", plus the hint to request a correction when that is possible).

### The page

* The three decision forms share the shared guard `public/js/provatferi-form-guard.js` with a new optional
  `data-guard-group`: when one is sent, every decision button is disabled and says it is working, and typed-but-unsent text in
  the others no longer raises the "leave site?" prompt, so a double click sends ONE request. (Before the fix a double click
  sometimes sent one request and sometimes two: it sent two in the production reproduction.) The server does not depend on the
  guard: it answers a repeated decision from the row as it is by then.
* An approved application says so ("the applicant now holds a seat on the committee roster") and offers no decision.
* `errors/422.blade.php` (Bangla and English): any other `abort(422)` in the admin panel now shows the branded page, not Symfony's.

## Existing submissions, uploads and the release switch

* Private originals are copied from release to release by the release manager's `switch` and recovered by `reconcile`
  (docs: `deploy/README.md`, "uploads"). Proof for this release: QA applications filed **before** the switch (their photos were
  written to the previous release's private disk) show their photos in the review page **after** it, through the new route.
* Public copies live in the vhost docroot, outside any release.

## Tests

`tests/Feature/Admin/CommitteeSubmissionReviewTest.php` (54 tests including data providers) written *before* the fix: the tests
for the two defects failed on the old code (that was the local reproduction), all pass now. A valid approval; a state that no
longer allows the decision (rejected, unpublished, reject/correction too); a missing or unsafe photo path at approval; repetition
(one seat, one audit row, one copy, one e-mail; a stale model handed to the controller); an old application; rollback with the
public copy removed; metadata-only logs; the photo for a reviewer, headers and bytes; anonymous (redirect to login); a staff
account without the permission (403); the placeholder states; thirteen unsafe paths never read or offered; another committee's
address; the guards on the forms; both admin languages without a raw key; the branded 422 page. The existing test that asserted
the 422 now asserts the redirect and the message.

## Production proof after the release (2026-10-18, 20:08–20:14Z; disposable QA committee + one real application read-only)

`committee-approval-qa.mjs --phase verify`, real Chrome against provatferi.org / admin.provatferi.org: **81/83** on the first run.
The two that did not pass asked for a **404** on `…/photo/..%2f..%2f.env` and `…/photo%2f..%2f..%2f.env`; production answers **403**
there (the host's edge refuses encoded dot-dot paths before Laravel sees them; Laravel's own answer is the 404 seen in the local run
and in PHPUnit). Both are refusals with no image; the check now accepts either and a re-run of just those two probes passes.

| What | Result |
|---|---|
| single click on Approve, same 2.3 MB photo | **454 ms** and 432 ms (3433 ms before the fix) |
| double click | the page ends on the review page, Approved, one audit row, one success message; ONE request leaves the browser |
| stale second tab clicks Approve after the first approved | the review page with "already approved — by NAME on DATE. Nothing was changed." (info); still one audit row, one seat |
| stale tab: Approve after a rejection / Reject twice / correction requested twice | an error naming the state / "already rejected" / "already requested", on the review page; one audit row each; no second link |
| applicant e-mail (disposable inboxes) | arrives, names the committee, **exactly one** although of a double click / a stale second tab (sent after the answer, so `defer()` works on the host) |
| pending application: photo | an image that loaded, from the route, not `/storage/`; 200 `image/png`, the submitted number of bytes, `no-store, private`, `nosniff`, sandbox CSP, `inline; filename=applicant-photo-<id>.png` |
| anonymous / view-only reviewer / account without the permission | redirect to the login / sees the page and the photo, no decision, a hand-sent decision is 403 and changes nothing / 403 for both |
| `..%2f` addresses, submission 0, `abc`, 9999999, a path in the query string, the same application through another committee | refused (403 edge or 404) / 404 / 404 / 404 / the application's own photo byte for byte / 404 |
| no photo / photo file gone / `../../.env` path on record | placeholder with the matching note, route 404; Approve on the last two answers "photo not found, nothing changed" (not a 422, not a 500); Approve with no photo at all works |
| rejected / unpublished application, Approve | an error naming the state on the review page |
| both admin languages, desktop and 390 px phone, light and dark | 40 layout checks: HTTP 200, no sideways scroll, no entity text, no raw key, the photo loaded, no console error; the four messages are in the admin's language |
| uploads and the release switch | QA applications filed **before** the switch (C still pending; A, B, D, E approved by the reproduction runs, so with a public copy) show their photos **after** it, through the new route |

The server side agrees (`deploy/qa/committee-approval-qa.php inspect` on the first QA application): one audit row, one seat, the
public copy byte-identical to the private original, production `APP_DEBUG` false, the Laravel log empty.

Real data: the only real application that exists (#24, committee 4) was looked at with GET requests only (`--phase readonly`,
then `--phase sweep` over every committee): HTTP 200, status Approved, its photo an image that loads through the route
(1254×1254, 2.1 MB), the route answers 200 `image/png`, no decision is offered, no 422. It was not clicked, approved, rejected or
changed at any point.

Cleanup of that run: the kit's diff against the baseline taken before any QA data showed **no difference in any real row or file**
(only 1 QA committee, 15 QA applications, 2 QA staff accounts and 2 QA roles left over); `purge … go` removed exactly those and
17 files, and its own diff afterwards read `identical_to_snapshot: true`. The kit file, the snapshot and every cron of the session
were removed and the admin docroot is back to its baseline (full account in `deploy/state/last-deployed.json`,
`committee_review_2026_10_18.cleanup`).

## Production acceptance kit

* `institutional/scripts/committee-approval-qa.mjs` — real Chrome. `--phase reproduce` (before a fix), `verify` (after: ~70
  checks, desktop and phone, light and dark, Bangla and English, two throw-away staff accounts, anonymous, stale tabs, the real
  double click), `status` (QA committee draft ⇄ upcoming through the real lifecycle form), `readonly` (GET-only look at one real
  application: no click, no screenshot, no name).
* `deploy/qa/committee-approval-qa.php` — run from one-shot crons under a one-off name (`_qa_<id>.php`; see `deploy/README.md`,
  "Cron jobs"): `setup`, `seed`, `inspect`, `inventory`, `snapshot`, `diff`, `logs`, `purge … go`, `forget`. It touches only rows
  carrying the QA marker, never prints a name, address or note, and `purge` refuses to run on production unless the kit has a
  one-off name.

## Traps found on the way

* **An "upcoming" committee is public** (the public API lists `upcoming` ones and their approved members; the site refreshes
  every 5 minutes). A QA committee must be `draft` whenever it is not being filled: set it `upcoming`, file the applications,
  set it `draft` at once. The QA run left one `upcoming` for about 2.5 hours before the first run; no real data was involved.
* The numeric `throttle:N,M` on the public API is one bucket per client address, shared by every visitor (they all reach Laravel
  from the site's server). An application costs two hits (the form page, the post); the committee page answers "not found" while
  the bucket is full. Two applications per 65 s.
* **A kit snapshot taken before a release switch stays in the retired tree**: `storage/app/committee-approval-qa/` is part of the
  release tree and `switch` carries over only the uploads folders, so afterwards the kit says "no snapshot named …". Restore it
  with `cp -n` from `_previous-<stamp>/storage/app/committee-approval-qa/` into the live `storage/app/…` (a cron command may not be
  longer than 255 characters: `mkdir -p` and `cp -n` as two crons a minute apart, absolute paths), and delete the retired copy
  after `forget` so housekeeping is not blocked by it. Better: take the baseline snapshot AFTER the switch.
* The kit's `purge` deletes the files a QA row points to; a QA row seeded with a `../..` path must never turn into a delete. It
  now deletes only paths of the exact shape the application writes and counts the rest (`unsafe_paths_left_alone`).
* Cron commands are limited to 255 characters (the API answers 422 — the status this very task is about).
* `Application::terminate()` runs terminating callbacks again for every request a process handles: use `defer()` for
  once-per-request after-response work (tests make several requests per process).
* `$request->user()` in a test run through `actingAs` and the `web` group: `SetAdminLocale` sets the locale from the user, so a
  test that wants English must give the *user* `ui_locale = en`, not call `app()->setLocale()` before the request.
* A `disk(…)->put()` on a disk configured `throw => false` returns `false` instead of throwing: check the file exists.

## Limitations

* `PublicMemberProfileController::approve/reject` has the same `abort_unless(…, 422)` for an already-reviewed profile version.
  It is a different workflow (the member directory) and was left alone; it now at least shows the branded 422 page.
* Committee routes do not carry `revalidate.public`: the public committee pages catch up with a 5-minute timer
  (`REVALIDATE_SECONDS = 300`), as before.
* A real committee photo whose private file is genuinely lost cannot be approved until the applicant sends a correction (the
  alternative, approving a member without a picture, was judged worse).
