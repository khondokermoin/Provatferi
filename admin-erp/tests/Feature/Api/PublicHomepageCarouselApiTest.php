<?php

namespace Tests\Feature\Api;

use App\Models\HomepageCarouselSlide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicHomepageCarouselApiTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $attributes */
    private function slide(array $attributes = []): HomepageCarouselSlide
    {
        return HomepageCarouselSlide::query()->create(array_merge([
            'image_path' => 'homepage-carousel/'.uniqid().'.jpg',
            'sort_order' => 1,
            'status' => 'active',
        ], $attributes));
    }

    public function test_only_active_slides_are_listed(): void
    {
        $this->slide(['title' => 'Active one', 'sort_order' => 1]);
        $this->slide(['title' => 'Inactive one', 'status' => 'inactive', 'sort_order' => 2]);

        $titles = collect($this->getJson('/api/v1/public/homepage-carousel')->assertOk()->json('data'))->pluck('title');

        $this->assertSame(['Active one'], $titles->all());
    }

    public function test_slides_are_ordered_by_sort_order(): void
    {
        $this->slide(['title' => 'Third', 'sort_order' => 3]);
        $this->slide(['title' => 'First', 'sort_order' => 1]);
        $this->slide(['title' => 'Second', 'sort_order' => 2]);

        $titles = collect($this->getJson('/api/v1/public/homepage-carousel')->json('data'))->pluck('title')->all();

        $this->assertSame(['First', 'Second', 'Third'], $titles);
    }

    public function test_the_response_shape_includes_every_bilingual_and_link_field(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('homepage-carousel/real.jpg', 'bytes');
        $this->slide([
            'image_path' => 'homepage-carousel/real.jpg',
            'title' => 'শিরোনাম', 'title_en' => 'Heading',
            'alt_text' => 'বিবরণ', 'alt_text_en' => 'Description',
            'link_url' => 'https://provatferi.org/activities',
            'link_label' => 'আরও দেখুন', 'link_label_en' => 'See more',
        ]);

        $slide = $this->getJson('/api/v1/public/homepage-carousel')->assertOk()->json('data.0');

        $this->assertArrayHasKey('id', $slide);
        $this->assertStringContainsString('homepage-carousel/real.jpg', $slide['image_url']);
        $this->assertSame('শিরোনাম', $slide['title']);
        $this->assertSame('Heading', $slide['title_en']);
        $this->assertSame('বিবরণ', $slide['alt_text']);
        $this->assertSame('Description', $slide['alt_text_en']);
        $this->assertSame('https://provatferi.org/activities', $slide['link_url']);
        $this->assertSame('আরও দেখুন', $slide['link_label']);
        $this->assertSame('See more', $slide['link_label_en']);
    }

    public function test_a_slide_with_no_link_has_null_link_fields_not_missing_keys(): void
    {
        $this->slide();

        $slide = $this->getJson('/api/v1/public/homepage-carousel')->assertOk()->json('data.0');

        $this->assertArrayHasKey('link_url', $slide);
        $this->assertNull($slide['link_url']);
        $this->assertNull($slide['link_label']);
    }

    public function test_an_empty_carousel_returns_an_empty_array_not_an_error(): void
    {
        $this->getJson('/api/v1/public/homepage-carousel')
            ->assertOk()
            ->assertJson(['data' => []]);
    }
}
