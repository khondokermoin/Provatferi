<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApprovalHistory;
use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\CommitteeSubmission;
use App\Notifications\CommitteeCorrectionRequestedNotification;
use App\Notifications\CommitteeSubmissionStatusChangedNotification;
use App\Services\PhotoUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;
use Throwable;

/**
 * §26-28: admin review of public committee-member submissions. A submission
 * never becomes a real CommitteeMember seat until explicitly approved here —
 * the public form (Phase 4/5) only ever writes to committee_submissions.
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
            'title' => 'আবেদন পর্যালোচনা — '.$committee->name,
            'breadcrumbs' => [
                ['label' => 'কমিটি', 'route' => 'admin.committees.index'],
                ['label' => $committee->name, 'route' => 'admin.committees.show', 'params' => $committee],
                ['label' => 'আবেদন'],
            ],
            'committee' => $committee,
            'submissions' => $submissions,
            'statuses' => CommitteeSubmission::STATUSES,
            'currentStatus' => $status,
        ]);
    }

    public function show(Committee $committee, CommitteeSubmission $submission): View
    {
        abort_unless($submission->committee_id === $committee->id, 404);

        $submission->load(['position', 'member', 'reviewer', 'history.actor']);

        return view('admin.committees.submissions.show', [
            'title' => $submission->full_name,
            'breadcrumbs' => [
                ['label' => 'কমিটি', 'route' => 'admin.committees.index'],
                ['label' => $committee->name, 'route' => 'admin.committees.show', 'params' => $committee],
                ['label' => 'আবেদন', 'route' => 'admin.committees.submissions.index', 'params' => $committee],
                ['label' => $submission->full_name],
            ],
            'committee' => $committee,
            'submission' => $submission,
            'statuses' => CommitteeSubmission::STATUSES,
            'nextStatuses' => self::TRANSITIONS[$submission->status] ?? [],
            'photoUrl' => $submission->photo_approved_path
                ? app(PhotoUploadService::class)->publicUrl($submission->photo_approved_path)
                : null,
        ]);
    }

    public function approve(Request $request, Committee $committee, CommitteeSubmission $submission): RedirectResponse
    {
        abort_unless($submission->committee_id === $committee->id, 404);
        $this->assertTransitionAllowed($submission, 'approved');

        $warning = null;

        DB::transaction(function () use ($request, $committee, $submission, &$warning) {
            $position = $submission->position;
            if ($position && $position->isOccupied()) {
                $warning = "\u{201c}{$position->name}\u{201d} পদটি ইতিমধ্যে পূর্ণ — তবু এই সদস্যকে যোগ করা হয়েছে, চাইলে পর্যালোচনা করুন।";
            }

            $photoApprovedPath = $submission->photo_approved_path;
            if (!$photoApprovedPath && $submission->photo_path) {
                $photoApprovedPath = app(PhotoUploadService::class)->promoteToPublic($submission->photo_path, 'committee');
            }

            $submission->forceFill([
                'status' => 'approved',
                'photo_approved_path' => $photoApprovedPath,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();

            CommitteeMember::query()->firstOrCreate(
                ['committee_submission_id' => $submission->id],
                [
                    'committee_id' => $committee->id,
                    'committee_position_id' => $submission->committee_position_id,
                    'start_date' => now(),
                    'status' => 'active',
                ],
            );

            ApprovalHistory::record($submission, 'approved', $request->user());
        });

        $this->notifySubmission($submission, new CommitteeSubmissionStatusChangedNotification($committee->name, 'approved'));

        return redirect()->route('admin.committees.submissions.show', [$committee, $submission])
            ->with($warning ? 'warning' : 'success', $warning ?? 'আবেদন অনুমোদিত হয়েছে — কমিটির সদস্য তালিকায় যুক্ত হয়েছে।');
    }

    public function reject(Request $request, Committee $committee, CommitteeSubmission $submission): RedirectResponse
    {
        abort_unless($submission->committee_id === $committee->id, 404);
        $this->assertTransitionAllowed($submission, 'rejected');

        $data = $request->validate(['admin_note' => ['required', 'string', 'max:2000']], [], ['admin_note' => 'কারণ']);

        $submission->forceFill([
            'status' => 'rejected', 'admin_note' => $data['admin_note'],
            'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
        ])->save();

        ApprovalHistory::record($submission, 'rejected', $request->user(), $data['admin_note']);

        $this->notifySubmission($submission, new CommitteeSubmissionStatusChangedNotification($committee->name, 'rejected', $data['admin_note']));

        return redirect()->route('admin.committees.submissions.show', [$committee, $submission])
            ->with('success', 'আবেদন প্রত্যাখ্যান করা হয়েছে।');
    }

    /**
     * §27: puts the submission in a correction state and issues a fresh,
     * single-use correction link — the raw token is only ever in this
     * request's flash data, never persisted or logged.
     */
    public function requestCorrection(Request $request, Committee $committee, CommitteeSubmission $submission): RedirectResponse
    {
        abort_unless($submission->committee_id === $committee->id, 404);
        $this->assertTransitionAllowed($submission, 'correction_requested');

        $data = $request->validate(['admin_note' => ['required', 'string', 'max:2000']], [], ['admin_note' => 'সংশোধনের কারণ']);

        $raw = $submission->issueCorrectionToken(now()->addDays(14));
        $submission->forceFill([
            'status' => 'correction_requested', 'admin_note' => $data['admin_note'],
            'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
        ])->save();

        ApprovalHistory::record($submission, 'correction_requested', $request->user(), $data['admin_note']);

        $url = rtrim(config('services.public_site.url'), '/').'/committee/register/correct/'.$raw;

        $this->notifySubmission($submission, new CommitteeCorrectionRequestedNotification(
            $committee->name, $data['admin_note'], $url, 14,
        ));

        return redirect()->route('admin.committees.submissions.show', [$committee, $submission])
            ->with('success', 'সংশোধনের জন্য ই-মেইল পাঠানো হয়েছে — লিংকটি নিচে একবারই দেখানো হবে (ব্যাকআপ হিসেবে), এখনই কপি করুন।')
            ->with('generated_correction_link', $url);
    }

    private function assertTransitionAllowed(CommitteeSubmission $submission, string $target): void
    {
        abort_unless(in_array($target, self::TRANSITIONS[$submission->status] ?? [], true), 422, 'এই অবস্থা থেকে এই পরিবর্তন সম্ভব নয়।');
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
                'submission_id' => $submission->id, 'notification' => $notification::class, 'error' => $e->getMessage(),
            ]);
        }
    }
}
