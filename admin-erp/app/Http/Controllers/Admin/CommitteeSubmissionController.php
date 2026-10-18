<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApprovalHistory;
use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\CommitteeSubmission;
use App\Notifications\CommitteeCorrectionRequestedNotification;
use App\Notifications\CommitteeSubmissionStatusChangedNotification;
use App\Services\CommitteeSubmissionPhoto;
use App\Services\PhotoUploadService;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

use function Illuminate\Support\defer;

/**
 * §26-28: admin review of public committee-member submissions. A submission
 * never becomes a real CommitteeMember seat until explicitly approved here —
 * the public form (Phase 4/5) only ever writes to committee_submissions.
 *
 * Production report 2026-10-18: "Admin cannot approve — 422 — and the applicant's photo is not visible". Both were reproduced
 * on production with disposable data (docs/COMMITTEE_SUBMISSION_REVIEW.md):
 *
 *  - The 422 was the SECOND request of a decision. An approval takes a few seconds (it copies the photo and, at the time,
 *    sent the applicant an e-mail before answering), so a double click or a second tab sends the same PATCH twice; the
 *    first one approves, the second finds a state that no longer allows it and used to answer `abort(422)` — a bare
 *    "Unprocessable Content" page, although the application WAS approved. Every decision now runs under a row lock and
 *    re-reads the state inside it, so concurrent or repeated requests are taken one after the other, the later one is
 *    told what happened in a plain message, and nothing is written twice.
 *  - The photo was shown only from the PUBLIC copy an approval creates, so a pending application — the one a reviewer must
 *    look at — showed a placeholder. The review page now shows the private original through an authenticated route.
 */
class CommitteeSubmissionController extends Controller
{
    /** @var array<string, array<int, string>> */
    private const TRANSITIONS = [
        'pending' => ['correction_requested', 'approved', 'rejected'],
        'correction_requested' => ['approved', 'rejected'],
        'approved' => ['unpublished'],
        'rejected' => [],
        'unpublished' => [],
    ];

    public function index(Request $request, Committee $committee): View
    {
        $status = (string) $request->query('status', '');

        $submissions = $committee->submissions()
            ->with(['position', 'member'])
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->latest('submitted_at')
            ->paginate(15)
            ->withQueryString();

        return view('admin.committees.submissions.index', [
            'title' => __('admin.fields.review_applications').' — '.$committee->name,
            'breadcrumbs' => [
                ['label' => __('admin.nav.committees'), 'route' => 'admin.committees.index'],
                ['label' => $committee->name, 'route' => 'admin.committees.show', 'params' => $committee],
                ['label' => __('admin.fields.applications_bare')],
            ],
            'committee' => $committee,
            'submissions' => $submissions,
            'statuses' => status_options(CommitteeSubmission::STATUSES),
            'currentStatus' => $status,
        ]);
    }

    public function show(Committee $committee, CommitteeSubmission $submission, CommitteeSubmissionPhoto $photos): View
    {
        abort_unless($submission->committee_id === $committee->id, 404);

        $submission->load(['position', 'member', 'reviewer', 'history.actor']);
        $photo = $photos->locate($submission);

        return view('admin.committees.submissions.show', [
            'title' => $submission->full_name,
            'breadcrumbs' => [
                ['label' => __('admin.nav.committees'), 'route' => 'admin.committees.index'],
                ['label' => $committee->name, 'route' => 'admin.committees.show', 'params' => $committee],
                ['label' => __('admin.fields.applications_bare'), 'route' => 'admin.committees.submissions.index', 'params' => $committee],
                ['label' => $submission->full_name],
            ],
            'committee' => $committee,
            'submission' => $submission,
            'statuses' => status_options(CommitteeSubmission::STATUSES),
            'nextStatuses' => self::TRANSITIONS[$submission->status] ?? [],
            // The reviewer's picture comes from an authenticated route, never from a public URL (see CommitteeSubmissionPhoto).
            'photoState' => $photo['state'],
            'photoUrl' => $photo['state'] === CommitteeSubmissionPhoto::AVAILABLE
                ? route('admin.committees.submissions.photo', [$committee, $submission])
                : null,
        ]);
    }

    /** The applicant's photo, streamed from the PRIVATE disk (or the approved public copy) to a signed-in reviewer. */
    public function photo(Committee $committee, CommitteeSubmission $submission, CommitteeSubmissionPhoto $photos): StreamedResponse
    {
        abort_unless($submission->committee_id === $committee->id, 404);

        return $photos->response($submission);
    }

    public function approve(Request $request, Committee $committee, CommitteeSubmission $submission, CommitteeSubmissionPhoto $photos, PhotoUploadService $uploads): RedirectResponse
    {
        abort_unless($submission->committee_id === $committee->id, 404);

        $copy = null; // the public copy THIS attempt made: taken away again if the attempt does not complete

        $result = $this->decide($request, $committee, $submission, 'approve', 'approved', function (CommitteeSubmission $locked) use ($request, $committee, $photos, $uploads, &$copy) {
            $position = $locked->position;
            $occupied = $position && $position->isOccupied();

            $approvedPath = $locked->photo_approved_path;
            if (! $approvedPath && $locked->photo_path) {
                $original = $photos->privateOriginal($locked);
                if ($original === null) {
                    // A business answer, not an error: the file the application points to is not there (or not a path this
                    // application would ever have written). Nothing has been changed yet.
                    return ['outcome' => 'photo_missing'];
                }
                $approvedPath = $uploads->promoteToPublic($original, 'committee');
                $copy = $approvedPath;
                if (! Storage::disk('public')->exists($approvedPath)) {
                    throw new RuntimeException('The public copy of the photo was not written.');
                }
            }

            $locked->forceFill([
                'status' => 'approved',
                'photo_approved_path' => $approvedPath,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();

            // The row lock above is what keeps this from running twice for one application; firstOrCreate stays as a second guard.
            CommitteeMember::query()->firstOrCreate(
                ['committee_submission_id' => $locked->id],
                [
                    'committee_id' => $committee->id,
                    'committee_position_id' => $locked->committee_position_id,
                    'start_date' => now(),
                    'status' => 'active',
                ],
            );

            ApprovalHistory::record($locked, 'approved', $request->user());

            return ['warning' => $occupied ? __('admin.flash.position_already_occupied_warning', ['position' => $position->name]) : null];
        }, function () use (&$copy, $uploads) {
            $uploads->deletePublic($copy);
        });

        if ($answer = $this->answer($result, $committee, 'approve', $request)) {
            return $answer;
        }

        $decided = $result['submission'];
        $this->afterResponse(fn () => $this->notifySubmission($decided, new CommitteeSubmissionStatusChangedNotification($committee->name, 'approved')));

        return redirect()->route('admin.committees.submissions.show', [$committee, $decided])
            ->with($result['warning'] ? 'warning' : 'success', $result['warning'] ?? __('admin.flash.submission_approved'));
    }

    public function reject(Request $request, Committee $committee, CommitteeSubmission $submission): RedirectResponse
    {
        abort_unless($submission->committee_id === $committee->id, 404);

        // A decision that can no longer be taken is answered before its fields are checked: the page the admin is looking at is out of date.
        if ($conflict = $this->conflictOf($submission, 'rejected')) {
            return $this->answer(['outcome' => $conflict, 'submission' => $submission], $committee, 'reject', $request);
        }

        $data = $request->validate(['admin_note' => ['required', 'string', 'max:2000']], [], ['admin_note' => __('admin.fields.reason')]);

        $result = $this->decide($request, $committee, $submission, 'reject', 'rejected', function (CommitteeSubmission $locked) use ($request, $data) {
            $locked->forceFill([
                'status' => 'rejected', 'admin_note' => $data['admin_note'],
                'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
            ])->save();

            ApprovalHistory::record($locked, 'rejected', $request->user(), $data['admin_note']);

            return [];
        });

        if ($answer = $this->answer($result, $committee, 'reject', $request)) {
            return $answer;
        }

        $decided = $result['submission'];
        $this->afterResponse(fn () => $this->notifySubmission($decided, new CommitteeSubmissionStatusChangedNotification($committee->name, 'rejected', $data['admin_note'])));

        return redirect()->route('admin.committees.submissions.show', [$committee, $decided])
            ->with('success', __('admin.flash.submission_rejected'));
    }

    /**
     * §27: puts the submission in a correction state and issues a fresh,
     * single-use correction link — the raw token is only ever in this
     * request's flash data, never persisted or logged.
     */
    public function requestCorrection(Request $request, Committee $committee, CommitteeSubmission $submission): RedirectResponse
    {
        abort_unless($submission->committee_id === $committee->id, 404);

        if ($conflict = $this->conflictOf($submission, 'correction_requested')) {
            return $this->answer(['outcome' => $conflict, 'submission' => $submission], $committee, 'request_correction', $request);
        }

        $data = $request->validate(['admin_note' => ['required', 'string', 'max:2000']], [], ['admin_note' => __('admin.fields.correction_reason')]);

        $result = $this->decide($request, $committee, $submission, 'request_correction', 'correction_requested', function (CommitteeSubmission $locked) use ($request, $data) {
            $raw = $locked->issueCorrectionToken(now()->addDays(14));
            $locked->forceFill([
                'status' => 'correction_requested', 'admin_note' => $data['admin_note'],
                'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
            ])->save();

            ApprovalHistory::record($locked, 'correction_requested', $request->user(), $data['admin_note']);

            return ['raw' => $raw];
        });

        if ($answer = $this->answer($result, $committee, 'request_correction', $request)) {
            return $answer;
        }

        $decided = $result['submission'];
        $url = rtrim(config('services.public_site.url'), '/').'/committee/register/correct/'.$result['raw'];

        $this->afterResponse(fn () => $this->notifySubmission($decided, new CommitteeCorrectionRequestedNotification(
            $committee->name, $data['admin_note'], $url, 14,
        )));

        return redirect()->route('admin.committees.submissions.show', [$committee, $decided])
            ->with('success', __('admin.flash.correction_email_sent'))
            ->with('generated_correction_link', $url);
    }

    /** 'already' when the application is in the very state asked for, 'blocked' when the move is not allowed from where it is, null when it may go ahead. */
    private function conflictOf(CommitteeSubmission $submission, string $target): ?string
    {
        if ($submission->status === $target) {
            return 'already';
        }

        return in_array($target, self::TRANSITIONS[$submission->status] ?? [], true) ? null : 'blocked';
    }

    /**
     * One review decision, taken exactly once.
     *
     * The row is locked and read AGAIN inside the transaction, so two clicks, two tabs or two reviewers deciding at the same
     * moment are served one after the other: the later one finds what the earlier one did and gets "already …" or "no longer
     * possible" instead of repeating the work (a second seat, a second public copy, a second audit row, a second e-mail) or
     * a bare 422 page. $apply runs under the lock, only when the move is allowed; it returns extra result keys, or an
     * `outcome` of its own to refuse (nothing is committed then). Anything unexpected rolls everything back, removes what
     * $undo says was made outside the database, is logged with metadata only, and is answered with a reference.
     *
     * @param  Closure(CommitteeSubmission): array<string, mixed>  $apply
     * @param  (Closure(): void)|null  $undo
     * @return array<string, mixed> outcome: done | already | blocked | photo_missing | failed, submission: the row as it is now
     */
    private function decide(Request $request, Committee $committee, CommitteeSubmission $submission, string $action, string $target, Closure $apply, ?Closure $undo = null): array
    {
        try {
            return DB::transaction(function () use ($submission, $target, $apply) {
                $locked = CommitteeSubmission::query()->whereKey($submission->getKey())->lockForUpdate()->firstOrFail();

                if ($conflict = $this->conflictOf($locked, $target)) {
                    return ['outcome' => $conflict, 'submission' => $locked];
                }

                return array_merge(['outcome' => 'done', 'submission' => $locked], $apply($locked));
            });
        } catch (ModelNotFoundException $e) {
            throw $e; // the application was deleted while the page was open: an honest 404, not "the decision failed"
        } catch (Throwable $e) {
            try {
                $undo?->__invoke();
            } catch (Throwable) {
                // Removing the stray copy failed too; the first failure is the one worth reporting.
            }

            $reference = strtoupper(bin2hex(random_bytes(4)));
            Log::error('committee_review.failed', $this->metadata($request, $committee, $submission, $action) + [
                'error_code' => class_basename($e), 'reference' => $reference,
            ]);

            return ['outcome' => 'failed', 'submission' => $submission, 'reference' => $reference];
        }
    }

    /** The answer for every outcome but "done": back to the review page with a plain message, and one log line of metadata. */
    private function answer(array $result, Committee $committee, string $action, Request $request): ?RedirectResponse
    {
        $outcome = $result['outcome'];
        if ($outcome === 'done') {
            return null;
        }

        /** @var CommitteeSubmission $submission */
        $submission = $result['submission'];
        $back = redirect()->route('admin.committees.submissions.show', [$committee, $submission]);

        if ($outcome === 'failed') {
            return $back->with('error', __('admin.committee_review.failed', ['ref' => $result['reference']]));
        }

        Log::info('committee_review.conflict', $this->metadata($request, $committee, $submission, $action) + [
            'error_code' => match ($outcome) {
                'already' => 'already_applied',
                'photo_missing' => 'photo_missing',
                default => 'transition_not_allowed',
            },
        ]);

        return match ($outcome) {
            'already' => $back->with('status', $this->alreadyMessage($submission, $action)),
            'photo_missing' => $back->with('error', __('admin.committee_review.photo_missing_blocked')),
            default => $back->with('error', __('admin.committee_review.blocked_'.($action === 'request_correction' ? 'correction' : $action), [
                'status' => status_label($submission->status),
            ])),
        };
    }

    private function alreadyMessage(CommitteeSubmission $submission, string $action): string
    {
        if ($action === 'approve') {
            $submission->loadMissing('reviewer');

            return $submission->reviewer && $submission->reviewed_at
                ? __('admin.committee_review.already_approved', ['name' => $submission->reviewer->name, 'when' => admin_datetime($submission->reviewed_at)])
                : __('admin.committee_review.already_approved_plain');
        }

        return __($action === 'reject' ? 'admin.committee_review.already_rejected' : 'admin.committee_review.already_correction');
    }

    /** What a log line may say about a decision: ids, the state, the actor — never a name, an address, a note or a file name. */
    private function metadata(Request $request, Committee $committee, CommitteeSubmission $submission, string $action): array
    {
        return [
            'submission_id' => $submission->id,
            'committee_id' => $committee->id,
            'actor_id' => $request->user()?->id,
            'action' => $action,
            'state' => $submission->status,
            'at' => now()->toIso8601String(),
        ];
    }

    /**
     * The e-mail to the applicant goes out AFTER the answer has been sent: it is a mail-server round trip that used to hold
     * the admin's click for seconds — the very window in which a second click arrives. (`defer()` runs the work once, when
     * the response is finished and only if it is not a 4xx/5xx; unlike a terminating callback it is not run again by the
     * next request handled by the same process.)
     */
    private function afterResponse(Closure $work): void
    {
        defer($work);
    }

    /**
     * §40: a mail-transport failure must never turn a successful, already-
     * persisted admin decision into a 500 — the status change and history
     * row are the source of truth; the email is a best-effort side effect.
     */
    private function notifySubmission(CommitteeSubmission $submission, mixed $notification): void
    {
        try {
            Notification::route('mail', $submission->email)->notify($notification);
        } catch (Throwable $e) {
            Log::warning('Committee submission notification failed to send.', [
                'submission_id' => $submission->id, 'notification' => $notification::class, 'error_code' => class_basename($e),
            ]);
        }
    }
}
