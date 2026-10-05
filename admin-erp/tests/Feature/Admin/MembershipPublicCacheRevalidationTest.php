<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\FlushPublicSiteRevalidations;
use App\Models\MembershipApplication;
use App\Models\MembershipFeePolicy;
use App\Models\MembershipSeason;
use App\Models\MembershipType;
use App\Services\PublicSiteRevalidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\MakesMembershipTypes;

/**
 * 2026-10-05, membership public cache: when an admin changes anything the public membership page is built from, the
 * public site is told which cached lookups to drop — and told BEFORE the admin is shown "saved", so the first public
 * request after that can never be answered from the old data.
 *
 * Pinned here, and why each one matters:
 *   - EVERY admin action on seasons, types and fee policies triggers exactly the tag(s) of what it changed, in ONE call
 *     (the observers cover every write path, not a list of call sites that the next added action would silently skip)
 *   - a season's offered-types list (a pivot sync fires no model event) triggers too
 *   - reading a screen sends nothing
 *   - the call goes out inside the request, before the response is returned (the early-flush middleware)
 *   - a failing or unreachable public site NEVER breaks or undoes an admin's save; a fast failure is retried once, with
 *     a fresh nonce, and a slow one is not (it would stall the admin)
 *   - the signature covers timestamp, nonce and the sorted tags (v2), the secret itself is never in the request
 *   - an unconfigured environment (local dev, CI) makes no HTTP call at all
 */
class MembershipPublicCacheRevalidationTest extends AdminTestCase
{
    use MakesMembershipTypes;

    private const SECRET = 'test-revalidate-secret-not-real';

    private const URL = 'https://provatferi.org/api/revalidate';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 10:00:00');
        config(['services.public_site.url' => 'https://provatferi.org', 'services.public_site.revalidate_secret' => self::SECRET]);
        $this->resetCalls();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Clears the recorder (and the revalidator's own pending list) so each step counts only its own calls. */
    private function resetCalls(): void
    {
        app(PublicSiteRevalidator::class)->flush();
        $this->fakeHttp([self::URL => Http::response(['ok' => true])]);
    }

    /** Http::fake() only APPENDS stubs and the first matching one wins, so a test that needs different behaviour starts from a fresh factory. */
    private function fakeHttp(mixed $stub): void
    {
        Http::swap((new HttpFactory)->preventStrayRequests()); // a fresh factory keeps the suite-wide "no real network" rule
        Http::fake($stub);
    }

    /** @return list<list<string>> the tags carried by each call made to the public site, in order */
    private function calls(): array
    {
        return Http::recorded(fn (Request $request) => $request->url() === self::URL)
            ->map(fn (array $pair) => $pair[0]['tags'])
            ->values()
            ->all();
    }

    private function season(array $overrides = []): MembershipSeason
    {
        $season = MembershipSeason::query()->create($overrides + [
            'name' => 'সিজন', 'slug' => 'season-'.uniqid(), 'campaign_type' => 'regular', 'status' => 'draft', 'display_order' => 0,
        ]);
        app()->terminate();
        $this->resetCalls();

        return $season;
    }

    private function type(string $code = 'QZ'): MembershipType
    {
        $type = $this->makeMembershipType(['name' => 'কিউ টাইপ', 'slug' => 'type-'.strtolower($code), 'code' => $code]);
        app()->terminate();
        $this->resetCalls();

        return $type;
    }

    /* ======================================================================================== seasons */

    public function test_creating_a_season_tells_the_site_once_with_the_seasons_tag(): void
    {
        $type = $this->type();

        $this->actingAs($this->superAdmin())->post(route('admin.membership.seasons.store'), [
            'name' => 'নতুন সিজন', 'campaign_type' => 'regular', 'status' => 'open', 'display_order' => 0, 'membership_type_ids' => [$type->id],
        ])->assertRedirect(route('admin.membership.seasons.index'));

        $this->assertSame([['membership-seasons']], $this->calls());
    }

    public function test_editing_opening_closing_and_deleting_a_season_each_revalidate(): void
    {
        $admin = $this->superAdmin();
        $season = $this->season();

        $this->actingAs($admin)->put(route('admin.membership.seasons.update', $season), [
            'name' => 'পরিবর্তিত', 'campaign_type' => 'regular', 'status' => 'draft', 'display_order' => 0,
        ])->assertRedirect();
        $this->assertSame([['membership-seasons']], $this->calls(), 'editing must revalidate');

        $this->resetCalls();
        $this->actingAs($admin)->patch(route('admin.membership.seasons.status', $season), ['status' => 'open'])->assertRedirect();
        $this->assertSame([['membership-seasons']], $this->calls(), 'opening must revalidate');

        $this->resetCalls();
        $this->actingAs($admin)->patch(route('admin.membership.seasons.status', $season), ['status' => 'closed'])->assertRedirect();
        $this->assertSame([['membership-seasons']], $this->calls(), 'closing must revalidate');

        $this->resetCalls();
        $this->actingAs($admin)->put(route('admin.membership.seasons.update', $season), [
            'name' => 'পরিবর্তিত', 'campaign_type' => 'regular', 'status' => 'open', 'display_order' => 0, 'opens_at' => '2026-10-06T09:00', 'closes_at' => '2026-10-07T09:00',
        ])->assertRedirect();
        $this->assertSame([['membership-seasons']], $this->calls(), 'changing the start and end dates must revalidate');

        $this->resetCalls();
        $this->actingAs($admin)->delete(route('admin.membership.seasons.destroy', $season))->assertRedirect();
        $this->assertSame([['membership-seasons']], $this->calls(), 'deleting must revalidate');
    }

    public function test_changing_only_the_types_a_season_offers_revalidates_even_though_a_pivot_sync_fires_no_model_event(): void
    {
        $season = $this->season();
        $type = $this->type('QA');

        $season->syncTypes([$type->id]);
        app()->terminate();

        $this->assertSame([['membership-seasons']], $this->calls());
        $this->assertTrue($season->fresh()->membershipTypes->contains($type));
    }

    /* ======================================================================================== types */

    public function test_creating_a_type_with_its_first_policy_is_one_call_carrying_both_tags(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.membership.types.store'), [
            'name' => 'নতুন ধরন', 'code' => 'nt', 'status' => 'active', 'sort_order' => 9,
            'registration_fee' => '10', 'monthly_contribution' => '5', 'effective_from' => '2026-10-05',
            'is_public_self_apply' => '1', 'is_public_visible' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        // one type save + one policy save = ONE signed call, tags sorted
        $this->assertSame([['membership-fees', 'membership-types']], $this->calls());
    }

    public function test_every_type_action_revalidates_the_types_tag(): void
    {
        $admin = $this->superAdmin();
        $type = $this->type();

        // visibility, self-apply and status all travel through the edit form
        $this->actingAs($admin)->put(route('admin.membership.types.update', $type), [
            'name' => 'কিউ টাইপ', 'status' => 'active', 'sort_order' => 1, 'is_public_self_apply' => '1',
            // is_public_visible left out = unchecked = hidden from the public site
        ])->assertRedirect();
        $this->assertSame([['membership-types']], $this->calls(), 'hiding a type must revalidate');
        $this->assertFalse($type->fresh()->is_public_visible);

        $this->resetCalls();
        $this->actingAs($admin)->patch(route('admin.membership.types.toggle', $type))->assertRedirect();
        $this->assertSame([['membership-types']], $this->calls(), 'enabling/disabling must revalidate');

        $this->resetCalls();
        $second = $this->makeMembershipType(['name' => 'দ্বিতীয়', 'slug' => 'second', 'code' => 'SC', 'sort_order' => 2]);
        app()->terminate();
        $this->resetCalls();
        $this->actingAs($admin)->post(route('admin.membership.types.move-up', $second))->assertRedirect();
        $this->assertSame([['membership-types']], $this->calls(), 'a reorder saves two rows but is one call');

        $this->resetCalls();
        $this->actingAs($admin)->delete(route('admin.membership.types.destroy', $second))->assertRedirect();
        $this->assertSame([['membership-types']], $this->calls(), 'deleting a type must revalidate');
    }

    /* ======================================================================================== fee policies */

    public function test_creating_and_cancelling_a_fee_policy_revalidate_the_fees_tag(): void
    {
        $admin = $this->superAdmin();
        $type = $this->type();

        $this->actingAs($admin)->post(route('admin.membership.types.fee-policies.store', $type), [
            'registration_fee' => '100', 'monthly_contribution' => '20', 'effective_from' => '2026-12-01', 'note' => 'future fee',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame([['membership-fees']], $this->calls(), 'a new fee policy must revalidate');

        $future = MembershipFeePolicy::query()->where('membership_type_id', $type->id)->where('effective_from', '2026-12-01')->firstOrFail();
        $this->resetCalls();
        $this->actingAs($admin)->post(route('admin.membership.types.fee-policies.cancel', [$type, $future]), ['cancellation_reason' => 'mistake'])->assertRedirect();
        $this->assertSame([['membership-fees']], $this->calls(), 'cancelling a scheduled policy must revalidate');
    }

    /* ======================================================================================== reading changes nothing */

    public function test_viewing_the_screens_sends_nothing(): void
    {
        $admin = $this->superAdmin();
        $season = $this->season();
        $type = $this->type();

        foreach ([
            route('admin.membership.seasons.index'), route('admin.membership.seasons.create'), route('admin.membership.seasons.edit', $season),
            route('admin.membership.types.index'), route('admin.membership.types.create'), route('admin.membership.types.show', $type), route('admin.membership.types.edit', $type),
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }

        $this->assertSame([], $this->calls());
    }

    /* ======================================================================================== when the call is made */

    public function test_the_membership_screens_flush_before_the_response_is_returned(): void
    {
        $revalidator = app(PublicSiteRevalidator::class);

        $response = (new FlushPublicSiteRevalidations($revalidator))->handle(HttpRequest::create('/admin/membership/seasons', 'POST'), function () use ($revalidator) {
            $revalidator->queue(PublicSiteRevalidator::MEMBERSHIP_SEASONS_TAG);

            return new Response('saved', 302);
        });

        // already sent when the middleware hands the response back — i.e. before the admin's browser is told it worked
        $this->assertSame([['membership-seasons']], $this->calls());
        $this->assertSame(302, $response->getStatusCode());

        app()->terminate();
        $this->assertCount(1, $this->calls(), 'and the terminating flush sends nothing a second time');
    }

    public function test_every_membership_admin_route_carries_the_flush_middleware(): void
    {
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'admin.membership.seasons.') && ! str_starts_with((string) $route->getName(), 'admin.membership.types.')) {
                continue;
            }
            $this->assertContains('revalidate.public', $route->gatherMiddleware(), 'missing on '.$route->getName());
            $checked++;
        }
        $this->assertGreaterThan(15, $checked, 'the season and type routes were found');
    }

    /* ======================================================================================== failure never breaks a save */

    public function test_an_unreachable_public_site_never_breaks_or_undoes_a_membership_save(): void
    {
        $type = $this->type();
        $attempts = 0;
        $this->fakeHttp(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('connection refused');
        });
        Log::spy();

        $this->actingAs($this->superAdmin())->post(route('admin.membership.seasons.store'), [
            'name' => 'টিকে থাকবে', 'campaign_type' => 'regular', 'status' => 'open', 'display_order' => 0, 'membership_type_ids' => [$type->id],
        ])->assertRedirect(route('admin.membership.seasons.index'))->assertSessionHas('success');

        $this->assertDatabaseHas('membership_seasons', ['name' => 'টিকে থাকবে', 'status' => 'open']);
        $this->assertSame(2, $attempts, 'a fast failure is retried once, no more');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'revalidation failed'))->once();
    }

    public function test_a_rejected_call_is_logged_and_not_retried_and_the_save_stands(): void
    {
        $this->fakeHttp([self::URL => Http::response(['ok' => false], 401)]);
        Log::spy();

        $this->actingAs($this->superAdmin())->post(route('admin.membership.seasons.store'), [
            'name' => 'অস্বীকৃত কল', 'campaign_type' => 'regular', 'status' => 'draft', 'display_order' => 0,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('membership_seasons', ['name' => 'অস্বীকৃত কল']);
        Http::assertSentCount(1);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'rejected'))->once();
    }

    public function test_a_fast_connection_failure_is_retried_with_a_fresh_nonce_and_nothing_is_logged_when_the_retry_works(): void
    {
        $nonces = [];
        $attempts = 0;
        $this->fakeHttp(function (Request $request) use (&$nonces, &$attempts) {
            $attempts++;
            $nonces[] = $request->header('X-Revalidate-Nonce')[0] ?? '';
            if ($attempts === 1) {
                throw new ConnectionException('connection reset');
            }

            return Http::response(['ok' => true]);
        });
        Log::spy();

        app(PublicSiteRevalidator::class)->queue(PublicSiteRevalidator::MEMBERSHIP_FEES_TAG);
        app(PublicSiteRevalidator::class)->flush();

        $this->assertSame(2, $attempts);
        $this->assertCount(2, array_unique($nonces), 'a retry is a new request: its nonce must differ, or the site would refuse it as a replay');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_gateway_error_is_retried_once(): void
    {
        $attempts = 0;
        $this->fakeHttp(function () use (&$attempts) {
            $attempts++;

            return $attempts === 1 ? Http::response('bad gateway', 502) : Http::response(['ok' => true]);
        });
        Log::spy();

        app(PublicSiteRevalidator::class)->queue(PublicSiteRevalidator::MEMBERSHIP_TYPES_TAG);
        app(PublicSiteRevalidator::class)->flush();

        $this->assertSame(2, $attempts);
        Log::shouldNotHaveReceived('warning');
    }

    /* ======================================================================================== the wire format */

    public function test_the_signature_covers_timestamp_nonce_and_the_sorted_tags_and_the_secret_is_never_sent(): void
    {
        $revalidator = app(PublicSiteRevalidator::class);
        // queued out of order and twice: the call is de-duplicated and sorted
        $revalidator->queue(PublicSiteRevalidator::MEMBERSHIP_TYPES_TAG, PublicSiteRevalidator::MEMBERSHIP_FEES_TAG);
        $revalidator->queue(PublicSiteRevalidator::MEMBERSHIP_TYPES_TAG, PublicSiteRevalidator::MEMBERSHIP_SEASONS_TAG);
        $revalidator->flush();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $timestamp = $request->header('X-Revalidate-Timestamp')[0] ?? '';
            $nonce = $request->header('X-Revalidate-Nonce')[0] ?? '';
            $signature = $request->header('X-Revalidate-Signature')[0] ?? '';

            return $request->url() === self::URL
                && $request->method() === 'POST'
                && $request['tags'] === ['membership-fees', 'membership-seasons', 'membership-types']
                && ctype_digit($timestamp) && abs(time() - (int) $timestamp) < 60
                && preg_match('/^[a-f0-9]{32}$/', $nonce) === 1
                // exactly what lib/revalidate-auth.ts signRevalidationV2 computes
                && hash_equals(hash_hmac('sha256', 'v2.'.$timestamp.'.'.$nonce.'.membership-fees,membership-seasons,membership-types', self::SECRET), $signature)
                && ! str_contains(json_encode([$request->url(), $request->headers(), $request->body()]), self::SECRET);
        });
    }

    public function test_two_separate_calls_never_share_a_nonce(): void
    {
        $revalidator = app(PublicSiteRevalidator::class);
        $revalidator->queue(PublicSiteRevalidator::MEMBERSHIP_FEES_TAG);
        $revalidator->flush();
        $revalidator->queue(PublicSiteRevalidator::MEMBERSHIP_FEES_TAG);
        $revalidator->flush();

        $nonces = Http::recorded(fn (Request $request) => $request->url() === self::URL)->map(fn (array $pair) => $pair[0]->header('X-Revalidate-Nonce')[0])->all();
        $this->assertCount(2, $nonces);
        $this->assertNotSame($nonces[0], $nonces[1]);
    }

    public function test_flush_with_nothing_pending_makes_no_call(): void
    {
        app(PublicSiteRevalidator::class)->flush();

        Http::assertNothingSent();
    }

    public function test_an_unconfigured_environment_makes_no_http_call_at_all(): void
    {
        config(['services.public_site.revalidate_secret' => null]);
        Http::fake();

        $this->season();
        $this->type('ZZ');
        app()->terminate();

        Http::assertNothingSent();
    }

    public function test_a_hard_delete_of_a_season_and_a_restore_also_revalidate(): void
    {
        $season = $this->season();

        $season->delete();
        app()->terminate();
        $this->assertSame([['membership-seasons']], $this->calls());

        $this->resetCalls();
        $season->restore();
        app()->terminate();
        $this->assertSame([['membership-seasons']], $this->calls());

        $this->resetCalls();
        $season->forceDelete();
        app()->terminate();
        $this->assertSame([['membership-seasons']], $this->calls());
    }

    public function test_creating_an_application_does_not_touch_the_public_cache(): void
    {
        $type = $this->type();
        $season = $this->season(['status' => 'open']);
        $season->membershipTypes()->attach($type->id);
        $this->resetCalls();

        MembershipApplication::query()->create([
            'application_no' => 'APP-X', 'membership_type_id' => $type->id, 'membership_season_id' => $season->id,
            'applicant_name' => 'ক', 'applicant_email' => 'k@example.com', 'applicant_phone' => '0171', 'status' => 'pending',
        ]);
        app()->terminate();

        $this->assertSame([], $this->calls(), 'applicants do not change what the page shows');
    }
}
