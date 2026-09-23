<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Notifications\VolunteerApplicationStatusChangedNotification;
use App\Services\ApplicationDocumentService;
use App\Services\NoticeFileService;
use App\Services\RecruitmentPdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * §7: review of applications filed through the public form. Everything an
 * applicant submitted is visible here and nowhere else — the public API has
 * no route that reads an application back, and the internal note never
 * leaves this screen.
 */
class JobApplicationController extends Controller
{
    public function __construct(
        private readonly ApplicationDocumentService $documents,
        private readonly NoticeFileService $files,
        private readonly RecruitmentPdfService $pdf,
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'posting' => (string) $request->query('posting', ''),
            'skill' => (string) $request->query('skill', ''),
            'district' => (string) $request->query('district', ''),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
        ];

        $applications = JobApplication::query()
            ->with('jobPosting')
            ->when($filters['search'] !== '', function ($q) use ($filters) {
                $term = '%'.addcslashes($filters['search'], '%_\\').'%';
                $q->where(fn ($w) => $w->where('applicant_name', 'like', $term)
                    ->orWhere('applicant_email', 'like', $term)
                    ->orWhere('applicant_phone', 'like', $term)
                    ->orWhere('application_no', 'like', $term));
            })
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['posting'] !== '', fn ($q) => $q->where('job_posting_id', $filters['posting']))
            ->when($filters['skill'] !== '', fn ($q) => $q->whereJsonContains('skills', $filters['skill']))
            ->when($filters['district'] !== '', fn ($q) => $q->where('district', $filters['district']))
            ->when($filters['from'] !== '', fn ($q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn ($q) => $q->whereDate('created_at', '<=', $filters['to']))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.recruitment.applications.index', [
            'title' => 'আবেদনসমূহ',
            'breadcrumbs' => [['label' => 'নিয়োগ'], ['label' => 'আবেদনসমূহ']],
            'applications' => $applications,
            'filters' => $filters,
            'statuses' => JobApplication::STATUSES,
            'skills' => JobApplication::SKILLS,
            'districts' => JobApplication::query()->whereNotNull('district')->distinct()->orderBy('district')->pluck('district', 'district')->all(),
            'postings' => JobPosting::query()->orderBy('title')->pluck('title', 'id'),
        ]);
    }

    public function show(JobApplication $jobApplication): View
    {
        $jobApplication->load(['jobPosting', 'reviewer']);

        return view('admin.recruitment.applications.show', [
            'title' => $jobApplication->applicant_name,
            'breadcrumbs' => [['label' => 'আবেদনসমূহ', 'route' => 'admin.recruitment.applications.index'], ['label' => $jobApplication->applicant_name]],
            'application' => $jobApplication,
            'statuses' => JobApplication::STATUSES,
            'contactLabels' => JobApplication::PREFERRED_CONTACTS,
        ]);
    }

    public function updateStatus(Request $request, JobApplication $jobApplication): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(JobApplication::STATUSES))],
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ], [], ['status' => 'স্ট্যাটাস', 'internal_note' => 'অভ্যন্তরীণ নোট']);

        $previousStatus = $jobApplication->status;

        $jobApplication->update([
            'status' => $data['status'],
            // An empty box leaves the existing note alone rather than wiping it.
            'internal_note' => filled($data['internal_note'] ?? null) ? $data['internal_note'] : $jobApplication->internal_note,
            'reviewed_by' => $request->user()->id,
        ]);

        // Only on a real transition into a decision state: re-saving an already
        // accepted application to edit its internal note must not email the
        // applicant a second time. (Membership's equivalent does not guard this.)
        if ($previousStatus !== $data['status']
            && in_array($data['status'], VolunteerApplicationStatusChangedNotification::NOTIFIABLE_STATUSES, true)) {
            $this->notifyApplicant($jobApplication);
        }

        return redirect()->route('admin.recruitment.applications.show', $jobApplication)
            ->with('success', 'আবেদনের স্ট্যাটাস হালনাগাদ হয়েছে — '.$jobApplication->statusLabel().'।');
    }

    /**
     * An email-transport failure must never turn an already-persisted status
     * change into a 500 — it is logged, not raised. Sends no internal note:
     * the notification has no parameter that could carry one.
     */
    private function notifyApplicant(JobApplication $application): void
    {
        $email = trim((string) $application->applicant_email);
        if ($email === '') {
            return;
        }

        try {
            // withTrashed: a posting closed/removed after the application came in
            // still has a title worth naming; the default scope would return null.
            $title = $application->jobPosting()->withTrashed()->value('title');

            Notification::route('mail', $email)->notify(new VolunteerApplicationStatusChangedNotification(
                $application->application_no,
                $application->status,
                $application->applicant_name,
                $title,
            ));
        } catch (Throwable $e) {
            Log::warning('Volunteer application status notification failed to send.', [
                'application_no' => $application->application_no,
                'status' => $application->status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Print view: plain authenticated HTML, no admin chrome (layouts.print).
     * The photo is fetched by the BROWSER via the normal authenticated file
     * route — the admin viewing this page already has that session, exactly
     * like the detail page's own photo thumbnail.
     */
    public function print(JobApplication $jobApplication): View
    {
        $jobApplication->load(['jobPosting', 'reviewer']);

        return view('admin.recruitment.applications.print', [
            'title' => $jobApplication->applicant_name,
            'application' => $jobApplication,
            'contactLabels' => JobApplication::PREFERRED_CONTACTS,
            // photoFileExists(), not photo_path truthiness — a recorded path
            // whose file is gone must render the placeholder, not an <img>
            // pointing at a route that will 404 (a broken image icon).
            'photoSrc' => $jobApplication->photoFileExists()
                ? route('admin.recruitment.applications.file', [$jobApplication, 'photo'])
                : null,
            'logoSrc' => asset('brand/provatferi-logo-light.png'),
            'generatedAt' => Carbon::now(),
        ]);
    }

    /**
     * PDF download: mPDF renders server-side with no browser/session
     * involved, so the photo and the org mark are embedded as base64 data
     * URIs read directly off disk here — a route URL would mean nothing to
     * mPDF's own HTML parser. Same document.blade.php partial as print(),
     * so the two outputs never drift apart.
     */
    public function pdf(JobApplication $jobApplication): Response
    {
        $jobApplication->load(['jobPosting', 'reviewer']);

        $photoSrc = null;
        if ($jobApplication->photoFileExists()) {
            $mime = $this->files->coverMime($jobApplication->photo_path);
            if ($mime !== 'application/octet-stream') {
                $bytes = Storage::disk('uploads_private')->get($jobApplication->photo_path);
                $photoSrc = "data:{$mime};base64,".base64_encode((string) $bytes);
            }
        }

        $logoPath = public_path('brand/provatferi-logo-light.png');
        $logoSrc = is_file($logoPath)
            ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath))
            : null;

        $html = view('admin.recruitment.applications.document', [
            'application' => $jobApplication,
            'contactLabels' => JobApplication::PREFERRED_CONTACTS,
            'photoSrc' => $photoSrc,
            'logoSrc' => $logoSrc,
            'generatedAt' => Carbon::now(),
        ])->render();

        $bytes = $this->pdf->render($html);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$jobApplication->application_no.'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * The applicant's photo and CV live on the private disk and are streamed
     * only to a signed-in admin holding recruitment.view (enforced on the
     * route). Nothing here is ever promoted to a public path.
     */
    public function file(JobApplication $jobApplication, string $kind): StreamedResponse
    {
        if ($kind === 'cv') {
            abort_unless($jobApplication->cv_path, 404);

            return $this->documents->response($jobApplication->cv_path, "{$jobApplication->application_no}.pdf", 'application/pdf', false);
        }

        abort_unless($jobApplication->photo_path, 404);
        $extension = pathinfo($jobApplication->photo_path, PATHINFO_EXTENSION);

        return $this->documents->response(
            $jobApplication->photo_path,
            "{$jobApplication->application_no}.{$extension}",
            $this->files->coverMime($jobApplication->photo_path),
            true,
        );
    }
}
