<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Models\JobPosting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class JobApplicationController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'posting' => (string) $request->query('posting', ''),
        ];

        $applications = JobApplication::query()
            ->with('jobPosting')
            ->when($filters['search'] !== '', function ($q) use ($filters) {
                $term = '%'.$filters['search'].'%';
                $q->where(fn ($w) => $w->where('applicant_name', 'like', $term)->orWhere('applicant_email', 'like', $term));
            })
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['posting'] !== '', fn ($q) => $q->where('job_posting_id', $filters['posting']))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.recruitment.applications.index', [
            'title' => 'নিয়োগ আবেদন',
            'breadcrumbs' => [['label' => 'নিয়োগ'], ['label' => 'আবেদনসমূহ']],
            'applications' => $applications,
            'filters' => $filters,
            'statuses' => JobApplication::STATUSES,
            'postings' => JobPosting::query()->orderBy('title')->pluck('title', 'id'),
        ]);
    }

    public function show(JobApplication $jobApplication): View
    {
        $jobApplication->load('jobPosting');

        return view('admin.recruitment.applications.show', [
            'title' => $jobApplication->applicant_name,
            'breadcrumbs' => [['label' => 'আবেদনসমূহ', 'route' => 'admin.recruitment.applications.index'], ['label' => $jobApplication->applicant_name]],
            'application' => $jobApplication,
            'statuses' => JobApplication::STATUSES,
        ]);
    }

    public function updateStatus(Request $request, JobApplication $jobApplication): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(JobApplication::STATUSES))],
            'interview_notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['status' => 'স্ট্যাটাস']);

        $jobApplication->update([
            'status' => $data['status'],
            'interview_notes' => $data['interview_notes'] ?? $jobApplication->interview_notes,
            'reviewed_by' => $request->user()->id,
        ]);

        return redirect()->route('admin.recruitment.applications.show', $jobApplication)
            ->with('success', 'আবেদনের স্ট্যাটাস হালনাগাদ হয়েছে।');
    }
}
