<?php

namespace Tests\Feature\Admin;

use App\Models\HomepageCarouselSlide;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 4: the admin-managed homepage carousel. Mirrors ObjectiveTest's own
 * shape (sort_order + move-up/move-down + a status toggle, reusing the
 * 'settings' permission module rather than inventing one per page — see
 * Permission::MODULES's own docblock) with an image upload on top, following
 * NoticeShareImageTest's Storage::fake() convention for the private-disk
 * half and adding 'public' since a slide's image is promoted there
 * immediately (no separate approval step exists for this feature).
 */
class HomepageCarouselTest extends AdminTestCase
{
    private function fakeStorage(): void
    {
        Storage::fake('uploads_private');
        Storage::fake('public');
    }

    private function slide(array $overrides = []): HomepageCarouselSlide
    {
        return HomepageCarouselSlide::query()->create(array_merge([
            'image_path' => 'homepage-carousel/'.uniqid().'.jpg',
            'sort_order' => 1,
            'status' => 'active',
        ], $overrides));
    }

    public function test_index_shows_empty_state_when_no_slides_exist(): void
    {
        $this->actingAs($this->superAdmin())->get(route('admin.homepage-carousel.index'))
            ->assertOk()->assertSee('এখনো কোনো স্লাইড নেই');
    }

    public function test_slides_render_in_sort_order(): void
    {
        $this->slide(['sort_order' => 3, 'title' => 'Third']);
        $this->slide(['sort_order' => 1, 'title' => 'First']);
        $this->slide(['sort_order' => 2, 'title' => 'Second']);

        $html = $this->actingAs($this->superAdmin())
            ->get(route('admin.homepage-carousel.index'))->getContent();

        $this->assertTrue(
            strpos($html, 'First') < strpos($html, 'Second') && strpos($html, 'Second') < strpos($html, 'Third'),
            'Slides must render in sort_order, not creation order.',
        );
    }

    public function test_a_slide_can_be_created_with_an_image(): void
    {
        $this->fakeStorage();

        $this->actingAs($this->superAdmin())->post(route('admin.homepage-carousel.store'), [
            'image' => UploadedFile::fake()->image('slide.jpg', 1600, 700),
            'title' => 'স্বাগতম', 'title_en' => 'Welcome',
            'sort_order' => 1, 'status' => 'active',
        ])->assertRedirect();

        $slide = HomepageCarouselSlide::query()->firstOrFail();
        $this->assertSame('স্বাগতম', $slide->title);
        $this->assertSame('Welcome', $slide->title_en);
        $this->assertStringStartsWith('homepage-carousel/', $slide->image_path);
        Storage::disk('public')->assertExists($slide->image_path);
        // The private original is deleted once promoted — this feature has
        // no separate approval step that would need it kept around.
        Storage::disk('uploads_private')->assertDirectoryEmpty('homepage-carousel');
    }

    public function test_creation_requires_an_image(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.homepage-carousel.store'), [
            'sort_order' => 1, 'status' => 'active',
        ])->assertSessionHasErrors('image');
    }

    public function test_a_link_url_without_a_label_is_rejected(): void
    {
        $this->fakeStorage();

        $this->actingAs($this->superAdmin())->post(route('admin.homepage-carousel.store'), [
            'image' => UploadedFile::fake()->image('slide.jpg'),
            'link_url' => 'https://provatferi.org/activities',
            'sort_order' => 1, 'status' => 'active',
        ])->assertSessionHasErrors('link_label');
    }

    public function test_a_link_label_without_a_url_is_rejected(): void
    {
        $this->fakeStorage();

        $this->actingAs($this->superAdmin())->post(route('admin.homepage-carousel.store'), [
            'image' => UploadedFile::fake()->image('slide.jpg'),
            'link_label' => 'See more', 'link_label_en' => 'See more',
            'sort_order' => 1, 'status' => 'active',
        ])->assertSessionHasErrors('link_url');
    }

    public function test_a_slide_with_both_link_fields_is_accepted(): void
    {
        $this->fakeStorage();

        $this->actingAs($this->superAdmin())->post(route('admin.homepage-carousel.store'), [
            'image' => UploadedFile::fake()->image('slide.jpg'),
            'link_url' => 'https://provatferi.org/activities',
            'link_label' => 'আরও দেখুন', 'link_label_en' => 'See more',
            'sort_order' => 1, 'status' => 'active',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $slide = HomepageCarouselSlide::query()->firstOrFail();
        $this->assertTrue($slide->hasLink());
    }

    public function test_updating_without_a_new_image_keeps_the_existing_one(): void
    {
        $this->fakeStorage();
        Storage::disk('public')->put('homepage-carousel/existing.jpg', 'fake-bytes');
        $slide = $this->slide(['image_path' => 'homepage-carousel/existing.jpg', 'title' => 'Old']);

        $this->actingAs($this->superAdmin())->put(route('admin.homepage-carousel.update', $slide), [
            'title' => 'New title', 'sort_order' => 1, 'status' => 'active',
        ])->assertRedirect();

        $slide->refresh();
        $this->assertSame('New title', $slide->title);
        $this->assertSame('homepage-carousel/existing.jpg', $slide->image_path);
        Storage::disk('public')->assertExists('homepage-carousel/existing.jpg');
    }

    public function test_updating_with_a_new_image_replaces_and_deletes_the_old_one(): void
    {
        $this->fakeStorage();
        Storage::disk('public')->put('homepage-carousel/old.jpg', 'old-bytes');
        $slide = $this->slide(['image_path' => 'homepage-carousel/old.jpg']);

        $this->actingAs($this->superAdmin())->put(route('admin.homepage-carousel.update', $slide), [
            'image' => UploadedFile::fake()->image('new.jpg'),
            'sort_order' => 1, 'status' => 'active',
        ])->assertRedirect();

        $slide->refresh();
        $this->assertNotSame('homepage-carousel/old.jpg', $slide->image_path);
        Storage::disk('public')->assertMissing('homepage-carousel/old.jpg');
        Storage::disk('public')->assertExists($slide->image_path);
    }

    public function test_move_up_swaps_sort_order_with_the_previous_slide(): void
    {
        $first = $this->slide(['sort_order' => 1]);
        $second = $this->slide(['sort_order' => 2]);

        $this->actingAs($this->superAdmin())->post(route('admin.homepage-carousel.move-up', $second))->assertRedirect();

        $this->assertSame(1, $second->fresh()->sort_order);
        $this->assertSame(2, $first->fresh()->sort_order);
    }

    public function test_move_down_swaps_sort_order_with_the_next_slide(): void
    {
        $first = $this->slide(['sort_order' => 1]);
        $second = $this->slide(['sort_order' => 2]);

        $this->actingAs($this->superAdmin())->post(route('admin.homepage-carousel.move-down', $first))->assertRedirect();

        $this->assertSame(2, $first->fresh()->sort_order);
        $this->assertSame(1, $second->fresh()->sort_order);
    }

    public function test_status_can_be_toggled(): void
    {
        $slide = $this->slide(['status' => 'active']);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->patch(route('admin.homepage-carousel.toggle', $slide))->assertRedirect();
        $this->assertSame('inactive', $slide->fresh()->status);

        $this->actingAs($admin)->patch(route('admin.homepage-carousel.toggle', $slide))->assertRedirect();
        $this->assertSame('active', $slide->fresh()->status);
    }

    public function test_deleting_a_slide_removes_its_public_image(): void
    {
        $this->fakeStorage();
        Storage::disk('public')->put('homepage-carousel/gone.jpg', 'bytes');
        $slide = $this->slide(['image_path' => 'homepage-carousel/gone.jpg']);

        $this->actingAs($this->superAdmin())->delete(route('admin.homepage-carousel.destroy', $slide))
            ->assertRedirect();

        $this->assertDatabaseMissing('homepage_carousel_slides', ['id' => $slide->id]);
        Storage::disk('public')->assertMissing('homepage-carousel/gone.jpg');
    }

    public function test_rbac_hides_create_and_delete_without_permission(): void
    {
        $this->slide();
        $viewer = $this->userWith(['settings.view']);

        $this->actingAs($viewer)->get(route('admin.homepage-carousel.index'))
            ->assertOk()
            ->assertDontSee(route('admin.homepage-carousel.create'));

        $this->actingAs($viewer)->post(route('admin.homepage-carousel.store'), ['sort_order' => 1])
            ->assertForbidden();
    }

    public function test_bilingual_editor_round_trips_slide_text(): void
    {
        $this->fakeStorage();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.homepage-carousel.store'), [
            'image' => UploadedFile::fake()->image('slide.jpg'),
            'title' => 'প্রভাতফেরীতে স্বাগতম', 'title_en' => 'Welcome to Provatferi',
            'alt_text' => 'সূর্যোদয়ের ছবি', 'alt_text_en' => 'A sunrise photo',
            'sort_order' => 1, 'status' => 'active',
        ])->assertRedirect();

        $slide = HomepageCarouselSlide::query()->firstOrFail();

        $html = $this->actingAs($admin)->get(route('admin.homepage-carousel.edit', $slide))
            ->assertOk()->getContent();

        $this->assertStringContainsString('pf-bilingual-field', $html);
        $this->assertStringContainsString('প্রভাতফেরীতে স্বাগতম', $html);
        $this->assertStringContainsString('Welcome to Provatferi', $html);
    }
}
