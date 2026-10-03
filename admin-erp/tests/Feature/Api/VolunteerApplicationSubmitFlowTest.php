<?php

namespace Tests\Feature\Api;

use App\Jobs\SendVolunteerApplicationNotifications;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Notifications\VolunteerApplicationReceivedNotification;
use App\Notifications\VolunteerApplicationSubmittedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * The volunteer submit path after the 2026-10-03 speed work, measured on production
 * first: ~3 s of a ~3.3 s submission was Laravel, almost all of it two SMTP sessions
 * sent inside the request. These tests pin what replaced that:
 *
 *  - the receipt and the operations notice leave AFTER the response, and a mail
 *    failure can never undo (or fail) an application that is already stored;
 *  - one submission attempt is one application however many times it arrives
 *    (a double click that beat the browser's guard, a retry after a lost response),
 *    and that is enforced by a UNIQUE index, not only by code;
 *  - two applicants racing for the same application number both get in;
 *  - the opt-in Server-Timing header names the phases and leaks nothing else.
 */
class VolunteerApplicationSubmitFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // the route is rate-limited per IP and every test shares 127.0.0.1
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
            'experience' => 'দুই বছর পাঠাগারে স্বেচ্ছাসেবী।',
            'skills' => ['fundraising'],
            'contribution' => 'তহবিল সংগ্রহে সময় দিতে চাই।',
            'accuracy_declaration' => '1',
            'privacy_consent' => '1',
            'contact_consent' => '1',
        ], $overrides);
    }

    private function url(JobPosting $posting): string
    {
        return "/api/v1/public/recruitment/{$posting->slug}/applications";
    }

    private const TOKEN = 'a1b2c3d4-e5f6-4789-8abc-def012345678';

    public function test_the_emails_are_dispatched_after_the_response_not_sent_inside_it(): void
    {
        Bus::fake();
        $posting = $this->posting();

        $this->postJson($this->url($posting), $this->payload())->assertCreated();

        Bus::assertDispatchedAfterResponse(SendVolunteerApplicationNotifications::class, 1);
        Bus::assertNotDispatched(SendVolunteerApplicationNotifications::class, fn () => false); // sanity: the assertion above is the real one
        Notification::assertNothingSent(); // nothing went out while the applicant was still waiting
    }

    public function test_after_the_response_both_emails_go_out_and_the_receipt_is_recorded(): void
    {
        $posting = $this->posting();

        $this->postJson($this->url($posting), $this->payload())->assertCreated();

        Notification::assertSentOnDemandTimes(VolunteerApplicationReceivedNotification::class, 1);
        Notification::assertSentOnDemand(VolunteerApplicationSubmittedNotification::class);
        $this->assertNotNull(JobApplication::query()->firstOrFail()->receipt_sent_at);
    }

    public function test_a_mail_failure_never_undoes_the_application_or_fails_the_response(): void
    {
        // Capture everything the app logs, the way the production log would see it.
        config(['logging.default' => 'capture', 'logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
        $posting = $this->posting();
        // The mail server is unreachable: every attempt to start a notification fails.
        // (Notification::route() builds a real on-demand notifiable whose notify() hands off to the
        // dispatcher the container holds — which is what is replaced here.)
        Notification::swap(new class
        {
            public function send(mixed $notifiables, mixed $notification, ?array $channels = null): never
            {
                throw new \RuntimeException('smtp: connection timed out');
            }

            public function sendNow(mixed $notifiables, mixed $notification, ?array $channels = null): never
            {
                throw new \RuntimeException('smtp: connection timed out');
            }
        });

        $this->postJson($this->url($posting), $this->payload())->assertCreated();

        $application = JobApplication::query()->firstOrFail();
        $this->assertSame('submitted', $application->status);
        $this->assertNull($application->receipt_sent_at, 'the receipt did not go out, and the record says so');

        /** @var TestHandler $handler */
        $handler = Log::channel('capture')->getLogger()->getHandlers()[0];
        // At ERROR level: the production log level would swallow a warning, which is how these used to vanish.
        $this->assertTrue($handler->hasError('Volunteer application receipt failed to send.'));
        $record = collect($handler->getRecords())->first(fn ($r) => $r->message === 'Volunteer application receipt failed to send.');
        $this->assertSame($application->application_no, $record->context['application_no']);
        $this->assertStringContainsString('timed out', $record->context['error']);
    }

    public function test_the_same_submission_token_creates_one_application_and_one_set_of_emails(): void
    {
        // Bus is faked so the count is of dispatches: in a test the app instance outlives each
        // request, and its terminating callbacks would re-run the first request's job on the second.
        Bus::fake();
        $posting = $this->posting();
        $payload = $this->payload(['submission_token' => self::TOKEN]);

        $first = $this->postJson($this->url($posting), $payload)->assertCreated();
        $second = $this->postJson($this->url($posting), $payload)->assertOk();

        $this->assertSame($first->json('data.application_no'), $second->json('data.application_no'));
        $this->assertDatabaseCount('job_applications', 1);
        $this->assertSame(self::TOKEN, JobApplication::query()->firstOrFail()->submission_token);
        Bus::assertDispatchedAfterResponseTimes(SendVolunteerApplicationNotifications::class, 1);
    }

    public function test_a_retry_with_corrected_data_after_a_validation_error_still_succeeds_with_the_same_token(): void
    {
        $posting = $this->posting();

        $this->postJson($this->url($posting), $this->payload(['submission_token' => self::TOKEN, 'applicant_phone' => 'abc']))
            ->assertStatus(422)->assertJsonValidationErrors(['applicant_phone']);
        $this->assertDatabaseCount('job_applications', 0);

        $this->postJson($this->url($posting), $this->payload(['submission_token' => self::TOKEN]))->assertCreated();
        $this->assertDatabaseCount('job_applications', 1);
    }

    public function test_the_database_itself_refuses_a_second_row_with_the_same_token(): void
    {
        $posting = $this->posting();
        $this->postJson($this->url($posting), $this->payload(['submission_token' => self::TOKEN]))->assertCreated();

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        JobApplication::query()->create([
            'application_no' => 'VOL-RAW-0001', 'job_posting_id' => $posting->id, 'applicant_name' => 'x',
            'applicant_email' => 'other@example.test', 'submission_token' => self::TOKEN,
        ]);
    }

    public function test_a_malformed_token_is_ignored_not_rejected(): void
    {
        $posting = $this->posting();

        $this->postJson($this->url($posting), $this->payload(['submission_token' => 'x']))->assertCreated();

        $this->assertNull(JobApplication::query()->firstOrFail()->submission_token);
    }

    public function test_a_token_that_belongs_to_another_postings_application_is_dropped_not_replayed(): void
    {
        $one = $this->posting();
        $two = $this->posting();
        $this->postJson($this->url($one), $this->payload(['submission_token' => self::TOKEN]))->assertCreated();

        $this->postJson($this->url($two), $this->payload(['submission_token' => self::TOKEN, 'applicant_email' => 'second@example.test']))->assertCreated();

        $this->assertDatabaseCount('job_applications', 2);
        $this->assertSame(1, JobApplication::query()->where('submission_token', self::TOKEN)->count());
    }

    public function test_two_different_tokens_from_the_same_person_still_cannot_make_two_live_applications(): void
    {
        $posting = $this->posting();

        $this->postJson($this->url($posting), $this->payload(['submission_token' => self::TOKEN]))->assertCreated();
        $this->postJson($this->url($posting), $this->payload(['submission_token' => 'ffffffff-0000-4000-8000-000000000001']))
            ->assertStatus(422)->assertJsonValidationErrors(['applicant_email']);

        $this->assertDatabaseCount('job_applications', 1);
    }

    public function test_losing_a_race_for_the_application_number_retries_instead_of_failing(): void
    {
        $posting = $this->posting();
        // Another applicant has just taken the number max(id)+1 would hand out next.
        $earlier = JobApplication::query()->create([
            'application_no' => 'VOL-PLACEHOLDER', 'job_posting_id' => $posting->id,
            'applicant_name' => 'আগের আবেদনকারী', 'applicant_email' => 'earlier@example.test',
        ]);
        $taken = JobApplication::generateApplicationNo();
        $earlier->update(['application_no' => $taken]);

        $response = $this->postJson($this->url($posting), $this->payload())->assertCreated();

        // The refused insert, then the next number along (PHP's string increment carries 0009 -> 0010).
        $nextAlong = $taken;
        $nextAlong++;
        $this->assertSame($nextAlong, $response->json('data.application_no'));
        $this->assertNotSame($taken, $response->json('data.application_no'));
        $this->assertDatabaseCount('job_applications', 2);
    }

    public function test_files_are_not_left_behind_when_the_application_cannot_be_written(): void
    {
        Storage::fake('uploads_private');
        $posting = $this->posting();
        // The write fails for a reason that is not a collision (disk full, connection lost…).
        JobApplication::creating(fn () => throw new \RuntimeException('database write failed'));

        try {
            $this->postJson($this->url($posting), $this->payload([
                'photo' => UploadedFile::fake()->image('me.jpg', 300, 300),
                'cv' => UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"),
            ]))->assertStatus(500);
        } finally {
            JobApplication::flushEventListeners();
        }

        $this->assertDatabaseCount('job_applications', 0);
        $this->assertSame([], Storage::disk('uploads_private')->allFiles(), 'the stored photo and CV are removed, not orphaned');
    }

    public function test_server_timing_is_attached_only_when_asked_and_names_the_phases(): void
    {
        $posting = $this->posting();

        $plain = $this->postJson($this->url($posting), $this->payload())->assertCreated();
        $this->assertNull($plain->headers->get('Server-Timing'));

        $timed = $this->withHeader('X-Pf-Timing', '1')->postJson($this->url($posting), $this->payload(['applicant_email' => 'timed@example.test']))->assertCreated();
        $header = (string) $timed->headers->get('Server-Timing');
        foreach (['boot', 'lookup', 'validate', 'lock', 'duplicate', 'photo', 'cv', 'db', 'dispatch', 'total'] as $phase) {
            $this->assertMatchesRegularExpression("/(^|, ){$phase};dur=\\d+(\\.\\d+)?/", $header, "missing phase {$phase} in: {$header}");
        }
        // Durations and phase names only.
        $this->assertDoesNotMatchRegularExpression('/example\.test|নাদিয়া|VOL-/u', $header);
    }
}
