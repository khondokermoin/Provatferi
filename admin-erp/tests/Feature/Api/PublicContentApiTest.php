<?php

namespace Tests\Feature\Api;

use App\Models\Objective;
use Database\Seeders\ObjectivesSeeder;
use Database\Seeders\SiteContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicContentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SiteContentSeeder::class);
    }

    public function test_settings_endpoint_returns_grouped_structured_data(): void
    {
        $response = $this->getJson('/api/v1/settings')->assertOk();

        $response->assertJsonStructure(['data' => [
            'organization' => ['name_bn', 'name_en', 'short_name', 'acronym', 'tagline'],
            'contact' => ['email', 'phone', 'address', 'facebook_url'],
            'seo' => ['title', 'description', 'alternate_names', 'canonical_url'],
            'links' => ['website_url', 'literature_url'],
        ]]);

        $response->assertJsonPath('data.contact.email', 'info@provatferi.org');
        $response->assertJsonPath('data.contact.phone', '+8801625050408');
        $response->assertJsonPath('data.seo.alternate_names', ['PLCC', 'প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র', 'Provatferi']);
    }

    public function test_settings_endpoint_never_exposes_the_old_gmail(): void
    {
        $body = $this->getJson('/api/v1/settings')->getContent();
        $this->assertStringNotContainsString('provatferi2019@gmail.com', $body);
    }

    public function test_about_endpoint_returns_about_mission_vision_and_ordered_objectives(): void
    {
        $this->seed(ObjectivesSeeder::class);

        $response = $this->getJson('/api/v1/about')->assertOk();

        $response->assertJsonStructure(['data' => ['about', 'mission', 'vision', 'objectives']]);
        $this->assertNotEmpty($response->json('data.about.description'));
        $this->assertNotEmpty($response->json('data.mission.body'));
        $this->assertNotEmpty($response->json('data.vision.body'));

        $objectives = $response->json('data.objectives');
        $this->assertCount(12, $objectives);

        $sortOrders = array_column($objectives, 'sort_order');
        $sorted = $sortOrders;
        sort($sorted);
        $this->assertSame($sorted, $sortOrders, 'Objectives must already be in display order.');
    }

    public function test_inactive_objectives_are_excluded_from_the_public_api(): void
    {
        Objective::query()->create(['body' => 'Hidden one', 'sort_order' => 1, 'active' => false]);
        Objective::query()->create(['body' => 'Visible one', 'sort_order' => 2, 'active' => true]);

        $bodies = $this->getJson('/api/v1/about')->json('data.objectives.*.body');

        $this->assertContains('Visible one', $bodies);
        $this->assertNotContains('Hidden one', $bodies);
    }

    public function test_unpublished_about_page_returns_null_rather_than_draft_content(): void
    {
        \App\Models\AboutPage::current()->update(['is_published' => false, 'description' => 'Draft text']);

        $this->getJson('/api/v1/about')->assertJsonPath('data.about', null);
    }

    public function test_bengali_content_round_trips_through_the_json_api(): void
    {
        $response = $this->getJson('/api/v1/settings')->assertOk();
        $nameBn = $response->json('data.organization.name_bn');

        $this->assertSame('প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র', $nameBn);
        $this->assertTrue(mb_check_encoding($nameBn, 'UTF-8'));
    }
}
