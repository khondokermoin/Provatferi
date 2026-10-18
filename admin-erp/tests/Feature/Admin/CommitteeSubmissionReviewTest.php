<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\CommitteeSubmissionController;
use App\Models\ApprovalHistory;
use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\CommitteePosition;
use App\Models\CommitteeSubmission;
use App\Models\OrganizationalUnit;
use App\Notifications\CommitteeCorrectionRequestedNotification;
use App\Notifications\CommitteeSubmissionStatusChangedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Committee-submission review (docs/COMMITTEE_SUBMISSION_REVIEW.md): the applicant's photo in the admin review page and the
 * approval of an application. Written BEFORE the fix (the production report was "Admin cannot approve — 422 — and the photo is
 * not visible"): the tests that exercise the two defects failed on the old code and are the local reproduction.
 *
 *   A approve a valid application            B a state that no longer allows the decision → a message, never a 422 page
 *   C an expected business failure           D repeating a decision changes nothing (one seat, one audit row, one copy)
 *   E an old application, today's rules      F a reviewer sees the photo           G anonymous: denied
 *   H an unauthorised account: denied        I no photo / lost photo: a placeholder
 *   J malformed, traversal and foreign paths K a failure part-way rolls everything back
 */
class CommitteeSubmissionReviewTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads_private');
        Storage::fake('public');
        Notification::fake();
    }

    protected function tearDown(): void
    {
        CommitteeMember::flushEventListeners();
        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------------ fixtures

    private function committee(string $status = 'upcoming'): Committee
    {
        $unit = OrganizationalUnit::query()->create(['name' => 'কেন্দ্রীয়', 'slug' => 'central-'.uniqid(), 'unit_type' => 'central', 'status' => 'active']);

        return Committee::query()->create(['organization_unit_id' => $unit->id, 'name' => 'কমিটি '.uniqid(), 'status' => $status]);
    }

    private function position(Committee $committee, bool $allowDuplicates = true, string $status = 'active'): CommitteePosition
    {
        return CommitteePosition::query()->create([
            'committee_id' => $committee->id, 'name' => 'সাধারণ সম্পাদক', 'slug' => 'gs-'.uniqid(),
            'display_order' => 0, 'allow_duplicates' => $allowDuplicates, 'status' => $status,
        ]);
    }

    /** A real PNG (the review page and the route are checked against its actual bytes). */
    private function png(int $size = 64): string
    {
        $image = imagecreatetruecolor($size, $size);
        imagefill($image, 0, 0, imagecolorallocate($image, 172, 53, 10));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $attributes */
    private function submission(Committee $committee, CommitteePosition $position, string $status = 'pending', array $attributes = []): CommitteeSubmission
    {
        return CommitteeSubmission::query()->create($attributes + [
            'committee_id' => $committee->id, 'committee_position_id' => $position->id,
            'full_name' => 'রফিক উদ্দিন', 'email' => 'rafiq-'.uniqid().'@example.com', 'phone' => '01800000000',
            'provatferi_comment' => 'প্রভাতফেরীর সাথে দীর্ঘদিন যুক্ত আছি।',
            'publishing_consent' => true, 'accuracy_declaration' => true,
            'status' => $status, 'submitted_at' => now(),
        ]);
    }

    /** A submission whose original photo really sits on the private disk, where the public form puts it. */
    private function withPhoto(Committee $committee, CommitteePosition $position, string $status = 'pending'): CommitteeSubmission
    {
        $path = 'committee-submissions/'.Str::uuid().'.png';
        Storage::disk('uploads_private')->put($path, $this->png());

        return $this->submission($committee, $position, $status, ['photo_path' => $path]);
    }

    private function approveUrl(Committee $c, CommitteeSubmission $s): string
    {
        return route('admin.committees.submissions.approve', [$c, $s]);
    }

    private function showUrl(Committee $c, CommitteeSubmission $s): string
    {
        return route('admin.committees.submissions.show', [$c, $s]);
    }

    private function photoUrl(Committee $c, CommitteeSubmission $s): string
    {
        return route('admin.committees.submissions.photo', [$c, $s]);
    }

    private function seats(CommitteeSubmission $s): int
    {
        return CommitteeMember::query()->where('committee_submission_id', $s->id)->count();
    }

    private function trail(CommitteeSubmission $s, ?string $action = null): int
    {
        return ApprovalHistory::query()->where('subject_type', CommitteeSubmission::class)->where('subject_id', $s->id)
            ->when($action, fn ($q) => $q->where('action', $action))->count();
    }

    /** @return list<string> */
    private function publicCopies(): array
    {
        return Storage::disk('public')->allFiles('committee');
    }

    private function english(\App\Models\User $user): \App\Models\User
    {
        $user->forceFill(['ui_locale' => 'en'])->save();

        return $user;
    }

    // ------------------------------------------------------------------------------------------------ A. approve a valid application

    public function test_a_pending_application_is_approved_with_its_public_photo_a_seat_and_one_audit_row(): void
    {
        $admin = $this->english($this->superAdmin());
        $committee = $this->committee();
        $submission = $this->withPhoto($committee, $this->position($committee));

        $this->actingAs($admin)->patch($this->approveUrl($committee, $submission))
            ->assertRedirect($this->showUrl($committee, $submission))->assertSessionHas('success');

        $fresh = $submission->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($admin->id, $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at);
        $this->assertNotNull($fresh->photo_approved_path);
        $this->assertSame($this->png(), Storage::disk('public')->get($fresh->photo_approved_path), 'the public copy is the submitted picture');
        $this->assertCount(1, $this->publicCopies());
        $this->assertSame(1, $this->seats($submission));
        $this->assertSame(1, $this->trail($submission, 'approved'));
        Notification::assertSentOnDemand(CommitteeSubmissionStatusChangedNotification::class);
    }

    public function test_an_application_without_any_photo_is_approved_too(): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee();
        $submission = $this->submission($committee, $this->position($committee));

        $this->actingAs($admin)->patch($this->approveUrl($committee, $submission))->assertRedirect()->assertSessionHas('success');

        $this->assertSame('approved', $submission->fresh()->status);
        $this->assertNull($submission->fresh()->photo_approved_path);
        $this->assertSame([], $this->publicCopies());
        $this->assertSame(1, $this->seats($submission));
    }

    // ------------------------------------------------------------------------------------------------ B. a state that no longer allows the decision

    public function test_approving_a_rejected_application_gives_a_message_on_the_review_page_not_a_422(): void
    {
        $admin = $this->english($this->superAdmin());
        $committee = $this->committee();
        $submission = $this->submission($committee, $this->position($committee), 'rejected');

        $response = $this->actingAs($admin)->patch($this->approveUrl($committee, $submission));

        $response->assertRedirect($this->showUrl($committee, $submission))->assertSessionHas('error');
        $this->assertStringContainsString('Rejected', (string) session('error'), 'the message names the current state');
        $this->assertSame('rejected', $submission->fresh()->status);
        $this->assertSame(0, $this->seats($submission));
        $this->assertSame(0, $this->trail($submission));
        Notification::assertNothingSent();

        $this->followingRedirects()->actingAs($admin)->patch($this->approveUrl($committee, $submission))
            ->assertOk()->assertSee('can no longer be approved', false);
    }

    public function test_an_unpublished_application_cannot_be_approved_either_and_says_so(): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee();
        $submission = $this->submission($committee, $this->position($committee), 'unpublished');

        $this->actingAs($admin)->patch($this->approveUrl($committee, $submission))
            ->assertRedirect($this->showUrl($committee, $submission))->assertSessionHas('error');

        $this->assertSame('unpublished', $submission->fresh()->status);
        $this->assertSame(0, $this->seats($submission));
    }

    public function test_rejecting_and_requesting_a_correction_have_the_same_manners(): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee();
        $position = $this->position($committee);

        $approved = $this->submission($committee, $position, 'approved');
        $this->actingAs($admin)->patch(route('admin.committees.submissions.reject', [$committee, $approved]), ['admin_note' => 'ভুল'])
            ->assertRedirect($this->showUrl($committee, $approved))->assertSessionHas('error');
        $this->assertSame('approved', $approved->fresh()->status);

        $rejected = $this->submission($committee, $position, 'rejected');
        $this->actingAs($admin)->patch(route('admin.committees.submissions.request-correction', [$committee, $rejected]), ['admin_note' => 'ভুল'])
            ->assertRedirect($this->showUrl($committee, $rejected))->assertSessionHas('error');
        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertSame(0, $this->trail($rejected));

        // Rejecting twice: the second is told it already happened, and nothing is written again.
        $pending = $this->submission($committee, $position);
        $url = route('admin.committees.submissions.reject', [$committee, $pending]);
        $this->actingAs($admin)->patch($url, ['admin_note' => 'অসম্পূর্ণ'])->assertRedirect()->assertSessionHas('success');
        $this->actingAs($admin)->patch($url, ['admin_note' => 'অসম্পূর্ণ'])->assertRedirect()->assertSessionHas('status')->assertSessionMissing('error');
        $this->assertSame(1, $this->trail($pending, 'rejected'));
        Notification::assertSentOnDemandTimes(CommitteeSubmissionStatusChangedNotification::class, 1);
    }

    public function test_a_still_valid_rejection_without_a_reason_is_still_a_field_error(): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee();
        $submission = $this->submission($committee, $this->position($committee));

        $this->actingAs($admin)->patch(route('admin.committees.submissions.reject', [$committee, $submission]), [])
            ->assertSessionHasErrors('admin_note');
        $this->assertSame('pending', $submission->fresh()->status);
    }

    // ------------------------------------------------------------------------------------------------ C. expected business failures

    public function test_a_photo_file_that_is_gone_blocks_the_approval_with_a_message_and_changes_nothing(): void
    {
        $admin = $this->english($this->superAdmin());
        $committee = $this->committee();
        $submission = $this->submission($committee, $this->position($committee), 'pending', ['photo_path' => 'committee-submissions/'.Str::uuid().'.png']);

        $this->actingAs($admin)->patch($this->approveUrl($committee, $submission))
            ->assertRedirect($this->showUrl($committee, $submission))->assertSessionHas('error');

        $this->assertStringContainsString('photo', (string) session('error'));
        $fresh = $submission->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->photo_approved_path);
        $this->assertNull($fresh->reviewed_by);
        $this->assertSame(0, $this->seats($submission));
        $this->assertSame(0, $this->trail($submission));
        $this->assertSame([], $this->publicCopies());
        Notification::assertNothingSent();
    }

    #[DataProvider('unsafePaths')]
    public function test_an_unsafe_photo_path_on_record_blocks_the_approval_the_same_way(string $path): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee();
        $submission = $this->submission($committee, $this->position($committee), 'pending', ['photo_path' => $path]);

        $this->actingAs($admin)->patch($this->approveUrl($committee, $submission))
            ->assertRedirect($this->showUrl($committee, $submission))->assertSessionHas('error');

        $this->assertSame('pending', $submission->fresh()->status);
        $this->assertSame(0, $this->seats($submission));
        $this->assertSame([], $this->publicCopies());
    }

    // ------------------------------------------------------------------------------------------------ D. repeating a decision changes nothing

    public function test_a_repeated_approval_is_a_plain_no_op_with_one_seat_one_audit_row_one_copy_and_one_email(): void
    {
        $admin = $this->english($this->superAdmin());
        $committee = $this->committee();
        $submission = $this->withPhoto($committee, $this->position($committee));

        $this->actingAs($admin)->patch($this->approveUrl($committee, $submission))->assertSessionHas('success');
        $copy = $submission->fresh()->photo_approved_path;
        $reviewedAt = $submission->fresh()->reviewed_at;

        $second = $this->actingAs($admin)->patch($this->approveUrl($committee, $submission));
        $second->assertRedirect($this->showUrl($committee, $submission))->assertSessionHas('status')->assertSessionMissing('error');
        $this->assertStringContainsString('already approved', (string) session('status'));

        $this->actingAs($admin)->patch($this->approveUrl($committee, $submission))->assertRedirect()->assertSessionHas('status');

        $fresh = $submission->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($copy, $fresh->photo_approved_path, 'the public copy is not replaced');
        $this->assertTrue($reviewedAt->equalTo($fresh->reviewed_at), 'the first decision stays the recorded one');
        $this->assertCount(1, $this->publicCopies());
        $this->assertSame(1, $this->seats($submission));
        $this->assertSame(1, $this->trail($submission, 'approved'));
        Notification::assertSentOnDemandTimes(CommitteeSubmissionStatusChangedNotification::class, 1);
    }

    public function test_a_stale_page_is_answered_from_the_row_as_it_is_now(): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee();
        $submission = $this->withPhoto($committee, $this->position($committee));

        // A review page opened while the application was pending, and still open in another tab...
        $stale = CommitteeSubmission::query()->findOrFail($submission->id);
        $this->assertSame('pending', $stale->status);

        // ...while someone else (or the first click of a double click) decides.
        $this->actingAs($admin)->patch($this->approveUrl($committee, $submission))->assertSessionHas('success');

        // The request that carries the STALE model must find out under the row lock, not trust what it was handed.
        app()->setLocale('en'); // (the request above ran in the admin's own language, Bangla by default)
        $request = Request::create('/', 'PATCH');
        $request->setUserResolver(fn () => $admin);
        $response = app()->call([app(CommitteeSubmissionController::class), 'approve'], ['request' => $request, 'committee' => $committee, 'submission' => $stale]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('already approved', (string) session('status'));
        $this->assertSame(1, $this->seats($submission));
        $this->assertSame(1, $this->trail($submission, 'approved'));
        $this->assertCount(1, $this->publicCopies());
    }

    // ------------------------------------------------------------------------------------------------ E. an old application, today's rules

    public function test_an_old_application_is_approved_from_its_own_row_whatever_the_committee_and_form_look_like_today(): void
    {
        $admin = $this->superAdmin();
        // The committee has moved on, the position is switched off and the application predates fields the form asks today.
        $committee = $this->committee('completed');
        $position = $this->position($committee, status: 'inactive');
        $submission = $this->submission($committee, $position, 'pending', [
            'phone' => '', 'provatferi_comment' => '', 'bio' => null, 'name_en' => null,
            'facebook_url' => 'http://old.example/profile page', 'publishing_consent' => false, 'accuracy_declaration' => false,
            'submitted_at' => now()->subYears(2),
        ]);

        $this->actingAs($admin)->patch($this->approveUrl($committee, $submission))->assertRedirect()->assertSessionHas('success');

        $this->assertSame('approved', $submission->fresh()->status);
        $this->assertSame($position->id, CommitteeMember::query()->where('committee_submission_id', $submission->id)->value('committee_position_id'));
    }

    public function test_the_single_seat_warning_still_comes_through(): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee();
        $position = $this->position($committee, allowDuplicates: false);
        $committee->members()->create(['committee_position_id' => $position->id, 'status' => 'active', 'start_date' => now()]);
        $submission = $this->submission($committee, $position);

        $this->actingAs($admin)->patch($this->approveUrl($committee, $submission))->assertRedirect()->assertSessionHas('warning');

        $this->assertSame('approved', $submission->fresh()->status);
        $this->assertSame(2, CommitteeMember::query()->where('committee_position_id', $position->id)->count());
    }

    // ------------------------------------------------------------------------------------------------ K. a failure part-way rolls everything back

    public function test_a_failure_while_the_seat_is_created_rolls_everything_back_and_removes_the_public_copy(): void
    {
        $admin = $this->english($this->superAdmin());
        $committee = $this->committee();
        $submission = $this->withPhoto($committee, $this->position($committee));

        CommitteeMember::creating(fn () => throw new \RuntimeException('simulated failure while creating the seat'));

        $response = $this->actingAs($admin)->patch($this->approveUrl($committee, $submission));

        $response->assertRedirect($this->showUrl($committee, $submission))->assertSessionHas('error');
        $this->assertStringContainsString('nothing was changed', (string) session('error'));
        $fresh = $submission->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->photo_approved_path);
        $this->assertNull($fresh->reviewed_by);
        $this->assertSame(0, $this->seats($submission));
        $this->assertSame(0, $this->trail($submission));
        $this->assertSame([], $this->publicCopies(), 'the copy made before the failure is removed again');
        Notification::assertNothingSent();

        // The same click, once the cause is gone, simply works: nothing half-done was left behind.
        CommitteeMember::flushEventListeners();
        $this->actingAs($admin)->patch($this->approveUrl($committee, $submission))->assertRedirect()->assertSessionHas('success');
        $this->assertSame('approved', $submission->fresh()->status);
        $this->assertSame(1, $this->seats($submission));
        $this->assertCount(1, $this->publicCopies());
    }

    public function test_a_failure_is_logged_with_metadata_only(): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee();
        $submission = $this->withPhoto($committee, $this->position($committee), 'pending');
        CommitteeMember::creating(fn () => throw new \RuntimeException('secret detail rafiq@example.com 01800000000'));
        Log::spy();

        $this->actingAs($admin)->patch($this->approveUrl($committee, $submission))->assertSessionHas('error');

        Log::shouldHaveReceived('error')->withArgs(function ($message, $context = []) use ($submission, $committee, $admin) {
            $flat = json_encode([$message, $context]);

            return $message === 'committee_review.failed'
                && $context['submission_id'] === $submission->id && $context['committee_id'] === $committee->id
                && $context['actor_id'] === $admin->id && $context['action'] === 'approve' && $context['state'] === 'pending'
                && $context['error_code'] === 'RuntimeException' && ! empty($context['reference'])
                && ! str_contains($flat, 'secret detail') && ! str_contains($flat, '@') && ! str_contains($flat, '01800000000');
        })->once();
    }

    public function test_a_blocked_decision_is_logged_with_metadata_only(): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee();
        $submission = $this->submission($committee, $this->position($committee), 'rejected');
        Log::spy();

        $this->actingAs($admin)->patch($this->approveUrl($committee, $submission));

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) use ($submission, $committee, $admin) {
            $flat = json_encode($context);

            return $message === 'committee_review.conflict'
                && $context['submission_id'] === $submission->id && $context['committee_id'] === $committee->id
                && $context['actor_id'] === $admin->id && $context['action'] === 'approve' && $context['state'] === 'rejected'
                && $context['error_code'] === 'transition_not_allowed' && ! str_contains($flat, '@') && ! str_contains($flat, 'রফিক');
        })->once();
    }

    // ------------------------------------------------------------------------------------------------ F. a reviewer sees the photo

    public function test_the_review_page_shows_the_photo_of_a_pending_application_through_the_authenticated_route(): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee();
        $submission = $this->withPhoto($committee, $this->position($committee));

        $page = $this->actingAs($admin)->get($this->showUrl($committee, $submission))->assertOk();

        $page->assertSee('data-photo-state="available"', false);
        $page->assertSee($this->photoUrl($committee, $submission), false);
        $page->assertDontSee('/storage/', false);
        $page->assertDontSee($submission->photo_path, false);
    }

    public function test_the_photo_route_streams_the_picture_with_private_headers(): void
    {
        $admin = $this->userWith(['organization.view']);
        $committee = $this->committee();
        $submission = $this->withPhoto($committee, $this->position($committee));

        $response = $this->actingAs($admin)->get($this->photoUrl($committee, $submission));

        $response->assertOk();
        $this->assertSame($this->png(), $response->streamedContent());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString($submission->photo_path, (string) $response->headers->get('Content-Disposition'));
    }

    public function test_the_photo_of_an_approved_application_is_still_shown_when_the_original_is_gone_from_its_public_copy(): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee();
        $submission = $this->withPhoto($committee, $this->position($committee));
        $this->actingAs($admin)->patch($this->approveUrl($committee, $submission));
        Storage::disk('uploads_private')->delete($submission->fresh()->photo_path);

        $this->actingAs($admin)->get($this->showUrl($committee, $submission))->assertOk()->assertSee('data-photo-state="available"', false);
        $response = $this->actingAs($admin)->get($this->photoUrl($committee, $submission))->assertOk();
        $this->assertSame($this->png(), $response->streamedContent());
    }

    // ------------------------------------------------------------------------------------------------ G / H. who may see it

    public function test_an_anonymous_visitor_is_not_given_the_photo(): void
    {
        $committee = $this->committee();
        $submission = $this->withPhoto($committee, $this->position($committee));

        $response = $this->get($this->photoUrl($committee, $submission));

        $response->assertRedirect(route('login'));
        $this->assertStringNotContainsString($this->png(), (string) $response->getContent());
    }

    public function test_a_staff_account_without_the_permission_is_not_given_the_photo(): void
    {
        $committee = $this->committee();
        $submission = $this->withPhoto($committee, $this->position($committee));

        foreach ([$this->userWith(['membership.view']), $this->userWith([])] as $outsider) {
            $this->actingAs($outsider)->get($this->photoUrl($committee, $submission))->assertForbidden();
            $this->actingAs($outsider)->get($this->showUrl($committee, $submission))->assertForbidden();
        }
    }

    // ------------------------------------------------------------------------------------------------ I. no photo, lost photo

    public function test_an_application_without_a_photo_shows_a_placeholder_and_the_route_says_not_found(): void
    {
        $admin = $this->english($this->superAdmin());
        $committee = $this->committee();
        $submission = $this->submission($committee, $this->position($committee));

        $page = $this->actingAs($admin)->get($this->showUrl($committee, $submission))->assertOk();
        $page->assertSee('data-photo-state="none"', false)->assertSee('No photo provided');
        $page->assertDontSee($this->photoUrl($committee, $submission), false);

        $this->actingAs($admin)->get($this->photoUrl($committee, $submission))->assertNotFound();
    }

    public function test_a_photo_whose_file_is_lost_shows_a_placeholder_with_a_note_and_the_route_says_not_found(): void
    {
        $admin = $this->english($this->superAdmin());
        $committee = $this->committee();
        $submission = $this->submission($committee, $this->position($committee), 'pending', ['photo_path' => 'committee-submissions/'.Str::uuid().'.png']);

        $page = $this->actingAs($admin)->get($this->showUrl($committee, $submission))->assertOk();
        $page->assertSee('data-photo-state="missing"', false)->assertSee('the file is no longer available');
        $page->assertDontSee($this->photoUrl($committee, $submission), false);

        $this->actingAs($admin)->get($this->photoUrl($committee, $submission))->assertNotFound();
    }

    // ------------------------------------------------------------------------------------------------ J. malformed, traversal and foreign paths

    /** @return array<string, array{string}> */
    public static function unsafePaths(): array
    {
        return [
            'dot-dot to the environment file' => ['../../.env'],
            'dot-dot after the right folder' => ['committee-submissions/../../../.env'],
            'dot-dot inside a name' => ['committee-submissions/..%2f..%2f.env'],
            'absolute unix path' => ['/etc/passwd'],
            'windows path' => ['C:\\Windows\\win.ini'],
            'backslashes' => ['committee-submissions\\..\\..\\.env'],
            'a NUL byte' => ["committee-submissions/a.png\0.php"],
            'another private folder' => ['applications/cv/secret.pdf'],
            'the right folder, a script' => ['committee-submissions/shell.php'],
            'a nested folder' => ['committee-submissions/sub/dir.png'],
            'a double extension' => ['committee-submissions/a.png.php'],
            'no folder at all' => ['photo.png'],
            'an url' => ['https://example.org/p.png'],
        ];
    }

    #[DataProvider('unsafePaths')]
    public function test_an_unsafe_path_is_never_read_and_never_offered(string $path): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee();
        // A tempting file really exists where a naive join would land.
        Storage::disk('uploads_private')->put('applications/cv/secret.pdf', '%PDF-1.4 secret');
        Storage::disk('uploads_private')->put('committee-submissions/shell.php', '<?php echo "pwned";');
        $submission = $this->submission($committee, $this->position($committee), 'pending', ['photo_path' => $path]);

        $this->actingAs($admin)->get($this->photoUrl($committee, $submission))->assertNotFound();

        $page = $this->actingAs($admin)->get($this->showUrl($committee, $submission))->assertOk();
        $page->assertDontSee($this->photoUrl($committee, $submission), false);
        $page->assertSee('data-photo-state="missing"', false);
    }

    public function test_an_unsafe_public_copy_path_is_not_followed_either(): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee();
        $submission = $this->submission($committee, $this->position($committee), 'approved', ['photo_approved_path' => '../../.env']);

        $this->actingAs($admin)->get($this->photoUrl($committee, $submission))->assertNotFound();
    }

    public function test_another_committees_application_cannot_be_reached_through_this_committee(): void
    {
        $admin = $this->superAdmin();
        $mine = $this->committee();
        $other = $this->committee();
        $theirs = $this->withPhoto($other, $this->position($other));

        $this->actingAs($admin)->get($this->photoUrl($mine, $theirs))->assertNotFound();
        $this->actingAs($admin)->get($this->showUrl($mine, $theirs))->assertNotFound();
        $this->actingAs($admin)->patch($this->approveUrl($mine, $theirs))->assertNotFound();
        $this->assertSame('pending', $theirs->fresh()->status);
    }

    // ------------------------------------------------------------------------------------------------ the page: guards, state, languages

    public function test_the_decision_forms_carry_the_double_submit_guard_and_the_script_is_loaded(): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee();
        $submission = $this->submission($committee, $this->position($committee));

        $page = $this->actingAs($admin)->get($this->showUrl($committee, $submission))->assertOk();

        $html = $page->getContent();
        foreach (['approve', 'reject', 'request-correction'] as $action) {
            $this->assertMatchesRegularExpression('#<form[^>]*submissions/'.$submission->id.'/'.$action.'"[^>]*data-form-guard#s', $html, "the {$action} form is guarded");
        }
        $page->assertSee('js/provatferi-form-guard.js', false);
    }

    public function test_an_approved_application_says_so_and_offers_no_decision(): void
    {
        $admin = $this->english($this->superAdmin());
        $committee = $this->committee();
        $submission = $this->submission($committee, $this->position($committee));
        $this->actingAs($admin)->patch($this->approveUrl($committee, $submission));

        $page = $this->actingAs($admin)->get($this->showUrl($committee, $submission))->assertOk();

        $page->assertSee('seat on the committee roster')->assertDontSee('submissions/'.$submission->id.'/approve', false);
    }

    public function test_the_messages_come_in_both_admin_languages_without_raw_keys(): void
    {
        $committee = $this->committee();
        $position = $this->position($committee);

        foreach (['bn', 'en'] as $locale) {
            $admin = $this->superAdmin();
            $admin->forceFill(['ui_locale' => $locale])->save();

            $rejected = $this->submission($committee, $position, 'rejected');
            $lost = $this->submission($committee, $position, 'pending', ['photo_path' => 'committee-submissions/'.Str::uuid().'.png']);
            $done = $this->submission($committee, $position, 'approved');

            foreach ([$rejected, $lost, $done] as $submission) {
                $this->actingAs($admin)->patch($this->approveUrl($committee, $submission));
                $flash = (string) (session('error') ?? session('status') ?? '');
                $this->assertNotSame('', $flash, "{$locale}: a message was given");
                $this->assertStringNotContainsString('admin.', $flash, "{$locale}: no raw translation key");
                $this->assertStringNotContainsString('committee_review', $flash);
                $this->assertSame($locale === 'bn', (bool) preg_match('/[\x{0980}-\x{09FF}]/u', $flash), "{$locale}: the message is in the admin's language");

                $page = $this->actingAs($admin)->get($this->showUrl($committee, $submission))->assertOk();
                $this->assertStringNotContainsString('committee_review.', $page->getContent());
            }
        }
    }

    public function test_the_branded_unprocessable_page_exists_in_both_languages(): void
    {
        // Under the real `web` group, so the admin's own language is resolved exactly as on every admin route.
        $this->app['router']->middleware('web')->get('/__qa-422', fn () => abort(422));

        foreach (['bn' => '/[\x{0980}-\x{09FF}]/u', 'en' => '/could not be processed/'] as $locale => $needle) {
            $admin = $this->superAdmin();
            $admin->forceFill(['ui_locale' => $locale])->save();

            $response = $this->actingAs($admin)->get('/__qa-422')->assertStatus(422);

            $response->assertSee($locale === 'bn' ? '৪২২' : '422')->assertSee('error-card', false);
            $this->assertMatchesRegularExpression($needle, $response->getContent());
            $this->assertStringNotContainsString('Unprocessable Content', $response->getContent());
        }
    }

    public function test_requesting_a_correction_still_issues_a_link_and_one_email(): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee();
        $submission = $this->submission($committee, $this->position($committee));
        $url = route('admin.committees.submissions.request-correction', [$committee, $submission]);

        $this->actingAs($admin)->patch($url, ['admin_note' => 'ছবি স্পষ্ট নয়।'])
            ->assertRedirect()->assertSessionHas('generated_correction_link', fn ($link) => str_contains($link, '/committee/register/correct/'));
        $this->assertSame('correction_requested', $submission->fresh()->status);

        // The second click does not issue a second link and does not send a second email.
        $this->actingAs($admin)->patch($url, ['admin_note' => 'ছবি স্পষ্ট নয়।'])->assertRedirect()->assertSessionMissing('generated_correction_link');
        $this->assertSame(1, $this->trail($submission, 'correction_requested'));
        Notification::assertSentOnDemandTimes(CommitteeCorrectionRequestedNotification::class, 1);
    }
}
