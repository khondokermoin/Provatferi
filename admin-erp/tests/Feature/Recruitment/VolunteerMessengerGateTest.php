<?php

namespace Tests\Feature\Recruitment;

use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobPostingSlug;
use App\Models\Setting;
use App\Support\Recruitment\ApplicationSuccessProof;
use App\Support\Recruitment\CentralMessengerGroup;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The server half of "the Messenger group is offered only after a verified application"
 * (docs/VOLUNTEER_MESSENGER_GROUP.md): a proof is issued for an ACCEPTED application and for nothing else, and the
 * verification endpoint releases the invite only for a proof that verifies. The tokens here are made up: the real invite
 * is configuration, never source.
 */
class VolunteerMessengerGateTest extends TestCase
{
    use RefreshDatabase;

    private const INVITE_CODE = 'TestInvite-A1b2C3';

    private const CANONICAL = 'https://m.me/j/TestInvite-A1b2C3/';

    protected function setUp(): void
    {
        parent::setUp();
        // The application route is rate-limited per IP (5 a minute) and every test shares 127.0.0.1.
        Cache::flush();
        Notification::fake();
    }

    private function posting(array $overrides = []): JobPosting
    {
        return JobPosting::query()->create(array_merge([
            'title' => 'প্রভাতফেরীর স্বেচ্ছাসেবী টিমে যুক্ত হওয়ার আহ্বান',
            'slug' => 'volunteer-call-'.uniqid(),
            'description' => 'বিবরণ',
            'employment_type' => 'volunteer',
            'application_mode' => 'rolling',
            'accepts_applications' => true,
            'status' => 'open',
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'applicant_name' => 'নাদিয়া ইসলাম',
            'applicant_email' => 'nadia@example.test',
            'applicant_phone' => '+8801711223344',
            'district' => 'কুমিল্লা',
            'current_location' => 'চান্দিনা, কুমিল্লা',
            'profession' => 'শিক্ষার্থী',
            'experience' => 'দুই বছর ধরে স্থানীয় পাঠাগারে স্বেচ্ছাসেবী কাজ করছি।',
            'skills' => ['fundraising'],
            'contribution' => 'নিয়মিত সময় দিতে চাই।',
            'accuracy_declaration' => '1',
            'privacy_consent' => '1',
            'contact_consent' => '1',
        ], $overrides);
    }

    private function apply(JobPosting $posting, array $overrides = []): TestResponse
    {
        return $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload($overrides));
    }

    /** @return array{0: JobPosting, 1: JobApplication, 2: string} */
    private function applied(array $postingOverrides = [], array $applyOverrides = []): array
    {
        $posting = $this->posting($postingOverrides);
        $proof = (string) $this->apply($posting, $applyOverrides)->assertCreated()->json('data.success_proof');

        return [$posting, JobApplication::query()->latest('id')->firstOrFail(), $proof];
    }

    private function verify(string $slug, mixed $proof): TestResponse
    {
        return $this->postJson("/api/v1/public/recruitment/{$slug}/application-success", ['proof' => $proof]);
    }

    private function configure(bool $enabled, ?string $url = self::CANONICAL): void
    {
        Setting::query()->updateOrCreate(['key' => CentralMessengerGroup::KEY_ENABLED], ['value' => $enabled ? 'true' : 'false', 'value_type' => 'boolean', 'group_name' => 'recruitment', 'is_public' => false]);
        Setting::query()->updateOrCreate(['key' => CentralMessengerGroup::KEY_URL], ['value' => $url, 'value_type' => 'string', 'group_name' => 'recruitment', 'is_public' => false]);
    }

    /** A proof encrypted under the real key but with a payload the issuer would never make (the derivation is the contract under test). */
    private function craft(array $payload): string
    {
        $appKey = (string) config('app.key');
        $raw = str_starts_with($appKey, 'base64:') ? (string) base64_decode(substr($appKey, 7), true) : $appKey;

        return (new Encrypter(hash_hmac('sha256', 'provatferi-volunteer-application-success-v1', $raw, true), 'AES-256-GCM'))->encryptString(json_encode($payload));
    }

    /** @return array<string, mixed> */
    private function validPayload(JobApplication $application, array $overrides = []): array
    {
        return array_merge(['v' => 1, 'k' => 'volunteer-application-success', 'a' => $application->id, 'p' => $application->job_posting_id, 'x' => now()->getTimestamp() + 600, 'n' => 'abcdefgh'], $overrides);
    }

    // --- Issuing -----------------------------------------------------------------------------------------------------

    public function test_an_accepted_application_returns_an_opaque_proof_and_the_response_cannot_be_cached(): void
    {
        $posting = $this->posting();

        $response = $this->apply($posting)->assertCreated();
        $application = JobApplication::query()->firstOrFail();
        $proof = (string) $response->json('data.success_proof');

        $this->assertGreaterThan(100, strlen($proof));
        $response->assertJsonPath('data.success_proof_expires_in', 1800);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));

        // Nothing readable travels in it: it is an authenticated-encryption envelope and nothing else.
        $envelope = json_decode((string) base64_decode($proof, true), true);
        $this->assertSame(['iv', 'mac', 'tag', 'value'], collect(array_keys((array) $envelope))->sort()->values()->all());
        $decoded = (string) base64_decode($proof, true);
        foreach ([$application->application_no, 'nadia@example.test', '+8801711223344', 'নাদিয়া', 'volunteer-application-success', (string) $posting->id.',', 'success'] as $readable) {
            $this->assertStringNotContainsString($readable, $decoded, "\"{$readable}\" is readable inside the proof");
            $this->assertStringNotContainsString($readable, $proof);
        }
    }

    public function test_each_proof_is_different_even_for_the_same_application(): void
    {
        [, $application, $first] = $this->applied();

        $this->assertNotSame($first, ApplicationSuccessProof::issue($application)['proof']);
        $this->assertNotSame(ApplicationSuccessProof::issue($application)['proof'], ApplicationSuccessProof::issue($application)['proof']);
    }

    /** @return array<string, array{0: string}> */
    public static function refusals(): array
    {
        return [
            'validation failure' => ['invalid'],
            'a refused duplicate' => ['duplicate'],
            'the honeypot' => ['honeypot'],
            'a rejected upload' => ['disguised-cv'],
            'a closed posting' => ['closed'],
            'an unknown posting' => ['unknown'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_submission_that_is_not_accepted_gets_no_proof(string $case): void
    {
        Storage::fake('uploads_private');
        $posting = $this->posting();
        $slug = $posting->slug;

        $response = match ($case) {
            'invalid' => $this->apply($posting, ['applicant_email' => 'not-an-email']),
            'duplicate' => (function () use ($posting) {
                $this->apply($posting)->assertCreated();

                return $this->apply($posting, ['applicant_email' => 'NADIA@example.test']);
            })(),
            'honeypot' => $this->apply($posting, ['website' => 'http://spam.example']),
            'disguised-cv' => $this->apply($posting, ['cv' => UploadedFile::fake()->createWithContent('cv.pdf', "<?php system('id'); ?>")]),
            'closed' => (function () use ($posting) {
                $posting->update(['status' => 'closed']);

                return $this->apply($posting);
            })(),
            'unknown' => $this->postJson('/api/v1/public/recruitment/no-such-posting/applications', $this->payload()),
        };

        $this->assertContains($response->getStatusCode(), [404, 422], "{$case} must be refused ({$slug})");
        $this->assertStringNotContainsString('success_proof', (string) $response->getContent());
    }

    public function test_the_same_attempt_arriving_again_gets_a_proof_for_the_same_application(): void
    {
        $posting = $this->posting();
        $token = 'attempt-token-'.bin2hex(random_bytes(8));

        $first = $this->apply($posting, ['submission_token' => $token])->assertCreated();
        $second = $this->apply($posting, ['submission_token' => $token])->assertOk();

        $this->assertSame(1, JobApplication::query()->count());
        $this->assertNotSame($first->json('data.success_proof'), $second->json('data.success_proof'));
        $this->configure(true);
        foreach ([$first, $second] as $response) {
            $this->verify($posting->slug, $response->json('data.success_proof'))->assertJsonPath('data.verified', true);
        }
    }

    // --- Verifying ---------------------------------------------------------------------------------------------------

    public function test_a_valid_proof_releases_the_invite_in_canonical_form(): void
    {
        [$posting, , $proof] = $this->applied();
        $this->configure(true, 'https://www.messenger.com/j/'.self::INVITE_CODE.'/?send_source=gc%3Acopy_invite_link_c');

        $this->verify($posting->slug, $proof)->assertOk()
            ->assertExactJson(['data' => ['verified' => true, 'messenger' => ['url' => self::CANONICAL]]]);
    }

    public function test_a_verified_visitor_still_gets_no_link_when_the_feature_is_off_or_has_no_invite(): void
    {
        [$posting, , $proof] = $this->applied();

        foreach ([[false, self::CANONICAL], [true, null], [false, null], [true, 'javascript:alert(1)']] as [$enabled, $url]) {
            $this->configure($enabled, $url);
            $this->verify($posting->slug, $proof)->assertOk()->assertExactJson(['data' => ['verified' => true, 'messenger' => null]]);
        }
    }

    public function test_nothing_is_configured_by_default(): void
    {
        [$posting, , $proof] = $this->applied();

        $this->verify($posting->slug, $proof)->assertExactJson(['data' => ['verified' => true, 'messenger' => null]]);
    }

    public function test_changing_or_switching_off_the_invite_takes_effect_on_the_next_call(): void
    {
        [$posting, , $proof] = $this->applied();

        $this->configure(true);
        $this->verify($posting->slug, $proof)->assertJsonPath('data.messenger.url', self::CANONICAL);

        $this->configure(true, 'https://m.me/j/ReplacementTok3n/');
        $this->verify($posting->slug, $proof)->assertJsonPath('data.messenger.url', 'https://m.me/j/ReplacementTok3n/');

        $this->configure(false, 'https://m.me/j/ReplacementTok3n/');
        $this->verify($posting->slug, $proof)->assertJsonPath('data.messenger', null)->assertJsonPath('data.verified', true);
    }

    /** @return array<string, array{0: mixed}> */
    public static function nonProofs(): array
    {
        return [
            'no proof at all' => [null],
            'an empty string' => [''],
            'a short string' => ['x'],
            'a number' => [12345],
            'an array' => [['a' => 'b']],
            'a boolean' => [true],
            'an overlong string' => [str_repeat('A', 3000)],
            'base64 that is not an envelope' => [base64_encode('{"iv":"x","value":"y","mac":"z"}')],
            'markup' => ['<script>alert(1)</script>'],
        ];
    }

    #[DataProvider('nonProofs')]
    public function test_a_missing_or_nonsense_proof_releases_nothing(mixed $proof): void
    {
        [$posting] = $this->applied();
        $this->configure(true);

        $this->verify($posting->slug, $proof)->assertOk()->assertExactJson(['data' => ['verified' => false, 'messenger' => null]]);
    }

    public function test_a_request_with_no_body_at_all_releases_nothing(): void
    {
        [$posting] = $this->applied();
        $this->configure(true);

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/application-success")->assertOk()
            ->assertExactJson(['data' => ['verified' => false, 'messenger' => null]]);
    }

    public function test_a_proof_with_any_character_changed_releases_nothing(): void
    {
        [$posting, , $proof] = $this->applied();
        $this->configure(true);
        $this->verify($posting->slug, $proof)->assertJsonPath('data.verified', true);

        foreach ([0, 1, 7, intdiv(strlen($proof), 3), intdiv(strlen($proof), 2), strlen($proof) - 8, strlen($proof) - 2] as $position) {
            $altered = $proof;
            $altered[$position] = $altered[$position] === 'A' ? 'B' : 'A';
            $this->verify($posting->slug, $altered)->assertJsonPath('data.verified', false)->assertJsonPath('data.messenger', null);
        }
        foreach ([substr($proof, 0, -4), substr($proof, 4), $proof.'AAAA', strrev($proof), strtolower($proof) === $proof ? strtoupper($proof) : strtolower($proof)] as $mangled) {
            $this->verify($posting->slug, $mangled)->assertJsonPath('data.verified', false);
        }
    }

    public function test_a_proof_made_for_another_posting_releases_nothing_on_this_one(): void
    {
        [$first, , $proof] = $this->applied();
        $other = $this->posting();
        $this->configure(true);

        $this->verify($first->slug, $proof)->assertJsonPath('data.verified', true);
        $this->verify($other->slug, $proof)->assertJsonPath('data.verified', false)->assertJsonPath('data.messenger', null);
    }

    public function test_an_unknown_posting_in_the_address_releases_nothing(): void
    {
        [, , $proof] = $this->applied();
        $this->configure(true);

        $this->verify('no-such-posting', $proof)->assertOk()->assertExactJson(['data' => ['verified' => false, 'messenger' => null]]);
    }

    public function test_a_blob_that_the_real_issuer_would_never_make_is_refused_even_under_the_real_key(): void
    {
        [$posting, $application] = $this->applied();
        $this->configure(true);
        $good = $this->validPayload($application);
        $this->verify($posting->slug, $this->craft($good))->assertJsonPath('data.verified', true); // the helper is faithful

        $bad = [
            'another purpose' => ['k' => 'mfa-secret'],
            'another version' => ['v' => 2],
            'no purpose' => ['k' => null],
            'ids as strings' => ['a' => (string) $application->id],
            'a float expiry' => ['x' => now()->getTimestamp() + 600.5],
            'an expired expiry' => ['x' => now()->getTimestamp() - 1],
            'a missing expiry' => ['x' => null],
            'another posting' => ['p' => $application->job_posting_id + 999],
            'another application' => ['a' => $application->id + 999],
        ];
        foreach ($bad as $name => $override) {
            $payload = array_merge($good, $override);
            if (($override['x'] ?? 1) === null || ($override['k'] ?? 1) === null) {
                $payload = array_filter($payload, fn ($value) => $value !== null);
            }
            $this->verify($posting->slug, $this->craft($payload))->assertJsonPath('data.verified', false, "accepted: {$name}");
        }
    }

    public function test_something_encrypted_for_another_purpose_under_the_application_key_is_refused(): void
    {
        [$posting, $application] = $this->applied();
        $this->configure(true);

        // The same JSON, encrypted the way the rest of the app encrypts things (Crypt, the plain application key).
        $this->verify($posting->slug, Crypt::encryptString(json_encode($this->validPayload($application))))->assertJsonPath('data.verified', false);
        $this->verify($posting->slug, Crypt::encrypt($this->validPayload($application)))->assertJsonPath('data.verified', false);
    }

    public function test_a_proof_expires_exactly_when_it_says_and_works_until_then(): void
    {
        [$posting, , $proof] = $this->applied();
        $this->configure(true);

        $this->travel(ApplicationSuccessProof::TTL_SECONDS - 2)->seconds();
        $this->verify($posting->slug, $proof)->assertJsonPath('data.verified', true);

        $this->travel(2)->seconds();
        $this->verify($posting->slug, $proof)->assertOk()->assertExactJson(['data' => ['verified' => false, 'messenger' => null]]);

        $this->travel(3)->hours();
        $this->verify($posting->slug, $proof)->assertJsonPath('data.verified', false);
    }

    public function test_refreshing_the_confirmation_works_again_and_again_inside_the_window(): void
    {
        [$posting, , $proof] = $this->applied();
        $this->configure(true);
        $start = now();

        foreach ([0, 1, 60, 600, 1500, 1799] as $secondsLater) {
            $this->travelTo($start->copy()->addSeconds($secondsLater));
            $this->verify($posting->slug, $proof)->assertJsonPath('data.verified', true)->assertJsonPath('data.messenger.url', self::CANONICAL);
        }
    }

    public function test_the_window_is_thirty_minutes(): void
    {
        $this->assertSame(1800, ApplicationSuccessProof::TTL_SECONDS);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function statuses(): array
    {
        return [
            'submitted' => ['submitted', true],
            'under review' => ['under_review', true],
            'contacted' => ['contacted', true],
            'shortlisted' => ['shortlisted', true],
            'accepted' => ['accepted', true],
            'not selected' => ['not_selected', false],
            'withdrawn' => ['withdrawn', false],
            'archived' => ['archived', false],
        ];
    }

    #[DataProvider('statuses')]
    public function test_an_admin_who_closes_the_application_out_revokes_the_proof(string $status, bool $stillWorks): void
    {
        [$posting, $application, $proof] = $this->applied();
        $this->configure(true);

        $application->update(['status' => $status]);

        $this->verify($posting->slug, $proof)->assertJsonPath('data.verified', $stillWorks);
    }

    public function test_a_proof_stops_working_when_its_application_is_gone(): void
    {
        [$posting, $application, $proof] = $this->applied();
        $this->configure(true);

        $application->delete();

        $this->verify($posting->slug, $proof)->assertJsonPath('data.verified', false);
    }

    public function test_the_address_may_be_the_postings_old_slug_or_a_posting_that_has_since_closed(): void
    {
        [$posting, , $proof] = $this->applied();
        $this->configure(true);
        $oldSlug = $posting->slug;

        JobPostingSlug::query()->create(['job_posting_id' => $posting->id, 'slug' => $oldSlug]);
        $posting->update(['slug' => 'renamed-'.uniqid()]);
        $this->verify($oldSlug, $proof)->assertJsonPath('data.verified', true);
        $this->verify($posting->fresh()->slug, $proof)->assertJsonPath('data.verified', true);

        $posting->update(['status' => 'closed']);
        $this->verify($posting->fresh()->slug, $proof)->assertJsonPath('data.verified', true);
    }

    public function test_the_proof_travels_in_the_body_never_in_the_address(): void
    {
        [$posting, , $proof] = $this->applied();
        $this->configure(true);

        $this->getJson("/api/v1/public/recruitment/{$posting->slug}/application-success")->assertStatus(405);
        $this->getJson("/api/v1/public/recruitment/{$posting->slug}/application-success?proof=".urlencode($proof))->assertStatus(405);
        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/application-success?proof=".urlencode($proof))
            ->assertExactJson(['data' => ['verified' => false, 'messenger' => null]]);
    }

    public function test_ids_and_flags_in_the_body_cannot_steer_the_answer(): void
    {
        [$posting, $application] = $this->applied();
        $this->configure(true);

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/application-success", [
            'application_id' => $application->id, 'application_no' => $application->application_no, 'posting_id' => $posting->id,
            'verified' => true, 'proof' => null, 'email' => $application->applicant_email,
        ])->assertExactJson(['data' => ['verified' => false, 'messenger' => null]]);
    }

    public function test_every_way_of_failing_looks_the_same_from_outside(): void
    {
        [$posting, $application, $proof] = $this->applied();
        $other = $this->posting();
        $this->configure(true);

        $bodies = [
            (string) $this->verify($posting->slug, null)->getContent(),
            (string) $this->verify($posting->slug, 'garbage')->getContent(),
            (string) $this->verify($other->slug, $proof)->getContent(),
            (string) $this->verify('unknown-slug', $proof)->getContent(),
            (string) $this->verify($posting->slug, $this->craft($this->validPayload($application, ['x' => 1])))->getContent(),
        ];
        $this->assertCount(1, array_unique($bodies), 'the reason a proof was refused must not be visible');
    }

    public function test_the_answer_carries_nothing_about_the_application_and_cannot_be_cached(): void
    {
        [$posting, $application, $proof] = $this->applied();
        $this->configure(true);

        $response = $this->verify($posting->slug, $proof)->assertOk();
        $body = (string) $response->getContent();

        foreach ([$application->application_no, 'nadia@example.test', '+8801711223344', 'নাদিয়া', 'কুমিল্লা', 'success_proof'] as $private) {
            $this->assertStringNotContainsString($private, $body);
        }
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(['verified', 'messenger'], array_keys($response->json('data')));
    }

    public function test_the_proof_never_reaches_the_log(): void
    {
        $lines = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$lines) {
            $lines[] = $e->message.' '.json_encode($e->context);
        });

        [$posting, , $proof] = $this->applied();
        $this->configure(true, 'https://m.me/j/LogProbeTok3n9/');
        $this->verify($posting->slug, $proof)->assertJsonPath('data.verified', true);
        $this->verify($posting->slug, 'garbage')->assertJsonPath('data.verified', false);

        foreach ($lines as $line) {
            $this->assertStringNotContainsString($proof, $line);
            $this->assertStringNotContainsString('LogProbeTok3n9', $line);
        }
    }

    public function test_the_verification_route_is_a_throttled_public_post(): void
    {
        $route = Route::getRoutes()->getByName('api.public.recruitment.success');

        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('throttle:600,1,application-success:', $route->gatherMiddleware());
        $this->assertNotContains('auth:sanctum', $route->gatherMiddleware(), 'it is public: the proof is the credential');
    }

    /**
     * Found in a real browser run, not by reading: the numeric `throttle:N,M` middleware is keyed by domain + IP, not by route,
     * so a verification call on the default bucket counted against the application form's 5 a minute. Every visitor comes from
     * the public site's one address, so a few confirmation page loads (an applicant refreshing) turned the next applicant away
     * with a 429 and a generic "could not submit" message.
     */
    public function test_confirmation_page_loads_never_use_up_the_application_forms_allowance(): void
    {
        [$posting, , $proof] = $this->applied();
        $this->configure(true);

        for ($i = 0; $i < 12; $i++) {
            $this->verify($posting->slug, $proof)->assertOk()->assertJsonPath('data.verified', true);
        }

        // Twelve verifications later, the next applicant is still accepted...
        $this->apply($posting, ['applicant_email' => 'second.applicant@example.test'])->assertCreated();
        // ...and the form's own limit is untouched: five in a minute are accepted, the sixth is not.
        foreach (['a', 'b', 'c'] as $letter) {
            $this->apply($posting, ['applicant_email' => "more.{$letter}@example.test"])->assertCreated();
        }
        $this->apply($posting, ['applicant_email' => 'one.too.many@example.test'])->assertStatus(429);
    }

    public function test_no_other_public_response_carries_the_invite_or_a_proof(): void
    {
        [$posting, , $proof] = $this->applied();
        $this->configure(true);

        $bodies = [
            (string) $this->getJson('/api/v1/settings')->getContent(),
            (string) $this->getJson('/api/v1/job-postings')->getContent(),
            (string) $this->getJson("/api/v1/job-postings/{$posting->slug}")->getContent(),
            (string) $this->getJson('/api/v1/public/notices')->getContent(),
        ];
        foreach ($bodies as $body) {
            $this->assertStringNotContainsString(self::INVITE_CODE, $body);
            $this->assertStringNotContainsString('m.me', $body);
            $this->assertStringNotContainsString($proof, $body);
        }
    }
}
