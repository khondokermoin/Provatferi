<?php

namespace Tests\Feature\Api;

use App\Models\LeadershipProfile;
use App\Models\LeadershipProfileSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesLeadershipProfiles;
use Tests\TestCase;

/**
 * The contract of GET /api/v1/public/leadership[/{slug}] (docs/LEADERSHIP_SYSTEM.md), read by the public site only. Pinned:
 * the exact field lists (an explicit allow-list, so a new column is private until someone adds it), that only ACTIVE profiles
 * exist for the public (an inactive or archived one is a 404, indistinguishable from one that was never created), the one
 * deterministic order, the redirect help for a retired address, and that whatever is in the table — a hostile link written
 * by hand, the internal mailbox — never comes out.
 */
class PublicLeadershipApiTest extends TestCase
{
    use MakesLeadershipProfiles;
    use RefreshDatabase;

    private const SUMMARY_KEYS = ['slug', 'name_bn', 'name_en', 'designation_bn', 'designation_en', 'short_bio_bn', 'short_bio_en',
        'expertise_bn', 'expertise_en', 'featured', 'photo', 'social', 'website_url'];

    private const DETAIL_KEYS = [...self::SUMMARY_KEYS, 'full_bio_bn', 'full_bio_en', 'quote_bn', 'quote_en', 'associated_since_year', 'public_email', 'public_phone'];

    /** @return list<array<string, mixed>> */
    private function list(string $query = ''): array
    {
        return $this->getJson('/api/v1/public/leadership'.$query)->assertOk()->json('data');
    }

    // --- who is public -----------------------------------------------------------------------------------------------

    public function test_an_empty_table_is_an_empty_list_not_an_error(): void
    {
        $this->getJson('/api/v1/public/leadership')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_only_active_profiles_are_listed(): void
    {
        $this->leadershipProfile(['slug' => 'visible', 'status' => 'active']);
        $this->leadershipProfile(['slug' => 'hidden', 'status' => 'inactive']);
        $this->leadershipProfile(['slug' => 'kept', 'status' => 'archived']);

        $this->assertSame(['visible'], array_column($this->list(), 'slug'));
    }

    public function test_an_inactive_or_archived_profile_is_exactly_as_missing_as_one_that_never_existed(): void
    {
        config(['app.debug' => false]); // production's setting: with debug on, an error body carries a trace that differs per request
        $this->leadershipProfile(['slug' => 'hidden', 'status' => 'inactive']);
        $this->leadershipProfile(['slug' => 'kept', 'status' => 'archived']);

        $missing = $this->getJson('/api/v1/public/leadership/never-existed');
        $missing->assertNotFound();

        foreach (['hidden', 'kept'] as $slug) {
            $response = $this->getJson("/api/v1/public/leadership/{$slug}");
            $response->assertNotFound();
            $this->assertSame($missing->status(), $response->status());
            $this->assertSame($missing->json(), $response->json(), 'the body must not reveal that a hidden profile exists');
        }
    }

    // --- order -------------------------------------------------------------------------------------------------------

    public function test_the_order_is_the_display_order_with_ties_broken_by_id_and_featured_never_reorders(): void
    {
        $this->leadershipProfile(['slug' => 'c', 'display_order' => 30, 'featured' => true]);
        $this->leadershipProfile(['slug' => 'a', 'display_order' => 10]);
        $this->leadershipProfile(['slug' => 'b1', 'display_order' => 20]);
        $this->leadershipProfile(['slug' => 'b2', 'display_order' => 20, 'featured' => true]);

        $this->assertSame(['a', 'b1', 'b2', 'c'], array_column($this->list(), 'slug'));
        // The same call again, and the featured filter: the same relative order, every time.
        $this->assertSame(['a', 'b1', 'b2', 'c'], array_column($this->list(), 'slug'));
        $this->assertSame(['b2', 'c'], array_column($this->list('?featured=1'), 'slug'));
    }

    public function test_the_featured_filter_and_the_limit_work_together_and_are_bounded(): void
    {
        foreach (range(1, 6) as $n) {
            $this->leadershipProfile(['slug' => "p{$n}", 'display_order' => $n * 10, 'featured' => $n % 2 === 1]);
        }

        $this->assertSame(['p1', 'p3'], array_column($this->list('?featured=1&limit=2'), 'slug'));
        $this->assertCount(6, $this->list('?limit=0'));
        $this->assertCount(6, $this->list('?limit=-5'));
        $this->assertCount(6, $this->list('?limit=9999'), 'a huge limit is capped, never an error');
        $this->assertCount(6, $this->list('?featured=nonsense'));
    }

    // --- what is in a response ---------------------------------------------------------------------------------------

    public function test_a_list_item_has_exactly_the_documented_fields(): void
    {
        $this->leadershipProfile([
            'name_en' => 'Name', 'photo_path' => 'leadership/x.jpg', 'photo_sm_path' => 'leadership/x-sm.jpg', 'photo_width' => 800, 'photo_height' => 1000,
            'facebook_url' => 'https://www.facebook.com/page', 'website_url' => 'https://example.org',
            'public_email' => 'person@example.org', 'public_phone' => '+8801711000000', 'full_bio_bn' => 'পূর্ণ', 'quote_bn' => 'উক্তি',
            'created_by' => null, 'updated_by' => null,
        ]);

        $item = $this->list()[0];

        $this->assertSame(self::SUMMARY_KEYS, array_keys($item));
        $this->assertSame(['url', 'sm_url', 'width', 'height'], array_keys($item['photo']));
        $this->assertSame(800, $item['photo']['width']);
        $this->assertSame(1000, $item['photo']['height']);
        $this->assertStringEndsWith('leadership/x.jpg', $item['photo']['url']);
        $this->assertStringEndsWith('leadership/x-sm.jpg', $item['photo']['sm_url']);
        $this->assertSame([['platform' => 'facebook', 'url' => 'https://www.facebook.com/page']], $item['social']);
        $this->assertSame('https://example.org', $item['website_url']);
    }

    public function test_the_card_never_carries_the_e_mail_the_phone_the_full_biography_or_the_quote(): void
    {
        $this->leadershipProfile([
            'public_email' => 'person@example.org', 'public_phone' => '+8801711000000', 'full_bio_bn' => 'গোপন পূর্ণ পরিচিতি', 'quote_en' => 'private quote',
        ]);

        $json = json_encode($this->list(), JSON_UNESCAPED_UNICODE);

        foreach (['person@example.org', '+8801711000000', 'গোপন পূর্ণ পরিচিতি', 'private quote'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $json);
        }
    }

    public function test_the_detail_has_exactly_the_documented_fields_including_the_optional_public_contact(): void
    {
        $this->leadershipProfile([
            'slug' => 'detail', 'name_en' => 'Name', 'full_bio_bn' => 'পূর্ণ পরিচিতি', 'full_bio_en' => 'Full bio', 'quote_bn' => 'উক্তি', 'quote_en' => 'Quote',
            'associated_since_year' => 2019, 'public_email' => 'person@example.org', 'public_phone' => '+880 1711 000000',
        ]);

        $data = $this->getJson('/api/v1/public/leadership/detail')->assertOk()->json('data');

        $this->assertSame(self::DETAIL_KEYS, array_keys($data));
        $this->assertSame('detail', $data['slug']);
        $this->assertSame('person@example.org', $data['public_email']);
        $this->assertSame('+880 1711 000000', $data['public_phone']);
        $this->assertSame(2019, $data['associated_since_year']);
        $this->assertSame('Quote', $data['quote_en']);
    }

    public function test_a_field_left_empty_is_null_not_an_empty_string_and_there_is_no_photo_object_without_a_photo(): void
    {
        $this->leadershipProfile(['slug' => 'bare', 'name_en' => '', 'short_bio_bn' => '   ', 'expertise_bn' => null]);

        $data = $this->getJson('/api/v1/public/leadership/bare')->assertOk()->json('data');

        foreach (['name_en', 'short_bio_bn', 'expertise_bn', 'full_bio_en', 'quote_bn', 'public_email', 'public_phone', 'website_url', 'associated_since_year', 'photo'] as $key) {
            $this->assertNull($data[$key], $key);
        }
        $this->assertSame([], $data['social']);
    }

    /** @return array<string, array{0: string}> */
    public static function privateKeys(): array
    {
        return ['id' => ['id'], 'created_by' => ['created_by'], 'updated_by' => ['updated_by'], 'status' => ['status'], 'created_at' => ['created_at'],
            'updated_at' => ['updated_at'], 'display_order' => ['display_order'], 'photo_path' => ['photo_path'], 'photo_sm_path' => ['photo_sm_path'],
            'photo_width' => ['photo_width'], 'facebook_url' => ['facebook_url'], 'linkedin_url' => ['linkedin_url'], 'youtube_url' => ['youtube_url']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('privateKeys')]
    public function test_no_internal_column_is_ever_a_key_of_a_response(string $key): void
    {
        $this->leadershipProfile(['slug' => 'checked', 'photo_path' => 'leadership/x.jpg', 'photo_sm_path' => 'leadership/x-sm.jpg', 'photo_width' => 1, 'photo_height' => 1]);

        $this->assertArrayNotHasKey($key, $this->list()[0]);
        $this->assertArrayNotHasKey($key, $this->getJson('/api/v1/public/leadership/checked')->json('data'));
    }

    public function test_the_response_never_contains_a_storage_path_or_the_internal_mailbox(): void
    {
        $admin = \App\Models\User::factory()->create(['email' => 'admin@provatferi.org']);
        $this->leadershipProfile(['slug' => 'clean', 'photo_path' => 'leadership/x.jpg', 'photo_sm_path' => 'leadership/x-sm.jpg', 'created_by' => $admin->id, 'updated_by' => $admin->id]);

        $body = $this->getJson('/api/v1/public/leadership/clean')->getContent().$this->getJson('/api/v1/public/leadership')->getContent();

        $this->assertStringNotContainsString('admin@provatferi.org', $body);
        $this->assertStringNotContainsString(storage_path(), $body);
        $this->assertStringNotContainsString('uploads_private', $body);
    }

    // --- defence in depth: whatever the table holds ------------------------------------------------------------------

    public function test_a_hostile_value_written_straight_to_the_table_never_reaches_a_response(): void
    {
        $this->leadershipProfile(['slug' => 'hostile']);
        DB::table('leadership_profiles')->where('slug', 'hostile')->update([
            'facebook_url' => 'javascript:alert(1)',
            'linkedin_url' => 'https://evil.example/in/someone',
            'youtube_url' => 'https://www.youtube.com/@legit',
            'website_url' => 'http://insecure.example.org',
            'public_email' => 'admin@provatferi.org',
            'public_phone' => 'call-me-now',
        ]);

        $data = $this->getJson('/api/v1/public/leadership/hostile')->assertOk()->json('data');

        $this->assertSame([['platform' => 'youtube', 'url' => 'https://www.youtube.com/@legit']], $data['social'], 'only the one valid link survives');
        $this->assertNull($data['website_url']);
        $this->assertNull($data['public_email'], 'the internal mailbox is never published, whatever the table says');
        $this->assertNull($data['public_phone']);

        $listed = $this->list()[0];
        $this->assertSame([['platform' => 'youtube', 'url' => 'https://www.youtube.com/@legit']], $listed['social']);
        $this->assertNull($listed['website_url']);
    }

    public function test_the_no_reply_and_security_mailboxes_are_not_published_either(): void
    {
        foreach (['no-reply@provatferi.org', 'noreply@example.org', 'security@provatferi.org', 'boss%0d%0abcc@example.org'] as $index => $address) {
            $this->leadershipProfile(['slug' => "mailbox-{$index}"]);
            DB::table('leadership_profiles')->where('slug', "mailbox-{$index}")->update(['public_email' => $address]);

            $this->assertNull($this->getJson("/api/v1/public/leadership/mailbox-{$index}")->json('data.public_email'), $address);
        }
    }

    public function test_the_social_links_come_in_a_fixed_order(): void
    {
        $this->leadershipProfile(['slug' => 'ordered', 'youtube_url' => 'https://www.youtube.com/@a', 'facebook_url' => 'https://www.facebook.com/a', 'linkedin_url' => 'https://www.linkedin.com/in/a']);

        $this->assertSame(['facebook', 'linkedin', 'youtube'], array_column($this->list()[0]['social'], 'platform'));
    }

    // --- addresses, history ------------------------------------------------------------------------------------------

    public function test_a_retired_address_resolves_to_the_profile_and_the_response_names_its_current_address(): void
    {
        $profile = $this->leadershipProfile(['slug' => 'current-address']);
        LeadershipProfileSlug::query()->create(['leadership_profile_id' => $profile->id, 'slug' => 'old-address']);

        $this->getJson('/api/v1/public/leadership/current-address')->assertOk()->assertJsonPath('data.slug', 'current-address');
        $this->getJson('/api/v1/public/leadership/old-address')->assertOk()->assertJsonPath('data.slug', 'current-address');
    }

    public function test_a_retired_address_of_a_profile_that_is_no_longer_public_is_a_404(): void
    {
        foreach (['inactive', 'archived'] as $status) {
            $profile = $this->leadershipProfile(['slug' => "now-{$status}", 'status' => $status]);
            LeadershipProfileSlug::query()->create(['leadership_profile_id' => $profile->id, 'slug' => "was-{$status}"]);

            $this->getJson("/api/v1/public/leadership/was-{$status}")->assertNotFound();
        }
    }

    public function test_an_address_that_cannot_be_a_slug_never_reaches_the_database(): void
    {
        $this->leadershipProfile(['slug' => 'real']);

        foreach (['Real', 'real_underscore', 'a%20b', '..', '-real', 'real-'] as $bad) {
            $this->getJson('/api/v1/public/leadership/'.$bad)->assertNotFound();
        }
    }

    public function test_the_endpoints_are_read_only(): void
    {
        $profile = $this->leadershipProfile(['slug' => 'readonly']);

        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
            $this->{$method}('/api/v1/public/leadership/readonly', ['name_bn' => 'changed'])->assertStatus(405);
            $this->{$method}('/api/v1/public/leadership', ['name_bn' => 'changed'])->assertStatus(405);
        }
        $this->assertSame($profile->name_bn, LeadershipProfile::query()->findOrFail($profile->id)->name_bn);
    }
}
