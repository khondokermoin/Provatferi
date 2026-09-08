<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\MembershipType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MembershipController extends Controller
{
    /**
     * Explicit transition map so an application can't jump straight from
     * pending to approved, or be revived after a terminal state.
     *
     * @var array<string, array<int, string>>
     */
    private const TRANSITIONS = [
        'pending' => ['under_review', 'cancelled'],
        'under_review' => ['need_information', 'approved', 'rejected', 'cancelled'],
        'need_information' => ['under_review', 'approved', 'rejected', 'cancelled'],
        'approved' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'type' => (string) $request->query('type', ''),
            'date' => (string) $request->query('date', ''),
        ];

        $applications = MembershipApplication::query()
            ->with(['user', 'membershipType'])
            ->when($filters['search'] !== '', function ($q) use ($filters) {
                $term = '%'.$filters['search'].'%';
                $q->where(fn ($w) => $w->where('application_no', 'like', $term)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term)->orWhere('email', 'like', $term)));
            })
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['type'] !== '', fn ($q) => $q->where('membership_type_id', $filters['type']))
            ->when($filters['date'] !== '', fn ($q) => $q->whereDate('created_at', $filters['date']))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.membership.index', [
            'title' => 'Membership Applications',
            'breadcrumbs' => [['label' => 'Membership'], ['label' => 'Applications']],
            'applications' => $applications,
            'filters' => $filters,
            'statuses' => MembershipApplication::STATUSES,
            'types' => MembershipType::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function show(MembershipApplication $membershipApplication): View
    {
        $membershipApplication->load(['user', 'membershipType', 'organizationUnit', 'reviewer']);

        return view('admin.membership.show', [
            'title' => $membershipApplication->application_no,
            'breadcrumbs' => [['label' => 'Applications', 'route' => 'admin.membership.index'], ['label' => $membershipApplication->application_no]],
            'application' => $membershipApplication,
            'allowedTransitions' => self::TRANSITIONS[$membershipApplication->status] ?? [],
            'statuses' => MembershipApplication::STATUSES,
        ]);
    }

    public function updateStatus(Request $request, MembershipApplication $membershipApplication): RedirectResponse
    {
        $allowed = self::TRANSITIONS[$membershipApplication->status] ?? [];

        // Rule::in([]) correctly rejects every value when the application is
        // in a terminal state — no fallback to the full status list here,
        // or an empty $allowed would silently accept anything.
        $data = $request->validate([
            'status' => ['required', Rule::in($allowed)],
            'review_notes' => ['nullable', 'string', 'max:2000'],
            'rejection_reason' => ['required_if:status,rejected', 'nullable', 'string', 'max:1000'],
        ]);

        $membershipApplication->update([
            'status' => $data['status'],
            'review_notes' => $data['review_notes'] ?? $membershipApplication->review_notes,
            'rejection_reason' => $data['status'] === 'rejected' ? $data['rejection_reason'] : $membershipApplication->rejection_reason,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        if ($data['status'] === 'approved') {
            $this->createMembership($membershipApplication, $request->user()->id);
        }

        return redirect()->route('admin.membership.show', $membershipApplication)
            ->with('success', 'আবেদনের স্ট্যাটাস হালনাগাদ হয়েছে।');
    }

    /**
     * Safe creation of the Member record on approval — one membership per
     * application, guarded by a DB transaction against a double-submit.
     */
    private function createMembership(MembershipApplication $application, int $approvedBy): void
    {
        DB::transaction(function () use ($application, $approvedBy) {
            if (Membership::query()->where('membership_application_id', $application->id)->exists()) {
                return;
            }

            $nextNumber = (Membership::query()->max('id') ?? 0) + 1;

            Membership::query()->create([
                'membership_application_id' => $application->id,
                'user_id' => $application->user_id,
                'membership_type_id' => $application->membership_type_id,
                'member_code' => 'PF-'.now()->format('Y').'-'.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT),
                'start_date' => now()->toDateString(),
                'status' => 'active',
                'approved_by' => $approvedBy,
                'approved_at' => now(),
            ]);
        });
    }
}
