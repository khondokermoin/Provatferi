<?php

namespace Tests\Feature\Api;

use App\Models\MembershipSeason;
use App\Models\MembershipType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesMembershipTypes;
use Tests\TestCase;

/**
 * 2026-10-05, membership public cache: every public membership answer says WHEN it can change by itself
 * (`meta.valid_until`), so the public site never serves a cached copy past that instant — no cron, no polling.
 *
 * The properties that make that safe, each pinned below:
 *   - it names the FIRST such instant: a season opening or closing, a fee policy starting or ending at midnight on the
 *     ORGANISATION's calendar (Asia/Dhaka, so 18:00 UTC the evening before), whichever comes first;
 *   - the answer really DOES change at that instant (the test asks the API a second before and at the instant);
 *   - only things the clock alone can change count — closed/draft seasons and cancelled policies need an admin, who
 *     announces it instead;
 *   - the campaigns lookup depends on season windows, the type list does not;
 *   - with nothing scheduled it is null.
 *
 * The frozen clock is 10:00 UTC on 2026-10-05 = 16:00 in Dhaka.
 */
class MembershipPublicValidityTest extends TestCase
{
    use MakesMembershipTypes;
    use RefreshDatabase;

    private const NOW = '2026-10-05 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::NOW);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function type(string $code = 'GM'): MembershipType
    {
        return $this->makeMembershipType(['name' => 'সাধারণ সদস্য', 'slug' => 'general-'.uniqid(), 'code' => $code], ['registration' => '100', 'monthly' => '0']);
    }

    private function season(string $status, ?string $opens = null, ?string $closes = null, ?MembershipType $offering = null): MembershipSeason
    {
        $season = MembershipSeason::query()->create([
            'name' => 'সিজন '.uniqid(), 'slug' => 'season-'.uniqid(), 'campaign_type' => 'regular', 'status' => $status, 'display_order' => 0,
            'opens_at' => $opens, 'closes_at' => $closes,
        ]);
        if ($offering !== null) {
            $season->membershipTypes()->attach($offering->id);
        }

        return $season;
    }

    private function campaigns(): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/public/membership/campaigns/current')->assertOk();
    }

    private function types(): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/membership-types')->assertOk();
    }

    public function test_with_nothing_scheduled_no_instant_is_named(): void
    {
        $this->type();

        $this->campaigns()->assertJsonPath('meta.valid_until', null)->assertJsonPath('meta.generated_at', '2026-10-05T10:00:00+00:00');
        $this->types()->assertJsonPath('meta.valid_until', null)->assertJsonPath('meta.generated_at', '2026-10-05T10:00:00+00:00');
    }

    public function test_a_season_that_opens_later_expires_the_answer_at_its_opening_but_not_the_type_list(): void
    {
        $type = $this->type();
        $this->season('open', '2026-10-05 12:00:00', null, $type);

        $this->campaigns()->assertJsonPath('data', [])->assertJsonPath('meta.valid_until', '2026-10-05T12:00:00+00:00');
        $this->types()->assertJsonPath('meta.valid_until', null);
    }

    public function test_an_open_season_that_closes_later_expires_the_answer_at_its_closing(): void
    {
        $type = $this->type();
        $this->season('open', '2026-10-05 08:00:00', '2026-10-05 11:30:00', $type);

        $this->campaigns()->assertJsonCount(1, 'data')->assertJsonPath('meta.valid_until', '2026-10-05T11:30:00+00:00');
    }

    public function test_the_earliest_of_several_boundaries_wins(): void
    {
        $type = $this->type();
        $this->season('open', '2026-10-05 13:00:00', null, $type);       // opens at 13:00
        $this->season('open', '2026-10-05 08:00:00', '2026-10-05 12:00:00', $type); // closes at 12:00
        $this->season('open', '2026-10-05 09:00:00', '2026-10-06 09:00:00', $type); // closes tomorrow

        $this->campaigns()->assertJsonPath('meta.valid_until', '2026-10-05T12:00:00+00:00');
    }

    public function test_seasons_an_admin_has_not_opened_or_that_already_ended_create_no_boundary(): void
    {
        $type = $this->type();
        $this->season('draft', '2026-10-05 12:00:00', '2026-10-05 13:00:00', $type);
        $this->season('scheduled', '2026-10-05 12:00:00', '2026-10-05 13:00:00', $type);
        $this->season('closed', '2026-10-05 12:00:00', '2026-10-05 13:00:00', $type);
        $this->season('archived', '2026-10-05 12:00:00', '2026-10-05 13:00:00', $type);
        $this->season('open', '2026-10-01 00:00:00', '2026-10-04 00:00:00', $type); // marked open, but its window has passed

        $this->campaigns()->assertJsonPath('data', [])->assertJsonPath('meta.valid_until', null);
    }

    public function test_a_future_fee_policy_starts_at_midnight_on_the_organisations_calendar(): void
    {
        $type = $this->type();
        $this->makeFeePolicy($type, '200', '50', '2026-10-06'); // tomorrow in Dhaka

        // 00:00 on 6 October in Dhaka (UTC+6) is 18:00 UTC on the 5th
        $this->types()->assertJsonPath('meta.valid_until', '2026-10-05T18:00:00+00:00');
        $this->campaigns()->assertJsonPath('meta.valid_until', '2026-10-05T18:00:00+00:00');
    }

    public function test_the_last_day_of_an_ending_policy_is_a_boundary_too(): void
    {
        $type = $this->type();
        $type->feePolicies()->first()->update(['effective_until' => '2026-10-05']); // runs through today only

        $this->types()->assertJsonPath('meta.valid_until', '2026-10-05T18:00:00+00:00');
    }

    public function test_a_cancelled_policy_or_one_long_over_creates_no_boundary(): void
    {
        $type = $this->type();
        $this->makeFeePolicy($type, '1', '1', '2026-12-01', null, false);          // cancelled, in the future
        $this->makeFeePolicy($type, '2', '2', '2001-01-01', '2001-12-31');          // long over

        $this->types()->assertJsonPath('meta.valid_until', null);
    }

    public function test_the_answer_really_changes_at_the_instant_it_names_fee_policy(): void
    {
        $type = $this->type();
        $this->makeFeePolicy($type, '250', '75', '2026-10-06');
        $first = $this->types();
        $until = $first->json('meta.valid_until');
        $this->assertSame('2026-10-05T18:00:00+00:00', $until);
        $this->assertSame('100.00', $first->json('data.0.registration_fee'));

        Carbon::setTestNow(Carbon::parse($until)->subSecond());
        $this->assertSame('100.00', $this->types()->json('data.0.registration_fee'), 'one second before: unchanged');

        Carbon::setTestNow(Carbon::parse($until));
        $after = $this->types();
        $this->assertSame('250.00', $after->json('data.0.registration_fee'), 'at the instant: the new fee');
        $this->assertSame('75.00', $after->json('data.0.monthly_contribution'));
        $after->assertJsonPath('meta.valid_until', null); // and nothing further is scheduled
    }

    public function test_the_answer_really_changes_at_the_instant_it_names_season_opening_and_closing(): void
    {
        $type = $this->type();
        $this->season('open', '2026-10-05 12:00:00', '2026-10-05 14:00:00', $type);

        $before = $this->campaigns();
        $opens = $before->json('meta.valid_until');
        $this->assertSame('2026-10-05T12:00:00+00:00', $opens);
        $this->assertSame([], $before->json('data'));

        Carbon::setTestNow(Carbon::parse($opens)->subSecond());
        $this->assertSame([], $this->campaigns()->json('data'), 'one second before it opens: still no season');

        Carbon::setTestNow(Carbon::parse($opens));
        $open = $this->campaigns();
        $this->assertCount(1, $open->json('data'), 'at the instant it opens: the season is there');
        $closes = $open->json('meta.valid_until');
        $this->assertSame('2026-10-05T14:00:00+00:00', $closes, 'and the next thing to watch is its closing');

        Carbon::setTestNow(Carbon::parse($closes)->addSecond());
        $this->assertSame([], $this->campaigns()->json('data'), 'one second after it closes: gone');
        $this->campaigns()->assertJsonPath('meta.valid_until', null);
    }

    public function test_the_application_endpoint_and_the_public_list_never_disagree_about_the_clock(): void
    {
        $type = $this->type();
        $season = $this->season('open', '2026-10-05 12:00:00', '2026-10-05 14:00:00', $type);
        $post = fn () => $this->postJson('/api/v1/public/membership/applications', [
            'applicant_name' => 'ক', 'applicant_email' => 'k@example.com', 'applicant_phone' => '01700000000',
            'membership_type_id' => $type->id, 'membership_season_id' => $season->id,
        ]);

        foreach (['2026-10-05 11:59:59' => false, '2026-10-05 12:00:00' => true, '2026-10-05 14:00:00' => true, '2026-10-05 14:00:01' => false] as $moment => $open) {
            Carbon::setTestNow($moment);
            $listed = count($this->campaigns()->json('data')) === 1;
            $accepted = $post()->status() === 201;
            $this->assertSame($open, $listed, "listed at $moment");
            $this->assertSame($listed, $accepted, "the form is offered exactly when the endpoint accepts, at $moment");
        }
    }
}
