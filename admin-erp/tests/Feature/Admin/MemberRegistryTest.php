<?php

namespace Tests\Feature\Admin;

use App\Models\ApprovalHistory;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\MembershipType;
use App\Models\PublicMemberProfileVersion;
use App\Models\User;
use App\Services\MembershipApprovalService;
use App\Services\PublicSiteRevalidator;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MakesMembershipTypes;

/**
 * Membership Registry task 2 (2026-10-06): the Admin Member Registry — list, search, filters, pagination, the member
 * page, the audited status actions, edits, and what they mean for the member portal and the public directory.
 */
class MemberRegistryTest extends AdminTestCase
{
    use MakesMembershipTypes;

    private User $admin;

    private MembershipType $student;

    private MembershipType $general;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->superAdmin();
        $this->student = $this->makeMembershipType(['name' => 'শিক্ষার্থী', 'slug' => 'student-'.uniqid(), 'code' => 'ST'], ['registration' => '0', 'monthly' => '0']);
        $this->general = $this->makeMembershipType(['name' => 'সাধারণ', 'slug' => 'general-'.uniqid(), 'code' => 'GM'], ['registration' => '100', 'monthly' => '0']);
    }

    /** A member created the only way members are created: an application, approved. @param array<string, mixed> $overrides */
    private function member(array $overrides = [], ?MembershipType $type = null): Membership
    {
        $type ??= $this->student;
        $application = MembershipApplication::query()->create($overrides + [
            'application_no' => 'APP-R-'.uniqid(),
            'applicant_name' => 'সদস্য '.uniqid(),
            'applicant_email' => 'm-'.uniqid().'@example.com',
            'applicant_phone' => '018'.random_int(10000000, 99999999),
            'membership_type_id' => $type->id,
            'application_data' => ['profession' => 'শিক্ষক', 'institution' => 'প্রভাতফেরী বিদ্যালয়'],
            'status' => 'under_review',
        ]);
        if ($type->is($this->general)) {
            $application->payments()->create([
                'membership_type_id' => $type->id, 'amount_expected' => '100', 'amount_received' => '100', 'received_at' => now()->toDateString(),
                'method' => 'cash', 'status' => 'paid', 'verified_at' => now(), 'verified_by' => $this->admin->id,
            ]);
        }

        return app(MembershipApprovalService::class)->approve($application, $this->admin)->membership;
    }

    private function action(Membership $membership, string $action, ?string $reason = null)
    {
        return $this->actingAs($this->admin)->patch(route('admin.membership.members.status', $membership), array_filter(['action' => $action, 'reason' => $reason]));
    }

    /* ================================================================ list, search, filters, pagination */

    public function test_the_registry_lists_members_with_identity_contact_type_and_fee_state(): void
    {
        $membership = $this->member(['applicant_name' => 'আয়েশা সিদ্দিকা', 'applicant_phone' => '01811111111']);

        $this->actingAs($this->admin)->get(route('admin.membership.members.index'))->assertOk()
            ->assertSee('আয়েশা সিদ্দিকা')->assertSee($membership->member_code)->assertSee('01811111111')
            ->assertSee($membership->member->email)->assertSee('শিক্ষার্থী')->assertSee('শিক্ষক')
            // the zero-fee row reads "no fee due" (every state is also listed in the payment filter, so look at the row)
            ->assertSee('<i class="ti ti-circle-minus" aria-hidden="true"></i>ফি প্রযোজ্য নয়', false);
    }

    public function test_search_finds_a_member_by_name_mobile_in_any_spelling_e_mail_or_member_number(): void
    {
        $target = $this->member(['applicant_name' => 'করিম উদ্দিন', 'applicant_email' => 'karim.uddin@example.com', 'applicant_phone' => '01822222222']);
        $other = $this->member(['applicant_name' => 'অন্য কেউ']);
        $index = fn (string $search) => $this->actingAs($this->admin)->get(route('admin.membership.members.index', ['search' => $search]));

        foreach (['করিম', '+880 1822-222222', 'karim.uddin@', $target->member_code] as $search) {
            $index($search)->assertOk()->assertSee($target->member_code)->assertDontSee($other->member_code);
        }
    }

    public function test_filters_narrow_by_status_type_fee_state_public_profile_and_joining_date(): void
    {
        $student = $this->member();
        $general = $this->member([], $this->general);
        $suspended = $this->member();
        $this->action($suspended, 'suspend', 'বকেয়া।');
        $old = $this->member();
        $old->forceFill(['start_date' => '2025-01-15'])->save();
        $public = $this->member();
        $public->member->forceFill(['public_profile_enabled' => true, 'public_profile_approved' => true])->save();

        $see = fn (array $query, array $in, array $out) => tap($this->actingAs($this->admin)->get(route('admin.membership.members.index', $query))->assertOk(),
            function ($response) use ($in, $out) {
                foreach ($in as $m) {
                    $response->assertSee($m->member_code);
                }
                foreach ($out as $m) {
                    $response->assertDontSee($m->member_code);
                }
            });

        $see(['status' => 'suspended'], [$suspended], [$student, $general]);
        $see(['type' => $this->general->id], [$general], [$student, $suspended]);
        $see(['payment' => 'paid'], [$general], [$student]);
        $see(['payment' => 'not_required'], [$student], [$general]);
        $see(['profile' => 'public'], [$public], [$student, $general]);
        $see(['joined_to' => '2025-12-31'], [$old], [$student, $general]);
        $see(['joined_from' => now()->subDay()->toDateString()], [$student, $general], [$old]);
    }

    public function test_pagination_and_page_size(): void
    {
        $members = collect(range(1, 17))->map(fn () => $this->member());

        // Bootstrap's own pagination in the panel's language and digits — not Laravel's default Tailwind markup, which
        // rendered oversized bare chevrons and an English "Showing … results" line in this Bootstrap panel.
        $this->actingAs($this->admin)->get(route('admin.membership.members.index'))->assertOk()
            ->assertSee('?page=2', false)->assertSee('<ul class="pagination', false)
            ->assertSee('১–১৫, মোট ১৭')->assertDontSee('Showing')->assertDontSee('results');
        $this->actingAs($this->admin)->get(route('admin.membership.members.index', ['per_page' => 30]))->assertOk()
            ->assertDontSee('page=2', false)->assertSee($members->first()->member_code)->assertSee($members->last()->member_code);
    }

    public function test_empty_states_with_and_without_filters(): void
    {
        $this->actingAs($this->admin)->get(route('admin.membership.members.index'))->assertOk()
            ->assertSee('এখনো কোনো সদস্য নেই')->assertSee(route('admin.membership.index'), false);

        $this->member();
        $this->actingAs($this->admin)->get(route('admin.membership.members.index', ['search' => 'zzzz-nobody']))->assertOk()
            ->assertSee('এই ফিল্টারে কোনো সদস্য পাওয়া যায়নি');
    }

    /* ================================================================ the member page */

    public function test_the_member_page_shows_identity_contact_membership_fee_snapshot_and_history(): void
    {
        $membership = $this->member(['applicant_name' => 'নাসরিন আক্তার'], $this->general);

        $this->actingAs($this->admin)->get(route('admin.membership.members.show', $membership))->assertOk()
            ->assertSee('নাসরিন আক্তার')->assertSee($membership->member_code)->assertSee($membership->member->phone)
            ->assertSee('প্রভাতফেরী বিদ্যালয়')
            ->assertSee(route('admin.membership.show', $membership->application), false)
            ->assertSee('পরিশোধিত (যাচাইকৃত)')
            ->assertSee('অনুমোদিত আবেদন থেকে সদস্যপদ তৈরি')
            ->assertSee('সদস্য পোর্টাল অ্যাকাউন্ট তৈরি');

        // English, and no double-escaped component attribute ("Contact &amp;amp; profile" read "Contact &amp; profile").
        $this->admin->forceFill(['ui_locale' => 'en'])->save();
        foreach ([route('admin.membership.members.show', $membership), route('admin.membership.members.edit', $membership), route('admin.membership.show', $membership->application)] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk()->assertDontSee('&amp;amp;', false)->assertDontSee('&amp;#039;', false);
        }
        $this->actingAs($this->admin)->get(route('admin.membership.members.show', $membership))->assertSee('Contact &amp; profile', false)->assertSee('Membership type');
    }

    /* ================================================================ status actions */

    public function test_suspending_needs_a_reason_signs_the_member_out_hides_them_and_is_recorded(): void
    {
        $membership = $this->member();
        $person = $membership->member;
        $token = $person->createToken('portal')->plainTextToken;

        $this->action($membership, 'suspend')->assertSessionHasErrors('reason');
        $this->assertSame('active', $membership->fresh()->status);

        $this->action($membership, 'suspend', 'বকেয়া চাঁদা।')->assertSessionHas('success');
        $this->assertSame('suspended', $membership->fresh()->status);
        $this->assertSame('suspended', $person->fresh()->status);
        $this->assertSame(0, $person->tokens()->count(), 'signed out of the member portal everywhere');
        $this->assertDatabaseHas('approval_history', ['subject_type' => Membership::class, 'subject_id' => $membership->id, 'action' => 'suspended', 'note' => 'বকেয়া চাঁদা।', 'actor_id' => $this->admin->id]);

        // a token issued before the suspension is refused, and so is a fresh sign-in. (Forget the admin's test session
        // first: Sanctum would otherwise authenticate the request as that web user, not by the token.)
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/member/me')->assertStatus(401);
        $person->forceFill(['password' => 'a-known-password'])->save();
        $this->postJson('/api/v1/member/auth/login', ['email' => $person->email, 'password' => 'a-known-password'])->assertUnprocessable();

        $this->actingAs($this->admin)->get(route('admin.membership.members.show', $membership))->assertOk()->assertSee('বকেয়া চাঁদা।');
    }

    public function test_reactivate_archive_and_activate_follow_the_allowed_transitions(): void
    {
        $membership = $this->member();

        $this->action($membership, 'reactivate')->assertSessionHas('status'); // already active: nothing to do
        $this->action($membership, 'archive')->assertSessionHasErrors('reason');
        $this->action($membership, 'archive', 'সংগঠন ছেড়েছেন।')->assertSessionHas('success');
        $this->assertSame('archived', $membership->fresh()->status);
        $this->assertSame('inactive', $membership->member->fresh()->status);

        $this->action($membership, 'suspend', 'x')->assertSessionHas('error'); // an archived membership cannot be suspended
        $this->action($membership, 'reactivate')->assertSessionHas('success');
        $this->assertSame('active', $membership->fresh()->status);
        $this->assertSame('active', $membership->member->fresh()->status);

        $membership->forceFill(['status' => 'inactive'])->save();
        $this->action($membership, 'activate')->assertSessionHas('success');
        $this->assertSame('active', $membership->fresh()->status);

        $this->assertNotNull(Membership::query()->find($membership->id), 'nothing is ever deleted');
        $this->assertSame(['activated', 'archived', 'created', 'reactivated'], ApprovalHistory::query()
            ->where('subject_type', Membership::class)->where('subject_id', $membership->id)->orderBy('action')->pluck('action')->all());
    }

    public function test_a_repeated_status_action_is_recorded_once(): void
    {
        $membership = $this->member();

        $this->action($membership, 'suspend', 'প্রথম বার।')->assertSessionHas('success');
        $this->action($membership, 'suspend', 'দ্বিতীয় বার।')->assertSessionHas('status');

        $this->assertSame(1, ApprovalHistory::query()->where('subject_type', Membership::class)->where('subject_id', $membership->id)->where('action', 'suspended')->count());
    }

    /* ================================================================ editing */

    public function test_editing_records_old_and_new_values_and_keeps_email_and_mobile_unique(): void
    {
        $membership = $this->member(['applicant_name' => 'পুরনো নাম', 'applicant_phone' => '01833333333']);
        $other = $this->member(['applicant_phone' => '01844444444']);
        $update = fn (array $data) => $this->actingAs($this->admin)->put(route('admin.membership.members.update', $membership), $data + [
            'name' => 'নতুন নাম', 'email' => $membership->member->email, 'phone' => '01833333333',
            'address' => 'রাজশাহী', 'profession' => 'চিকিৎসক', 'institution' => 'রাজশাহী মেডিকেল',
        ]);

        $update(['email' => $other->member->email])->assertSessionHasErrors('email');
        $update(['phone' => '+880 1844-444444'])->assertSessionHasErrors('phone');
        $update([])->assertRedirect(route('admin.membership.members.show', $membership));

        $person = $membership->member->fresh();
        $this->assertSame(['নতুন নাম', 'রাজশাহী', 'চিকিৎসক', 'রাজশাহী মেডিকেল'], [$person->name, $person->address, $person->profession, $person->institution]);
        $entry = ApprovalHistory::query()->where('subject_type', Member::class)->where('subject_id', $person->id)->where('action', 'updated')->sole();
        $changes = json_decode($entry->note, true)['changes'];
        $this->assertSame(['পুরনো নাম', 'নতুন নাম'], $changes['name']);
        $this->assertSame('শিক্ষক', $changes['profession'][0]);
        $this->assertArrayNotHasKey('email', $changes, 'unchanged fields are not recorded');

        // the history reads in the viewer's language
        $this->actingAs($this->admin)->get(route('admin.membership.members.show', $membership))->assertOk()->assertSee('পুরনো নাম → নতুন নাম');
    }

    /* ================================================================ permissions */

    public function test_a_viewer_can_see_the_registry_but_cannot_change_status_or_edit(): void
    {
        $membership = $this->member();
        $viewer = $this->userWith(['membership.view']);

        $this->actingAs($viewer)->get(route('admin.membership.members.index'))->assertOk();
        $this->actingAs($viewer)->get(route('admin.membership.members.show', $membership))->assertOk()->assertDontSee('data-testid="status-actions"', false);
        $this->actingAs($viewer)->patch(route('admin.membership.members.status', $membership), ['action' => 'suspend', 'reason' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->put(route('admin.membership.members.update', $membership), ['notes' => 'x'])->assertForbidden();
        $this->assertSame('active', $membership->fresh()->status);
    }

    /* ================================================================ member portal & public directory */

    public function test_the_member_portal_reports_no_fee_due_for_a_zero_fee_membership_and_paid_for_a_paid_one(): void
    {
        $student = $this->member();
        $token = $student->member->createToken('portal')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/member/me')->assertOk()
            ->assertJsonPath('data.profile.member_code', $student->member_code)
            ->assertJsonPath('data.memberships.0.registration_fee', '0.00')
            ->assertJsonPath('data.memberships.0.payment_state', 'not_required')
            ->assertJsonPath('data.payments', []);

        $general = $this->member([], $this->general);
        $token = $general->member->createToken('portal')->plainTextToken;
        $this->app['auth']->forgetGuards(); // the test app keeps the guard (and the member it resolved) between requests
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/member/me')->assertOk()
            ->assertJsonPath('data.memberships.0.payment_state', 'paid');
    }

    public function test_suspending_a_public_member_takes_them_off_the_directory_and_tells_the_public_site(): void
    {
        config(['services.public_site.url' => 'https://provatferi.org', 'services.public_site.revalidate_secret' => 'test-secret-not-real']);
        $membership = $this->member(['applicant_name' => 'প্রকাশ্য সদস্য']);
        $person = $membership->member;
        $person->forceFill(['public_profile_enabled' => true, 'public_profile_approved' => true, 'public_slug' => 'prokashyo-'.uniqid()])->save();
        PublicMemberProfileVersion::query()->create(['member_id' => $person->id, 'status' => 'approved', 'is_current_live' => true, 'profession' => 'লেখক', 'submitted_at' => now(), 'reviewed_at' => now()]);
        $fakeSite = function () {
            Http::swap((new HttpFactory)->preventStrayRequests()); // a fresh factory: Http::fake() only appends stubs
            Http::fake(['https://provatferi.org/api/revalidate' => Http::response(['ok' => true])]);
        };
        $fakeSite();
        app(PublicSiteRevalidator::class)->flush(); // what the setup above queued goes to the fake…
        $fakeSite();                                 // …and the recorder starts empty for the step under test

        $this->getJson('/api/v1/public/members')->assertOk()->assertJsonFragment(['name' => 'প্রকাশ্য সদস্য']);

        $this->action($membership, 'suspend', 'নিয়ম ভঙ্গ।')->assertSessionHas('success');

        $tags = Http::recorded(fn (Request $request) => $request->url() === 'https://provatferi.org/api/revalidate')->map(fn ($pair) => $pair[0]['tags'])->all();
        $this->assertSame([[PublicSiteRevalidator::MEMBERS_TAG]], $tags, 'one call, sent before the admin\'s response, with the members tag');
        $this->getJson('/api/v1/public/members')->assertOk()->assertJsonMissing(['name' => 'প্রকাশ্য সদস্য']);
        $this->getJson('/api/v1/public/members/'.$person->public_slug)->assertNotFound();
    }

    public function test_contact_details_never_reach_the_public_directory(): void
    {
        $membership = $this->member(['applicant_name' => 'গোপন তথ্যধারী', 'applicant_phone' => '01855555555']);
        $person = $membership->member;
        $person->forceFill(['public_profile_enabled' => true, 'public_profile_approved' => true, 'public_slug' => 'gopon-'.uniqid(), 'address' => 'গোপন ঠিকানা'])->save();
        PublicMemberProfileVersion::query()->create(['member_id' => $person->id, 'status' => 'approved', 'is_current_live' => true, 'submitted_at' => now(), 'reviewed_at' => now()]);

        $body = $this->getJson('/api/v1/public/members/'.$person->public_slug)->assertOk()->getContent();
        foreach (['01855555555', $person->email, 'গোপন ঠিকানা', $membership->member_code, 'শিক্ষক'] as $private) {
            $this->assertStringNotContainsString($private, $body);
        }
    }
}
