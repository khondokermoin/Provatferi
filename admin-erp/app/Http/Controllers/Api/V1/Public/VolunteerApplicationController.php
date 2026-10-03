<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Jobs\SendVolunteerApplicationNotifications;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Services\ApplicationDocumentService;
use App\Services\PhotoUploadService;
use App\Support\PhaseTimer;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

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
 *
 * Speed and double-submit safety (2026-10-03): the applicant's receipt and the
 * operations heads-up are sent AFTER the response (see
 * SendVolunteerApplicationNotifications) — they used to be two synchronous SMTP
 * sessions inside the request, ~3 s of the applicant's wait. And the form sends a
 * `submission_token` (random, once per form) so the same attempt arriving twice
 * — a double click that got past the browser, a retry after a response that
 * never arrived — returns the original result instead of a second application or
 * a confusing "already applied" error. The token is UNIQUE in the database; the
 * per-person lock below keeps two different-token requests from the same person
 * from racing past refuseDuplicate().
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
        $timer = PhaseTimer::begin();

        $posting = JobPosting::query()->where('slug', $slug)->firstOrFail();
        abort_unless($posting->status === 'open' && $posting->accepts_applications, 404);

        // A fixed-window posting stops taking applications once its deadline
        // has passed, without an admin having to close it by hand. A rolling
        // one has no deadline to expire (§20).
        if (! $posting->isRolling() && $posting->application_deadline !== null && $posting->application_deadline->endOfDay()->isPast()) {
            throw ValidationException::withMessages(['status' => 'এই বিজ্ঞপ্তিতে আবেদনের সময়সীমা শেষ হয়েছে।']);
        }
        $timer->lap('lookup');

        $data = $request->validate($this->rules($posting), $this->messages(), $this->attributes());
        $timer->lap('validate');

        [$token, $replayed] = $this->resolveToken($this->submissionToken($request), $posting);
        if ($replayed !== null) {
            return $this->accepted($request, $replayed, $timer, replayed: true);
        }

        // One person's submissions for one posting go through here one at a time.
        $lock = Cache::lock('volunteer-apply:'.$posting->id.':'.sha1(mb_strtolower($data['applicant_email'])), 30);
        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['status' => 'আপনার আবেদনটি এখনও প্রক্রিয়াধীন — একটু পরে আবার চেষ্টা করুন।']);
        }
        $timer->lap('lock');

        try {
            // The attempt we were waiting behind may have been this very one.
            [$token, $replayed] = $this->resolveToken($token, $posting);
            if ($replayed !== null) {
                return $this->accepted($request, $replayed, $timer, replayed: true);
            }

            $this->refuseDuplicate($posting, $data['applicant_email']);
            $timer->lap('duplicate');

            $files = $this->storeFiles($request, $timer);

            [$application, $created] = $this->persist($posting, $data, $files, $token);
            $timer->lap('db');
        } finally {
            $lock->release();
        }

        if (! $created) {
            return $this->accepted($request, $application, $timer, replayed: true);
        }

        // The response goes back first; the e-mails follow it, in this same
        // process (no queue worker is needed, or available, on this host).
        dispatch(new SendVolunteerApplicationNotifications($application->id))->afterResponse();
        $timer->lap('dispatch');

        return $this->accepted($request, $application, $timer);
    }

    /**
     * Identity and contact fields (name/email/phone) and the three consent
     * declarations are built in directly below — never touched by a
     * posting's configuration, per the organization's own floor: these are
     * never "casually optional". Every OTHER field's leading
     * required/nullable comes from $posting->resolvedFieldRequirements(),
     * so a posting's Application Form Settings and this endpoint's
     * validation can never drift apart — there is exactly one place
     * (JobPosting::CONFIGURABLE_APPLICATION_FIELDS) that decides what is
     * configurable at all.
     *
     * @return array<string, mixed>
     */
    private function rules(JobPosting $posting): array
    {
        $required = fn (string $key): array => [$posting->isFieldRequired($key) ? 'required' : 'nullable'];

        return [
            'applicant_name' => ['required', 'string', 'max:255'],
            'applicant_email' => ['required', 'email:rfc', 'max:255'],
            // Digits, spaces, dashes, brackets and an optional leading +,
            // with at least eight digits — permissive enough for any real
            // Bangladeshi or international number, strict enough to reject
            // a name or a sentence typed into the phone box.
            'applicant_phone' => ['required', 'string', 'max:30', 'regex:/^\+?[\d][\d\s\-()]{7,}$/'],
            'district' => [...$required('district'), 'string', 'max:120'],
            'current_location' => [...$required('current_location'), 'string', 'max:255'],
            'profession' => [...$required('profession'), 'string', 'max:255'],
            'experience' => [...$required('experience'), 'string', 'max:5000'],
            'skills' => [...$required('skills'), 'array', ...($posting->isFieldRequired('skills') ? ['min:1'] : [])],
            'skills.*' => [Rule::in(array_keys(JobApplication::SKILLS))],
            'other_skills' => ['nullable', 'string', 'max:1000'],
            'contribution' => [...$required('contribution'), 'string', 'max:5000'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
            'facebook_url' => ['nullable', 'url', 'max:255'],
            'portfolio_url' => ['nullable', 'url', 'max:255'],
            'availability' => [...$required('availability'), 'string', 'max:150'],
            'preferred_contact' => [...$required('preferred_contact'), Rule::in(array_keys(JobApplication::PREFERRED_CONTACTS))],
            'photo' => [...$required('photo'), 'file', 'max:5120'],
            'cv' => [...$required('cv'), 'file', 'max:5120'],
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
     * The browser's per-form token, or null when it sent none or something that
     * is not shaped like one. A bad token is ignored, never an error — the
     * submission itself is still perfectly valid.
     */
    private function submissionToken(Request $request): ?string
    {
        $token = $request->input('submission_token');

        return is_string($token) && preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token) === 1 ? $token : null;
    }

    /**
     * What the token means for THIS posting: the application it already produced
     * (a replay), or — when it belongs to another posting's application, which a
     * browser never does — nothing usable, so it is dropped rather than allowed to
     * trip the UNIQUE index or hand back someone else's application number.
     *
     * @return array{0: ?string, 1: ?JobApplication} [token still usable, application to replay]
     */
    private function resolveToken(?string $token, JobPosting $posting): array
    {
        if ($token === null) {
            return [null, null];
        }

        $owner = JobApplication::query()->where('submission_token', $token)->first();
        if ($owner === null) {
            return [$token, null];
        }

        return $owner->job_posting_id === $posting->id ? [$token, $owner] : [null, null];
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
    private function storeFiles(Request $request, PhaseTimer $timer): array
    {
        $stored = [];

        try {
            if ($request->hasFile('photo')) {
                $stored['photo_path'] = $this->photos->storePrivate($request->file('photo'), 'applications/photos');
            }
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['photo' => $e->getMessage()]);
        }
        $timer->lap('photo');

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
        $timer->lap('cv');

        return $stored;
    }

    /**
     * Writes the application. application_no is "max(id)+1", so two different
     * applicants arriving in the same instant can pick the same number — the
     * UNIQUE index refuses the second, and it simply tries again with a fresh
     * one. The same index on submission_token turns a token that raced in from
     * elsewhere into "return the application that already has it".
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $files
     * @return array{0: JobApplication, 1: bool}  the application, and whether this call created it
     */
    private function persist(JobPosting $posting, array $data, array $files, ?string $token): array
    {
        $attributes = [
            'job_posting_id' => $posting->id,
            'submission_token' => $token,
            'applicant_name' => $data['applicant_name'],
            'applicant_email' => $data['applicant_email'],
            'applicant_phone' => $data['applicant_phone'],
            // Every field below this line is configurable (JobPosting::CONFIGURABLE_APPLICATION_FIELDS):
            // when optional-and-omitted, validate() drops the key entirely rather than
            // handing back a null — direct $data[...] access would throw, not just store null.
            'district' => $data['district'] ?? null,
            'current_location' => $data['current_location'] ?? null,
            'profession' => $data['profession'] ?? null,
            'experience' => $data['experience'] ?? null,
            'skills' => $data['skills'] ?? null,
            'other_skills' => $data['other_skills'] ?? null,
            'contribution' => $data['contribution'] ?? null,
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
        ];

        for ($attempt = 1; ; $attempt++) {
            try {
                return [JobApplication::query()->create($attributes + ['application_no' => JobApplication::generateApplicationNo($attempt - 1)]), true];
            } catch (UniqueConstraintViolationException $e) {
                if ($token !== null && ($existing = JobApplication::query()->where('submission_token', $token)->first()) !== null) {
                    $this->discardFiles($files);

                    return [$existing, false];
                }
                if ($attempt >= 4) {
                    $this->discardFiles($files);
                    throw $e;
                }
            } catch (\Throwable $e) {
                // Anything else: do not leave the applicant's files orphaned on the private disk.
                $this->discardFiles($files);
                throw $e;
            }
        }
    }

    /** @param  array<string, string>  $files */
    private function discardFiles(array $files): void
    {
        if (isset($files['photo_path'])) {
            $this->photos->deletePrivate($files['photo_path']);
        }
        if (isset($files['cv_path'])) {
            $this->documents->delete($files['cv_path']);
        }
    }

    /**
     * 201 for a new application, 200 when this is the same attempt arriving again.
     * `Server-Timing` is attached only when the caller opts in with X-Pf-Timing: 1
     * (scripts/submit-qa.mjs does): durations only, never request data.
     */
    private function accepted(Request $request, JobApplication $application, PhaseTimer $timer, bool $replayed = false): JsonResponse
    {
        $response = response()->json(['data' => ['application_no' => $application->application_no]], $replayed ? 200 : 201);

        if ($request->header('X-Pf-Timing') === '1') {
            $response->header('Server-Timing', $timer->header());
        }

        return $response;
    }
}
