<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\JobPosting;
use Illuminate\Http\JsonResponse;

/**
 * Public read-only contract. Response shape changed 2026-09-08 (Phase 1C):
 * added `summary`/`opening_date`; GET /job-postings/{id-or-slug} now accepts
 * either. Only `open` postings are listed or shown — draft, closed and the
 * new `archived` status are all excluded, matching "archived jobs are not
 * public".
 */
class JobPostingController extends Controller
{
    public function index(): JsonResponse
    {
        $jobs = JobPosting::query()
            ->where('status', 'open')
            ->orderByDesc('published_at')
            ->get($this->publicColumns());

        return response()->json(['data' => $jobs]);
    }

    public function show(string $jobPosting): JsonResponse
    {
        $model = JobPosting::query()
            ->select($this->publicColumns())
            ->where('status', 'open')
            ->where(fn ($q) => $q->where('id', $jobPosting)->orWhere('slug', $jobPosting))
            ->firstOrFail();

        return response()->json(['data' => $model]);
    }

    /** @return array<int, string> */
    private function publicColumns(): array
    {
        return [
            'id', 'title', 'slug', 'summary', 'department', 'description', 'requirements',
            'employment_type', 'salary_range', 'opening_date', 'application_deadline', 'published_at',
        ];
    }
}
