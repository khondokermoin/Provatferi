<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Committee;
use App\Models\JobPosting;
use App\Models\MembershipSeason;
use App\Models\MembershipType;
use App\Models\Notice;
use App\Models\Objective;
use App\Models\OrganizationalPosition;
use App\Models\OrganizationalUnit;
use Database\Seeders\SiteContentSeeder;

/**
 * Phase 3 Step 2/3/6: the reusable [বাংলা] [English] tabbed content editor
 * (components/admin/bilingual-field.blade.php), applied to every admin form
 * with a `_en` field, replacing the old stacked "field" / "field (English)"
 * layout. Covers: every form actually renders the tab markup (a real
 * Blade-compile smoke test — BilingualFieldsValidationTest only exercises
 * POST/PUT, never GET, so it never caught a template error here), the
 * missing-translation indicator appears/disappears correctly, and a
 * validation error on the English side opens the English tab instead of
 * leaving it hidden behind the (default) Bangla tab.
 */
class BilingualEditorTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SiteContentSeeder::class);
    }

    private function unit(): OrganizationalUnit
    {
        return OrganizationalUnit::query()->create([
            'name' => 'কেন্দ্রীয় ইউনিট', 'slug' => 'unit-'.uniqid(), 'unit_type' => 'central', 'status' => 'active',
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function bilingualFormRoutes(): array
    {
        return [
            'activity create' => ['admin.activities.create'],
            'activity type create' => ['admin.activities.types.create'],
            'committee create' => ['admin.committees.create'],
            'about edit' => ['admin.content.about.edit'],
            'mission edit' => ['admin.content.mission.edit'],
            'vision edit' => ['admin.content.vision.edit'],
            'objective create' => ['admin.content.objectives.create'],
            'membership season create' => ['admin.membership.seasons.create'],
            'membership type create' => ['admin.membership.types.create'],
            'notice create' => ['admin.notices.create'],
            'organization unit create' => ['admin.organization.units.create'],
            'position create' => ['admin.positions.create'],
            'recruitment create' => ['admin.recruitment.create'],
        ];
    }

    /** @dataProvider bilingualFormRoutes */
    public function test_every_bilingual_admin_form_renders_the_tabbed_editor(string $routeName): void
    {
        $html = $this->actingAs($this->superAdmin())->get(route($routeName))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('pf-bilingual-field', $html);
        $this->assertStringContainsString('nav-tabs', $html);
        $this->assertStringContainsString('data-bs-toggle="tab"', $html);
        $this->assertStringContainsString('role="tablist"', $html);
    }

    public function test_bilingual_editor_marks_an_empty_english_side_as_missing(): void
    {
        // SetAdminLocale resolves from the signed-in user's own ui_locale column
        // first — a manual App::setLocale() call would be overwritten by that
        // middleware once the request actually runs.
        $admin = $this->superAdmin();
        $admin->update(['ui_locale' => 'en']);
        $type = ActivityType::query()->create(['name' => 'পাঠচক্র', 'slug' => 'type-'.uniqid(), 'status' => 'active']);
        $activity = Activity::query()->create([
            'activity_type_id' => $type->id, 'title' => 'কার্যক্রম', 'slug' => 'activity-'.uniqid(), 'status' => 'draft', 'participant_count' => 0,
        ]);

        $html = $this->actingAs($admin)->get(route('admin.activities.edit', $activity))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No English translation', $html);
    }

    public function test_bilingual_editor_does_not_mark_a_filled_english_side_as_missing(): void
    {
        $admin = $this->superAdmin();
        $admin->update(['ui_locale' => 'en']);
        $type = ActivityType::query()->create(['name' => 'পাঠচক্র', 'slug' => 'type-'.uniqid(), 'status' => 'active', 'name_en' => 'Reading Circle']);

        $html = $this->actingAs($admin)->get(route('admin.activities.types.edit', $type))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('No English translation', $html);
    }

    public function test_a_validation_error_on_the_english_field_opens_the_english_tab(): void
    {
        $admin = $this->superAdmin();
        $type = ActivityType::query()->create(['name' => 'পাঠচক্র', 'slug' => 'type-'.uniqid(), 'status' => 'active']);
        $activity = Activity::query()->create([
            'activity_type_id' => $type->id, 'title' => 'কার্যক্রম', 'slug' => 'activity-'.uniqid(), 'status' => 'draft', 'participant_count' => 0,
        ]);
        $editUrl = route('admin.activities.edit', $activity);

        // title_en accepts a string up to 255 chars (nullable|string|max:255) —
        // a 300-char string fails validation on that one field only, while
        // every other required field stays valid. A plain over-length string
        // (rather than e.g. an array) keeps old()'s redisplay of the value
        // itself valid, isolating this test to the tab-selection behaviour.
        $this->actingAs($admin)
            ->from($editUrl)
            ->put(route('admin.activities.update', $activity), [
                'activity_type_id' => $type->id,
                'title' => 'কার্যক্রম',
                'title_en' => str_repeat('x', 300),
                'status' => 'draft',
                'participant_count' => 0,
            ])
            ->assertRedirect($editUrl)
            ->assertSessionHasErrors('title_en');

        $html = $this->actingAs($admin)->get($editUrl)->assertOk()->getContent();

        // The English pane for the "title" field must be the active/shown one —
        // never left hidden behind the default Bangla tab with the error inside it.
        $this->assertMatchesRegularExpression(
            '/class="tab-pane fade show active"\s+id="bf-title-en-pane"/',
            $html,
        );
    }

    public function test_a_validation_error_on_the_bangla_field_keeps_the_bangla_tab_open(): void
    {
        $admin = $this->superAdmin();
        $editUrl = route('admin.activities.types.create');

        // 'name' is required — omitting it fails validation on the Bangla side only.
        $this->actingAs($admin)
            ->from($editUrl)
            ->post(route('admin.activities.types.store'), [
                'status' => 'active', 'sort_order' => 1,
            ])
            ->assertRedirect($editUrl)
            ->assertSessionHasErrors('name');

        $html = $this->actingAs($admin)->get($editUrl)->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/class="tab-pane fade show active"\s+id="bf-name-bn-pane"/',
            $html,
        );
    }

    public function test_both_language_inputs_are_always_present_in_the_dom(): void
    {
        $html = $this->actingAs($this->superAdmin())->get(route('admin.positions.create'))
            ->assertOk()
            ->getContent();

        // Both fields exist in the DOM at once (never conditionally rendered per
        // tab) — this is what guarantees switching tabs can never lose input.
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringContainsString('name="name_en"', $html);
    }
}
