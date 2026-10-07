<?php

namespace Tests\Feature\Admin;

use App\Models\MembershipSeason;
use App\Models\MembershipType;
use Tests\Concerns\MakesMembershipTypes;

/**
 * Membership task 4, step 0 (2026-10-08): a membership type is offered for self-service applications only when
 * everything approval needs is configured — today a valid member-number code and a fee policy in force. Generic: the
 * rule names no type. An incomplete type keeps every setting (status, visibility, self-apply switch, fees); it is simply
 * not offered, and the admin sees "Configuration incomplete" and why.
 */
class MembershipTypeReadinessTest extends AdminTestCase
{
    use MakesMembershipTypes;

    private function type(?string $code, string $name): MembershipType
    {
        return $this->makeMembershipType(
            ['name' => $name, 'code' => $code, 'slug' => 'ready-'.uniqid(), 'is_public_self_apply' => true, 'is_public_visible' => true],
            ['registration' => '0', 'monthly' => '0'],
        );
    }

    public function test_a_type_without_a_member_number_code_is_not_offered_and_keeps_its_settings(): void
    {
        $ready = $this->type('GM', 'সাধারণ সদস্য');
        $incomplete = $this->type(null, 'সম্মানসূচক সদস্য');
        $season = MembershipSeason::query()->create(['name' => 'সিজন', 'slug' => 'season-'.uniqid(), 'campaign_type' => 'regular', 'status' => 'open', 'display_order' => 0]);
        $season->membershipTypes()->sync([$ready->id, $incomplete->id]);

        $offered = collect($this->getJson('/api/v1/public/membership/campaigns/current')->assertOk()->json('data.0.membership_types'))->pluck('id')->all();
        $this->assertSame([$ready->id], $offered, 'only the complete type is offered in the application form');

        $this->postJson('/api/v1/public/membership/applications', [
            'applicant_name' => 'ক', 'applicant_email' => 'k@example.com', 'applicant_phone' => '01700000000',
            'membership_type_id' => $incomplete->id, 'membership_season_id' => $season->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('membership_type_id');

        $public = collect($this->getJson('/api/v1/membership-types')->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($public[$ready->id]['is_public_self_apply']);
        $this->assertFalse($public[$incomplete->id]['is_public_self_apply'], 'shown as information, not offered for self-service applications');

        $incomplete->refresh();
        $this->assertSame(['active', true, true], [$incomplete->status, $incomplete->is_public_visible, $incomplete->is_public_self_apply], 'nothing about the type itself was changed');
        $this->assertSame(['code'], $incomplete->configurationProblems());
        $this->assertFalse($incomplete->offersSelfApply());
        $this->assertTrue($ready->offersSelfApply());
    }

    public function test_the_admin_sees_configuration_incomplete_and_why_in_bangla_and_english(): void
    {
        $admin = $this->superAdmin();
        $incomplete = $this->type(null, 'সম্মানসূচক সদস্য');
        $this->type('LM', 'আজীবন সদস্য');

        $this->actingAs($admin)->get(route('admin.membership.types.index'))->assertOk()
            ->assertSee('কনফিগারেশন অসম্পূর্ণ')->assertSee('data-testid="type-config-incomplete"', false);
        $this->actingAs($admin)->get(route('admin.membership.types.show', $incomplete))->assertOk()
            ->assertSee('data-testid="config-problem-code"', false)->assertDontSee('data-testid="config-problem-fee_policy"', false);

        $admin->forceFill(['ui_locale' => 'en'])->save();
        $this->actingAs($admin)->get(route('admin.membership.types.show', $incomplete))->assertOk()
            ->assertSee('Configuration incomplete')->assertSee('No valid member-number code')->assertDontSee('কনফিগারেশন অসম্পূর্ণ');
    }

    public function test_a_type_becomes_offered_once_it_is_complete_and_the_rule_is_generic(): void
    {
        $season = MembershipSeason::query()->create(['name' => 'সিজন', 'slug' => 'season-'.uniqid(), 'campaign_type' => 'regular', 'status' => 'open', 'display_order' => 0]);
        $future = $this->type(null, 'ভবিষ্যতের ধরন');
        $season->membershipTypes()->sync([$future->id]);
        $this->assertSame([], $this->getJson('/api/v1/public/membership/campaigns/current')->json('data.0.membership_types'));

        $future->update(['code' => 'FT']);
        $this->assertSame([$future->id], collect($this->getJson('/api/v1/public/membership/campaigns/current')->json('data.0.membership_types'))->pluck('id')->all());

        $noPolicy = $this->makeMembershipType(['name' => 'নীতিহীন', 'code' => 'NP', 'slug' => 'np-'.uniqid()], null);
        $this->assertSame(['fee_policy'], $noPolicy->configurationProblems(), 'a missing fee policy is incomplete configuration too');
    }
}
