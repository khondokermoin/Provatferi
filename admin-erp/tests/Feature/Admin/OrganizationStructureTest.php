<?php

namespace Tests\Feature\Admin;

use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\OrganizationalPosition;
use App\Models\OrganizationalUnit;
use App\Models\User;

class OrganizationStructureTest extends AdminTestCase
{
    private function unit(string $name = 'Central'): OrganizationalUnit
    {
        return OrganizationalUnit::query()->create([
            'name' => $name, 'slug' => str($name)->slug()->__toString(),
            'unit_type' => 'central', 'status' => 'active',
        ]);
    }

    /* ---------- Positions ---------- */

    public function test_positions_start_empty_and_can_be_created(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('admin.positions.index'))
            ->assertOk()->assertSee('এখনো কোনো পদ নেই');

        $this->actingAs($admin)->post(route('admin.positions.store'), [
            'name' => 'সভাপতি',
            'organization_unit_id' => $this->unit()->id,
            'level' => 1,
            'status' => 'active',
        ])->assertRedirect();

        $this->assertDatabaseHas('organizational_positions', ['name' => 'সভাপতি', 'level' => 1, 'status' => 'active']);
    }

    public function test_position_validation(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.positions.store'), [
            'name' => '', 'level' => -5, 'status' => 'bogus',
        ])->assertSessionHasErrors(['name', 'level', 'status']);
    }

    public function test_a_position_used_by_a_committee_member_cannot_be_deleted(): void
    {
        $unit = $this->unit();
        $position = OrganizationalPosition::query()->create([
            'name' => 'Secretary', 'slug' => 'secretary', 'level' => 2, 'status' => 'active',
        ]);
        $committee = Committee::query()->create([
            'organization_unit_id' => $unit->id, 'name' => 'Exec', 'status' => 'active',
        ]);
        CommitteeMember::query()->create([
            'committee_id' => $committee->id,
            'user_id' => User::factory()->create()->id,
            'position_id' => $position->id,
            'status' => 'active',
        ]);

        $this->actingAs($this->superAdmin())->delete(route('admin.positions.destroy', $position))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseHas('organizational_positions', ['id' => $position->id]);
    }

    /* ---------- Committees ---------- */

    public function test_committees_start_empty_with_no_fabricated_records(): void
    {
        $this->assertSame(0, Committee::query()->count());

        $this->actingAs($this->superAdmin())->get(route('admin.committees.index'))
            ->assertOk()->assertSee('এখনো কোনো কমিটি নেই');
    }

    public function test_a_committee_can_be_created_and_shown(): void
    {
        $unit = $this->unit();

        $this->actingAs($this->superAdmin())->post(route('admin.committees.store'), [
            'name' => 'নির্বাহী কমিটি ২০২৬',
            'organization_unit_id' => $unit->id,
            'committee_type' => 'executive',
            'term_start' => '2026-01-01',
            'term_end' => '2028-12-31',
            'status' => 'active',
        ])->assertRedirect();

        $committee = Committee::query()->firstOrFail();
        $this->assertSame('নির্বাহী কমিটি ২০২৬', $committee->name);

        $this->actingAs($this->superAdmin())->get(route('admin.committees.show', $committee))
            ->assertOk()->assertSee('নির্বাহী কমিটি ২০২৬', false)
            ->assertSee('এই কমিটিতে এখনো কোনো সদস্য নেই');
    }

    public function test_committee_term_end_must_not_precede_start(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.committees.store'), [
            'name' => 'Bad Term',
            'organization_unit_id' => $this->unit()->id,
            'term_start' => '2026-06-01',
            'term_end' => '2026-01-01',
            'status' => 'draft',
        ])->assertSessionHasErrors('term_end');
    }

    /* ---------- Committee members ---------- */

    public function test_a_member_can_be_added_and_appears_on_the_committee(): void
    {
        $committee = Committee::query()->create([
            'organization_unit_id' => $this->unit()->id, 'name' => 'Exec', 'status' => 'active',
        ]);
        $position = OrganizationalPosition::query()->create([
            'name' => 'President', 'slug' => 'president', 'level' => 1, 'status' => 'active',
        ]);
        $person = User::factory()->create(['name' => 'Mehedi Hasan']);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.committees.members.store', $committee), [
                'user_id' => $person->id,
                'position_id' => $position->id,
                'serial_no' => 1,
                'status' => 'active',
            ])->assertRedirect(route('admin.committees.show', $committee));

        $this->actingAs($this->superAdmin())->get(route('admin.committees.show', $committee))
            ->assertOk()->assertSee('Mehedi Hasan')->assertSee('President');
    }

    public function test_the_same_person_cannot_hold_two_seats_on_one_committee(): void
    {
        $committee = Committee::query()->create([
            'organization_unit_id' => $this->unit()->id, 'name' => 'Exec', 'status' => 'active',
        ]);
        $position = OrganizationalPosition::query()->create([
            'name' => 'President', 'slug' => 'president', 'level' => 1, 'status' => 'active',
        ]);
        $person = User::factory()->create();

        $payload = ['user_id' => $person->id, 'position_id' => $position->id, 'status' => 'active'];

        $this->actingAs($this->superAdmin())
            ->post(route('admin.committees.members.store', $committee), $payload)->assertRedirect();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.committees.members.store', $committee), $payload)
            ->assertSessionHasErrors('user_id');

        $this->assertSame(1, $committee->members()->count());
    }

    public function test_a_committee_with_members_cannot_be_deleted(): void
    {
        $committee = Committee::query()->create([
            'organization_unit_id' => $this->unit()->id, 'name' => 'Exec', 'status' => 'active',
        ]);
        $position = OrganizationalPosition::query()->create([
            'name' => 'President', 'slug' => 'president', 'level' => 1, 'status' => 'active',
        ]);
        CommitteeMember::query()->create([
            'committee_id' => $committee->id, 'user_id' => User::factory()->create()->id,
            'position_id' => $position->id, 'status' => 'active',
        ]);

        $this->actingAs($this->superAdmin())->delete(route('admin.committees.destroy', $committee))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseHas('committees', ['id' => $committee->id]);
    }

    public function test_members_of_another_committee_are_not_reachable(): void
    {
        $unit = $this->unit();
        $a = Committee::query()->create(['organization_unit_id' => $unit->id, 'name' => 'A', 'status' => 'active']);
        $b = Committee::query()->create(['organization_unit_id' => $unit->id, 'name' => 'B', 'status' => 'active']);
        $position = OrganizationalPosition::query()->create([
            'name' => 'President', 'slug' => 'president', 'level' => 1, 'status' => 'active',
        ]);
        $member = CommitteeMember::query()->create([
            'committee_id' => $a->id, 'user_id' => User::factory()->create()->id,
            'position_id' => $position->id, 'status' => 'active',
        ]);

        // Member belongs to A, so reaching it through B must 404.
        $this->actingAs($this->superAdmin())
            ->get(route('admin.committees.members.edit', [$b, $member]))
            ->assertNotFound();
    }

    public function test_rbac_guards_organization_structure(): void
    {
        $viewer = $this->userWith(['organization.view']);

        $this->actingAs($viewer)->get(route('admin.positions.index'))->assertOk();
        $this->actingAs($viewer)->get(route('admin.positions.create'))->assertForbidden();
        $this->actingAs($viewer)->get(route('admin.committees.create'))->assertForbidden();

        $outsider = $this->userWith(['activities.view']);
        $this->actingAs($outsider)->get(route('admin.committees.index'))->assertForbidden();
    }
}
