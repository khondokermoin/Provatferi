<?php

namespace Tests\Feature\Admin;

use App\Models\HomepageCarouselSlide;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * PRIORITY 1.1: an admin's carousel change must show on the live homepage in
 * seconds, not after the Next.js fetch cache's 120-second window. Laravel
 * tells the public site to drop the cached carousel by sending a signed POST
 * to {PUBLIC_SITE_URL}/api/revalidate after every change.
 *
 * What is pinned here, and why each one matters:
 *   - EVERY way a slide changes triggers it (the observer, not six call
 *     sites — the seventh action added later must not silently skip it)
 *   - the signature is a real HMAC the Next.js verifier will accept, and the
 *     secret itself is never in the request
 *   - one request is one call, even when it saves twice (a reorder swaps two)
 *   - a failing or unreachable public site NEVER breaks an admin's save
 *   - an unconfigured environment (local dev, CI) makes no HTTP call at all
 */
class HomepageCarouselRevalidationTest extends AdminTestCase
{
    private const SECRET = 'test-revalidate-secret-not-real';

    private const URL = 'https://provatferi.org/api/revalidate';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.public_site.url' => 'https://provatferi.org']);
    }

    private function configureSecret(): void
    {
        config(['services.public_site.revalidate_secret' => self::SECRET]);
    }

    private function slide(array $overrides = []): HomepageCarouselSlide
    {
        return HomepageCarouselSlide::query()->create(array_merge([
            'image_path' => 'homepage-carousel/'.uniqid().'.jpg',
            'sort_order' => 1,
            'status' => 'active',
        ], $overrides));
    }

    private function revalidationCalls(): int
    {
        return Http::recorded(fn (Request $request) => $request->url() === self::URL)->count();
    }

    public function test_creating_a_slide_sends_one_signed_revalidation(): void
    {
        $this->configureSecret();
        Http::fake([self::URL => Http::response(['ok' => true])]);
        Storage::fake('uploads_private');
        Storage::fake('public');

        $this->actingAs($this->superAdmin())->post(route('admin.homepage-carousel.store'), [
            'image' => UploadedFile::fake()->image('slide.jpg', 800, 600),
            'sort_order' => 1,
            'status' => 'active',
        ])->assertRedirect(route('admin.homepage-carousel.index'));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $timestamp = $request->header('X-Revalidate-Timestamp')[0] ?? '';
            $signature = $request->header('X-Revalidate-Signature')[0] ?? '';

            return $request->url() === self::URL
                && $request->method() === 'POST'
                && $request['tag'] === 'homepage-carousel'
                && ctype_digit($timestamp)
                && abs(time() - (int) $timestamp) < 60
                // The exact algorithm lib/revalidate-auth.ts verifies.
                && hash_equals(hash_hmac('sha256', $timestamp.'.homepage-carousel', self::SECRET), $signature);
        });
    }

    public function test_the_secret_itself_is_never_sent(): void
    {
        $this->configureSecret();
        Http::fake([self::URL => Http::response(['ok' => true])]);

        $this->slide();
        app()->terminate();

        Http::assertSent(function (Request $request) {
            $haystack = json_encode([$request->url(), $request->headers(), $request->body()]);

            return ! str_contains($haystack, self::SECRET);
        });
    }

    public function test_editing_replacing_toggling_and_deleting_each_revalidate(): void
    {
        $this->configureSecret();
        Http::fake([self::URL => Http::response(['ok' => true])]);
        Storage::fake('uploads_private');
        Storage::fake('public');
        $admin = $this->superAdmin();
        $slide = $this->slide(['title' => 'Before']);
        app()->terminate();
        Http::fake([self::URL => Http::response(['ok' => true])]); // reset the recorder after setup

        // edit (text only)
        $this->actingAs($admin)->put(route('admin.homepage-carousel.update', $slide), [
            'title' => 'After', 'sort_order' => 1, 'status' => 'active',
        ])->assertRedirect();
        $this->assertSame(1, $this->revalidationCalls(), 'editing text must revalidate');

        // replace the image
        Http::fake([self::URL => Http::response(['ok' => true])]);
        $this->actingAs($admin)->put(route('admin.homepage-carousel.update', $slide), [
            'image' => UploadedFile::fake()->image('new.jpg', 800, 600),
            'title' => 'After', 'sort_order' => 1, 'status' => 'active',
        ])->assertRedirect();
        $this->assertSame(1, $this->revalidationCalls(), 'replacing the image must revalidate');

        // enable/disable
        Http::fake([self::URL => Http::response(['ok' => true])]);
        $this->actingAs($admin)->patch(route('admin.homepage-carousel.toggle', $slide))->assertRedirect();
        $this->assertSame(1, $this->revalidationCalls(), 'toggling status must revalidate');

        // delete
        Http::fake([self::URL => Http::response(['ok' => true])]);
        $this->actingAs($admin)->delete(route('admin.homepage-carousel.destroy', $slide))->assertRedirect();
        $this->assertSame(1, $this->revalidationCalls(), 'deleting must revalidate');
    }

    public function test_reordering_swaps_two_rows_but_sends_a_single_revalidation(): void
    {
        $this->configureSecret();
        $first = $this->slide(['sort_order' => 1]);
        $second = $this->slide(['sort_order' => 2]);
        app()->terminate();
        Http::fake([self::URL => Http::response(['ok' => true])]);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.homepage-carousel.move-up', $second))->assertRedirect();

        // moveUp saves BOTH rows. Without per-request de-duplication that is
        // two identical calls to the public site for one click.
        $this->assertSame(1, $this->revalidationCalls());
        $this->assertSame(2, $first->fresh()->sort_order, 'the swap itself must still have happened');
        $this->assertSame(1, $second->fresh()->sort_order);
    }

    public function test_an_unreachable_public_site_never_breaks_the_admin_save(): void
    {
        $this->configureSecret();
        Http::fake(fn () => throw new ConnectionException('connection refused'));
        Log::spy();
        Storage::fake('uploads_private');
        Storage::fake('public');

        $this->actingAs($this->superAdmin())->post(route('admin.homepage-carousel.store'), [
            'image' => UploadedFile::fake()->image('slide.jpg', 800, 600),
            'sort_order' => 1,
            'status' => 'active',
        ])->assertRedirect(route('admin.homepage-carousel.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, HomepageCarouselSlide::query()->count(), 'the slide must be saved even though revalidation failed');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'revalidation failed'))->once();
    }

    public function test_a_rejected_revalidation_is_logged_and_does_not_throw(): void
    {
        $this->configureSecret();
        Http::fake([self::URL => Http::response(['ok' => false], 401)]);
        Log::spy();

        $this->slide();
        app()->terminate();

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'rejected'))->once();
    }

    public function test_an_unconfigured_environment_makes_no_http_call_at_all(): void
    {
        config(['services.public_site.revalidate_secret' => null]);
        Http::fake();

        $this->slide();
        app()->terminate();

        Http::assertNothingSent();
    }

    public function test_an_empty_secret_is_treated_as_unconfigured(): void
    {
        config(['services.public_site.revalidate_secret' => '']);
        Http::fake();

        $this->slide();
        app()->terminate();

        Http::assertNothingSent();
    }
}
