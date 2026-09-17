<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Notifications\VolunteerApplicationReceivedNotification;
use App\Notifications\VolunteerApplicationSubmittedNotification;
use App\Services\ApplicationDocumentService;
use App\Services\PhotoUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * §1/§2: the website form is the system of record for volunteer interest —
 * WhatsApp is a community channel, never the intake. This writes into the
 * existing job_applications table (an application against a job_posting),
 * so there is exactly one application store in the ERP.
 *
 * A posting only accepts submissions here while it is open AND its admin
 * has ticked "আবেদন গ্রহণ করা হবে" (§19), so this endpoint can never be
 * used to file applications against a posting that never invited them.
 * `website` is the honeypot the other public forms already use (§21).
 *
 * The response carries the application number and nothing else: no id, no
 * echo of the submitted data, and no route that could later read it back —
 * an application is readable only inside the ERP, behind recruitment.view.
 */
class VolunteerApplicationController extends Controller
{
    public function __construct(
        private readonly PhotoUploadService $photos,
        private readonly ApplicationDocumentService $documents,
    ) {
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        $posting = JobPosting::query()->with('notice')->where('slug', $slug)->firstOrFail();
        abort_unless($posting->status === 'open' && $posting->accepts_applications, 404);

        // A fixed-window posting stops taking applications once its deadline
        // has passed, without an admin having to close it by hand. A rolling
        // one has no deadline to expire (§20).
        if (! $posting->isRolling() && $posting->application_deadline !== null && $posting->application_deadline->endOfDay()->isPast()) {
            throw ValidationException::withMessages(['status' => 'এই বিজ্ঞপ্তিতে আবেদনের সময়সীমা শেষ হয়েছে।']);
        }

        $data = $request->validate($this->rules(), $this->messages(), $this->attributes());

        $this->refuseDuplicate($posting, $data['applicant_email']);

        $files = $this->storeFiles($request);

        $application = JobApplication::query()->create([
            'application_no' => JobApplication::generateApplicationNo(),
            'job_posting_id' => $posting->id,
            'applicant_name' => $data['applicant_name'],
            'applicant_email' => $data['applicant_email'],
            'applicant_phone' => $data['applicant_phone'],
            'district' => $data['district'],
            'current_location' => $data['current_location'],
            'profession' => $data['profession'],
            'experience' => $data['experience'],
            'skills' => $data['skills'],
            'other_skills' => $data['other_skills'] ?? null,
            'contribution' => $data['contribution'],
            'linkedin_url' => $data['linkedin_url'] ?? null,
            'facebook_url' => $data['facebook_url'] ?? null,
            'portfolio_url' => $data['portfolio_url'] ?? null,
            'availability' => $data['availability'] ?? null,
            'preferred_contact' => $data['preferred_contact'] ?? null,
            'accuracy_declaration' => true,
            'privacy_consent' => true,
            'contact_consent' => true,
            'photo_path' => $files['photo_path'] ?? null,
            'cv_path' => $files['cv_path'] ?? null,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $this->sendNotifications($application, $posting);

        return response()->json(['data' => ['application_no' => $application->application_no]], 201);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'applicant_name' => ['required', 'string', 'max:255'],
            'applicant_email' => ['required', 'email:rfc', 'max:255'],
            // Digits, spaces, dashes, brackets and an optional leading +,
            // with at least eight digits — permissive enough for any real
            // Bangladeshi or international number, strict enough to reject
            // a name or a sentence typed into the phone box.
            'applicant_phone' => ['required', 'string', 'max:30', 'regex:/^\+?[\d][\d\s\-()]{7,}$/'],
            'district' => ['required', 'string', 'max:120'],
            'current_location' => ['required', 'string', 'max:255'],
            'profession' => ['required', 'string', 'max:255'],
            'experience' => ['required', 'string', 'max:5000'],
            'skills' => ['required', 'array', 'min:1'],
            'skills.*' => [Rule::in(array_keys(JobApplication::SKILLS))],
            'other_skills' => ['nullable', 'string', 'max:1000'],
            'contribution' => ['required', 'string', 'max:5000'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
            'facebook_url' => ['nullable', 'url', 'max:255'],
            'portfolio_url' => ['nullable', 'url', 'max:255'],
            'availability' => ['nullable', 'string', 'max:150'],
            'preferred_contact' => ['nullable', Rule::in(array_keys(JobApplication::PREFERRED_CONTACTS))],
            'photo' => ['nullable', 'file', 'max:5120'],
            'cv' => ['nullable', 'file', 'max:5120'],
            'accuracy_declaration' => ['accepted'],
            'privacy_consent' => ['accepted'],
            'contact_consent' => ['accepted'],
            'website' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'applicant_phone.regex' => 'সঠিক মোবাইল নম্বর লিখুন।',
            'skills.required' => 'অন্তত একটি কাজের ক্ষেত্র নির্বাচন করুন।',
            'accuracy_declaration.accepted' => 'তথ্যের সঠিকতার ঘোষণায় সম্মতি দিন।',
            'privacy_consent.accepted' => 'গোপনীয়তা নীতিতে সম্মতি দিন।',
            'contact_consent.accepted' => 'যোগাযোগের অনুমতি দিন।',
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return [
            'applicant_name' => 'পূর্ণ নাম',
            'applicant_email' => 'ই-মেইল',
            'applicant_phone' => 'মোবাইল নম্বর',
            'district' => 'জেলা',
            'current_location' => 'বর্তমান অবস্থান',
            'profession' => 'পেশা / শিক্ষা',
            'experience' => 'কাজের অভিজ্ঞতা',
            'skills' => 'আগ্রহ ও দক্ষতার ক্ষেত্র',
            'contribution' => 'অবদানের পরিকল্পনা',
            'photo' => 'প্রোফাইল ছবি',
            'cv' => 'সিভি',
        ];
    }

    /**
     * One live application per person per posting. A withdrawn or closed-out
     * application is not live, so someone genuinely re-applying later is not
     * blocked; the message never reveals anything about the earlier row
     * beyond the fact that it exists for this e-mail.
     */
    private function refuseDuplicate(JobPosting $posting, string $email): void
    {
        $exists = JobApplication::query()
            ->where('job_posting_id', $posting->id)
            ->whereRaw('LOWER(applicant_email) = ?', [mb_strtolower($email)])
            ->whereNotIn('status', JobApplication::REAPPLY_ALLOWED_AFTER)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'applicant_email' => 'এই ই-মেইল দিয়ে ইতিমধ্যে একটি আবেদন জমা হয়েছে — আমরা যাচাই করে যোগাযোগ করব।',
            ]);
        }
    }

    /**
     * Both files land on the private disk under server-generated names and
     * stay there: nothing here promotes anything to a public location.
     *
     * @return array<string, string>
     */
    private function storeFiles(Request $request): array
    {
        $stored = [];

        try {
            if ($request->hasFile('photo')) {
                $stored['photo_path'] = $this->photos->storePrivate($request->file('photo'), 'applications/photos');
            }
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['photo' => $e->getMessage()]);
        }

        try {
            if ($request->hasFile('cv')) {
                $stored['cv_path'] = $this->documents->storeCv($request->file('cv'));
            }
        } catch (RuntimeException $e) {
            // A rejected CV must not leave an orphaned photo behind.
            if (isset($stored['photo_path'])) {
                $this->photos->deletePrivate($stored['photo_path']);
            }
            throw ValidationException::withMessages(['cv' => $e->getMessage()]);
        }

        return $stored;
    }

    /** Mail failure must never cost the applicant their submission — it is logged, not raised. */
    private function sendNotifications(JobApplication $application, JobPosting $posting): void
    {
        $notice = $posting->notice;
        $communityUrl = $notice !== null && $notice->isPubliclyVisible() ? $notice->action_url : null;

        try {
            Notification::route('mail', $application->applicant_email)->notify(
                new VolunteerApplicationReceivedNotification($application->application_no, $posting->title, $communityUrl)
            );
        } catch (Throwable $e) {
            Log::warning('Volunteer application receipt failed to send.', [
                'application_no' => $application->application_no,
                'error' => $e->getMessage(),
            ]);
        }

        $operations = config('mail.reply_to.operations');
        if (! is_string($operations) || $operations === '') {
            return;
        }

        try {
            Notification::route('mail', $operations)->notify(
                new VolunteerApplicationSubmittedNotification(
                    $application->application_no,
                    $posting->title,
                    $application->applicant_name,
                    route('admin.recruitment.applications.show', $application),
                )
            );
        } catch (Throwable $e) {
            Log::warning('Volunteer application admin notification failed to send.', [
                'application_no' => $application->application_no,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
