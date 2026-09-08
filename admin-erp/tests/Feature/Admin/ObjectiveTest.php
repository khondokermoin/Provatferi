<?php

namespace Tests\Feature\Admin;

use App\Models\Objective;

class ObjectiveTest extends AdminTestCase
{
    private function objective(string $body, int $order, bool $active = true): Objective
    {
        return Objective::query()->create(['body' => $body, 'sort_order' => $order, 'active' => $active]);
    }

    public function test_index_shows_empty_state_when_no_objectives_exist(): void
    {
        $this->actingAs($this->superAdmin())->get(route('admin.content.objectives.index'))
            ->assertOk()->assertSee('এখনো কোনো উদ্দেশ্য নেই');
    }

    public function test_objectives_render_in_sort_order(): void
    {
        $this->objective('Third', 3);
        $this->objective('First', 1);
        $this->objective('Second', 2);

        $html = $this->actingAs($this->superAdmin())
            ->get(route('admin.content.objectives.index'))->getContent();

        $this->assertTrue(
            strpos($html, 'First') < strpos($html, 'Second') && strpos($html, 'Second') < strpos($html, 'Third'),
            'Objectives must render in sort_order, not creation order.',
        );
    }

    public function test_an_objective_can_be_created(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.content.objectives.store'), [
            'body' => 'বই পড়ার সংস্কৃতি গড়ে তোলা।', 'sort_order' => 1, 'active' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('objectives', ['body' => 'বই পড়ার সংস্কৃতি গড়ে তোলা।', 'active' => 1]);
    }

    public function test_creation_requires_a_body(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.content.objectives.store'), ['sort_order' => 1])
            ->assertSessionHasErrors('body');
    }

    public function test_an_objective_can_be_updated(): void
    {
        $objective = $this->objective('Old text', 1);

        $this->actingAs($this->superAdmin())->put(route('admin.content.objectives.update', $objective), [
            'body' => 'Updated text', 'sort_order' => 1, 'active' => '1',
        ])->assertRedirect();

        $this->assertSame('Updated text', $objective->fresh()->body);
    }

    public function test_move_up_swaps_sort_order_with_the_previous_objective(): void
    {
        $first = $this->objective('First', 1);
        $second = $this->objective('Second', 2);

        $this->actingAs($this->superAdmin())->post(route('admin.content.objectives.move-up', $second))->assertRedirect();

        $this->assertSame(1, $second->fresh()->sort_order);
        $this->assertSame(2, $first->fresh()->sort_order);
    }

    public function test_move_down_swaps_sort_order_with_the_next_objective(): void
    {
        $first = $this->objective('First', 1);
        $second = $this->objective('Second', 2);

        $this->actingAs($this->superAdmin())->post(route('admin.content.objectives.move-down', $first))->assertRedirect();

        $this->assertSame(2, $first->fresh()->sort_order);
        $this->assertSame(1, $second->fresh()->sort_order);
    }

    public function test_moving_the_first_item_up_is_a_no_op(): void
    {
        $first = $this->objective('First', 1);
        $this->objective('Second', 2);

        $this->actingAs($this->superAdmin())->post(route('admin.content.objectives.move-up', $first))->assertRedirect();

        $this->assertSame(1, $first->fresh()->sort_order);
    }

    public function test_activation_can_be_toggled(): void
    {
        $objective = $this->objective('Toggle me', 1, active: true);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->patch(route('admin.content.objectives.toggle', $objective))->assertRedirect();
        $this->assertFalse($objective->fresh()->active);

        $this->actingAs($admin)->patch(route('admin.content.objectives.toggle', $objective))->assertRedirect();
        $this->assertTrue($objective->fresh()->active);
    }

    public function test_an_objective_can_be_deleted(): void
    {
        $objective = $this->objective('Delete me', 1);

        $this->actingAs($this->superAdmin())->delete(route('admin.content.objectives.destroy', $objective))
            ->assertRedirect();

        $this->assertDatabaseMissing('objectives', ['id' => $objective->id]);
    }

    public function test_rbac_hides_create_and_delete_without_permission(): void
    {
        $this->objective('Visible', 1);
        $viewer = $this->userWith(['settings.view']);

        $this->actingAs($viewer)->get(route('admin.content.objectives.index'))
            ->assertOk()
            ->assertDontSee(route('admin.content.objectives.create'));

        $this->actingAs($viewer)->post(route('admin.content.objectives.store'), ['body' => 'x', 'sort_order' => 1])
            ->assertForbidden();
    }

    public function test_bengali_objective_round_trips_through_create_and_render(): void
    {
        $text = 'সাহিত্য, সংস্কৃতি ও সৃজনশীল চর্চার বিকাশ ঘটানো।';
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.content.objectives.store'), [
            'body' => $text, 'sort_order' => 1, 'active' => '1',
        ])->assertRedirect();

        $objective = Objective::query()->firstOrFail();
        $this->assertSame($text, $objective->body);
        $this->assertTrue(mb_check_encoding($objective->body, 'UTF-8'));

        $this->actingAs($admin)->get(route('admin.content.objectives.index'))
            ->assertOk()->assertSee($text, false);
    }
}
