<?php

namespace Tests\Feature\Admin;

use App\Models\LeadershipProfile;
use App\Models\LeadershipProfileSlug;
use App\Models\Role;
use App\Services\PublicSiteRevalidator;
use App\Support\AdminTime;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MakesLeadershipProfiles;

/**
 * Leadership / Key Persons, admin side (docs/LEADERSHIP_SYSTEM.md): what an admin can create and change, what a role without
 * the publish permission cannot, how slugs, order and status behave, what is refused, and what every change does to the
 * public site. The photo has its own file (LeadershipPhotoTest).
 */
class LeadershipProfileTest extends AdminTestCase
{
    use MakesLeadershipProfiles;

    private const SECRET = 'test-revalidate-secret-not-real';

    private const URL = 'https://provatferi.org/api/revalidate';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.public_site.url' => 'https://provatferi.org', 'services.public_site.revalidate_secret' => self::SECRET]);
        Http::swap((new HttpFactory)->preventStrayRequests()); // the suite-wide "no real network" rule
        Http::fake([self::URL => Http::response(['ok' => true])]);
    }

    /** @return list<list<string>> the tags of each call made to the public site, in order */
    private function calls(): array
    {
        return Http::recorded(fn (Request $request) => $request->url() === self::URL)
            ->map(fn (array $pair) => $pair[0]['tags'])->values()->all();
    }

    /** @return list<string> the profiles' slugs in the one canonical order */
    private function order(bool $includeArchived = false): array
    {
        $query = LeadershipProfile::query()->inDisplayOrder();
        if (! $includeArchived) {
            $query->orderable();
        }

        return $query->pluck('slug')->all();
    }

    // --- list --------------------------------------------------------------------------------------------------------

    public function test_the_list_is_empty_until_a_profile_exists_and_then_follows_the_display_order(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('admin.leadership.index'))->assertOk()->assertSee('এখনো কোনো নেতৃত্ব প্রোফাইল নেই');

        $this->leadershipProfile(['name_bn' => 'তৃতীয়', 'display_order' => 30]);
        $this->leadershipProfile(['name_bn' => 'প্রথম', 'display_order' => 10]);
        $this->leadershipProfile(['name_bn' => 'দ্বিতীয়', 'display_order' => 20]);

        $html = $this->actingAs($admin)->get(route('admin.leadership.index'))->assertOk()->getContent();
        $this->assertTrue(
            strpos($html, 'প্রথম') < strpos($html, 'দ্বিতীয়') && strpos($html, 'দ্বিতীয়') < strpos($html, 'তৃতীয়'),
            'Profiles must be listed in display_order, not in creation order.',
        );
    }

    public function test_the_list_hides_the_archived_unless_asked_and_filters_by_status_featured_and_search(): void
    {
        $this->leadershipProfile(['name_bn' => 'সক্রিয় ব্যক্তি', 'name_en' => 'Active Person', 'designation_bn' => 'পরিচালক']);
        $this->leadershipProfile(['name_bn' => 'নিষ্ক্রিয় ব্যক্তি', 'status' => 'inactive']);
        $this->leadershipProfile(['name_bn' => 'আর্কাইভ ব্যক্তি', 'status' => 'archived']);
        $this->leadershipProfile(['name_bn' => 'ফিচারড ব্যক্তি', 'featured' => true]);
        $admin = $this->superAdmin();

        $default = $this->actingAs($admin)->get(route('admin.leadership.index'))->assertOk();
        $default->assertSee('সক্রিয় ব্যক্তি')->assertSee('নিষ্ক্রিয় ব্যক্তি')->assertDontSee('আর্কাইভ ব্যক্তি');

        $this->actingAs($admin)->get(route('admin.leadership.index', ['status' => 'archived']))
            ->assertOk()->assertSee('আর্কাইভ ব্যক্তি')->assertDontSee('সক্রিয় ব্যক্তি');

        $this->actingAs($admin)->get(route('admin.leadership.index', ['status' => 'inactive']))
            ->assertOk()->assertSee('নিষ্ক্রিয় ব্যক্তি')->assertDontSee('ফিচারড ব্যক্তি');

        $this->actingAs($admin)->get(route('admin.leadership.index', ['featured' => '1']))
            ->assertOk()->assertSee('ফিচারড ব্যক্তি')->assertDontSee('সক্রিয় ব্যক্তি');

        $this->actingAs($admin)->get(route('admin.leadership.index', ['q' => 'Active Person']))
            ->assertOk()->assertSee('সক্রিয় ব্যক্তি')->assertDontSee('ফিচারড ব্যক্তি');

        $this->actingAs($admin)->get(route('admin.leadership.index', ['q' => 'পরিচালক']))
            ->assertOk()->assertSee('সক্রিয় ব্যক্তি')->assertDontSee('নিষ্ক্রিয় ব্যক্তি');

        // A wildcard typed into the search is text, not a pattern.
        $this->actingAs($admin)->get(route('admin.leadership.index', ['q' => '%']))->assertOk()->assertSee('এই ফিল্টারের সঙ্গে মেলে এমন কোনো প্রোফাইল নেই');

        // A bad filter value is ignored, not an error.
        $this->actingAs($admin)->get(route('admin.leadership.index', ['status' => 'nonsense', 'featured' => 'x']))->assertOk();
    }

    public function test_the_reorder_arrows_are_only_offered_on_the_unfiltered_list_to_someone_who_may_publish(): void
    {
        $this->leadershipProfile();
        $this->leadershipProfile();

        $this->actingAs($this->superAdmin())->get(route('admin.leadership.index'))->assertOk()->assertSee('move-up', false);
        $this->actingAs($this->superAdmin())->get(route('admin.leadership.index', ['q' => 'প্রোফাইল']))->assertOk()->assertDontSee('/move-up', false);
        $this->actingAs($this->userWith(['leadership.view']))->get(route('admin.leadership.index'))->assertOk()->assertDontSee('/move-up', false);
    }

    // --- create ------------------------------------------------------------------------------------------------------

    public function test_a_profile_is_created_with_bangla_and_english_fields_and_starts_hidden_by_default_for_a_creator(): void
    {
        $creator = $this->userWith(['leadership.view', 'leadership.create']);

        $this->actingAs($creator)->post(route('admin.leadership.store'), $this->leadershipForm([
            'short_bio_bn' => 'সংক্ষিপ্ত পরিচিতি', 'short_bio_en' => 'Short bio',
            'full_bio_bn' => "প্রথম অনুচ্ছেদ\n\nদ্বিতীয় অনুচ্ছেদ", 'full_bio_en' => "First paragraph\n\nSecond paragraph",
            'quote_bn' => 'একটি উক্তি', 'quote_en' => 'A quote',
            'expertise_bn' => 'শিক্ষা', 'expertise_en' => 'Education',
            'associated_since_year' => '2019',
            'public_email' => 'Person@Example.org', 'public_phone' => '+880 1711 000000',
            'facebook_url' => 'https://www.facebook.com/test.person',
            'linkedin_url' => 'https://www.linkedin.com/in/test-person',
            'youtube_url' => 'https://www.youtube.com/@testperson',
            'website_url' => 'https://example.org/test',
        ]))->assertRedirect(route('admin.leadership.index'))->assertSessionHasNoErrors()->assertSessionHas('success');

        $profile = LeadershipProfile::query()->firstOrFail();
        $this->assertSame('test-person', $profile->slug, 'the address comes from the English name when there is one');
        $this->assertSame('পরীক্ষামূলক ব্যক্তি', $profile->name_bn);
        $this->assertSame('Test Person', $profile->name_en);
        $this->assertSame('Test designation', $profile->designation_en);
        $this->assertSame("প্রথম অনুচ্ছেদ\n\nদ্বিতীয় অনুচ্ছেদ", $profile->full_bio_bn);
        $this->assertSame('A quote', $profile->quote_en);
        $this->assertSame('Education', $profile->expertise_en);
        $this->assertSame(2019, $profile->associated_since_year);
        $this->assertSame('person@example.org', $profile->public_email, 'an e-mail is stored in lower case');
        $this->assertSame('https://www.facebook.com/test.person', $profile->facebook_url);
        $this->assertSame('https://example.org/test', $profile->website_url);
        $this->assertSame($creator->id, $profile->created_by);
        $this->assertSame($creator->id, $profile->updated_by);
        $this->assertSame('inactive', $profile->status, 'without the publish permission a new profile is always hidden');
        $this->assertFalse($profile->featured);
        $this->assertSame(LeadershipProfile::ORDER_STEP, $profile->display_order);
    }

    public function test_a_creator_cannot_publish_feature_or_order_by_crafting_the_request(): void
    {
        $this->leadershipProfile(['display_order' => 50]);
        $creator = $this->userWith(['leadership.view', 'leadership.create']);

        $this->actingAs($creator)->post(route('admin.leadership.store'), $this->leadershipForm([
            'status' => 'active', 'featured' => '1', 'display_order' => '1',
        ]))->assertSessionHasNoErrors();

        $profile = LeadershipProfile::query()->where('name_en', 'Test Person')->firstOrFail();
        $this->assertSame('inactive', $profile->status);
        $this->assertFalse($profile->featured);
        $this->assertSame(60, $profile->display_order, 'a new profile goes to the end of the order');
    }

    public function test_someone_who_may_publish_can_create_an_active_featured_profile_at_a_chosen_place(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.leadership.store'), $this->leadershipForm([
            'status' => 'active', 'featured' => '1', 'display_order' => '25',
        ]))->assertSessionHasNoErrors();

        $profile = LeadershipProfile::query()->firstOrFail();
        $this->assertSame('active', $profile->status);
        $this->assertTrue($profile->featured);
        $this->assertSame(25, $profile->display_order);
    }

    public function test_status_cannot_be_set_to_archived_through_the_form(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.leadership.store'), $this->leadershipForm(['status' => 'archived']))
            ->assertSessionHasErrors('status');
        $this->assertSame(0, LeadershipProfile::query()->count());
    }

    public function test_bangla_name_and_designation_are_required_and_english_is_optional(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.leadership.store'), [])->assertSessionHasErrors(['name_bn', 'designation_bn']);

        $this->actingAs($admin)->post(route('admin.leadership.store'), ['name_bn' => 'শুধু বাংলা নাম', 'designation_bn' => 'শুধু বাংলা পদবি'])
            ->assertSessionHasNoErrors();

        $profile = LeadershipProfile::query()->firstOrFail();
        $this->assertNull($profile->name_en);
        $this->assertNull($profile->designation_en);
        $this->assertNull($profile->short_bio_en);
        $this->assertNull($profile->photo_path);
    }

    public function test_lengths_and_the_year_are_bounded(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.leadership.store'), $this->leadershipForm([
            'short_bio_bn' => str_repeat('ক', 601),
            'full_bio_en' => str_repeat('a', 10001),
            'quote_bn' => str_repeat('ক', 501),
            'expertise_en' => str_repeat('a', 161),
            'associated_since_year' => '1850',
            'display_order' => '-1',
        ]))->assertSessionHasErrors(['short_bio_bn', 'full_bio_en', 'quote_bn', 'expertise_en', 'associated_since_year', 'display_order']);

        $this->actingAs($admin)->post(route('admin.leadership.store'), $this->leadershipForm([
            'associated_since_year' => (string) (AdminTime::year() + 1),
        ]))->assertSessionHasErrors('associated_since_year');
    }

    // --- plain text only ---------------------------------------------------------------------------------------------

    #[DataProvider('markup')]
    public function test_markup_is_refused_rather_than_stored(string $field, string $value): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.leadership.store'), $this->leadershipForm([$field => $value]))
            ->assertSessionHasErrors($field);

        $this->assertSame(0, LeadershipProfile::query()->count());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function markup(): array
    {
        return [
            'script in the name' => ['name_bn', '<script>alert(1)</script>'],
            'tag in the english name' => ['name_en', 'Roney <b>bold</b>'],
            'tag in the designation' => ['designation_en', '<img src=x onerror=alert(1)>'],
            'tag in a short bio' => ['short_bio_bn', 'সংক্ষিপ্ত <a href="javascript:alert(1)">লিংক</a>'],
            'tag in a full bio' => ['full_bio_en', "Fine.\n\n</p><script>alert(1)</script>"],
            'comment in a quote' => ['quote_en', 'Quote <!-- hidden -->'],
            'processing instruction' => ['quote_bn', 'উক্তি <?php echo 1; ?>'],
        ];
    }

    public function test_a_less_than_sign_that_opens_nothing_is_ordinary_text(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.leadership.store'), $this->leadershipForm([
            'short_bio_en' => 'Led a team of 5 < 10 people, and 3 > 2.',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('Led a team of 5 < 10 people, and 3 > 2.', LeadershipProfile::query()->firstOrFail()->short_bio_en);
    }

    public function test_control_characters_and_text_direction_overrides_are_removed_and_blank_runs_collapse(): void
    {
        $override = mb_chr(0x202E);
        $isolate = mb_chr(0x2066);

        $this->actingAs($this->superAdmin())->post(route('admin.leadership.store'), $this->leadershipForm([
            'name_en' => "  Test{$override}   Person\x07  ",
            'designation_en' => "Dir{$isolate}ector",
            'full_bio_en' => "One\r\n\r\n\r\n\r\nTwo   \nThree\x00",
        ]))->assertSessionHasNoErrors();

        $profile = LeadershipProfile::query()->firstOrFail();
        $this->assertSame('Test Person', $profile->name_en);
        $this->assertSame('Director', $profile->designation_en);
        $this->assertSame("One\n\nTwo\nThree", $profile->full_bio_en);
    }

    public function test_the_bengali_joiners_that_conjuncts_need_are_kept(): void
    {
        $zwnj = mb_chr(0x200C);
        $name = "র{$zwnj}নি";

        $this->actingAs($this->superAdmin())->post(route('admin.leadership.store'), $this->leadershipForm(['name_bn' => $name]))
            ->assertSessionHasNoErrors();

        $this->assertSame($name, LeadershipProfile::query()->firstOrFail()->name_bn);
    }

    public function test_a_single_line_field_refuses_a_line_break(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.leadership.store'), $this->leadershipForm(['designation_bn' => "প্রথম\nদ্বিতীয়"]))
            ->assertSessionHasErrors('designation_bn');
    }

    // --- links, e-mail, phone ----------------------------------------------------------------------------------------

    #[DataProvider('badLinks')]
    public function test_an_unsafe_link_is_refused_with_a_message_under_the_field_and_nothing_is_saved(string $field, string $value): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.leadership.store'), $this->leadershipForm([$field => $value]))
            ->assertSessionHasErrors($field);

        $this->assertSame(0, LeadershipProfile::query()->count());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function badLinks(): array
    {
        return [
            'javascript in facebook' => ['facebook_url', 'javascript:alert(1)'],
            'data uri in linkedin' => ['linkedin_url', 'data:text/html;base64,PHNjcmlwdD4='],
            'http facebook' => ['facebook_url', 'http://www.facebook.com/page'],
            'look-alike host' => ['youtube_url', 'https://youtube.com.evil.example/@x'],
            'user-info trick' => ['facebook_url', 'https://facebook.com@evil.example/page'],
            'another platform' => ['linkedin_url', 'https://www.facebook.com/page'],
            'home page only' => ['facebook_url', 'https://www.facebook.com/'],
            'website over http' => ['website_url', 'http://example.org'],
            'website javascript' => ['website_url', 'javascript:alert(1)'],
            'website ip address' => ['website_url', 'https://192.168.0.1/admin'],
            'website internal name' => ['website_url', 'https://printer.local/'],
            'website with markup' => ['website_url', 'https://example.org/"><script>'],
            'website with a space' => ['website_url', 'https://example.org/a b'],
            'a very long address' => ['website_url', 'https://example.org/'.str_repeat('a', 600)],
        ];
    }

    public function test_links_are_stored_in_their_normalised_form(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.leadership.store'), $this->leadershipForm([
            'facebook_url' => 'HTTPS://WWW.FACEBOOK.COM/Test.Person#fragment',
            'website_url' => 'https://EXAMPLE.org:443/Path?x=1',
        ]))->assertSessionHasNoErrors();

        $profile = LeadershipProfile::query()->firstOrFail();
        $this->assertSame('https://www.facebook.com/Test.Person', $profile->facebook_url);
        $this->assertSame('https://example.org/Path?x=1', $profile->website_url);
    }

    #[DataProvider('forbiddenMailboxes')]
    public function test_the_internal_automated_and_security_mailboxes_cannot_be_published(string $address): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.leadership.store'), $this->leadershipForm(['public_email' => $address]))
            ->assertSessionHasErrors('public_email');

        $this->assertSame(0, LeadershipProfile::query()->count());
    }

    /** @return array<string, array{0: string}> */
    public static function forbiddenMailboxes(): array
    {
        return [
            'internal' => ['admin@provatferi.org'],
            'no-reply' => ['no-reply@provatferi.org'],
            'noreply' => ['noreply@example.org'],
            'security' => ['security@provatferi.org'],
            'not an address' => ['not-an-address'],
            'an encoded line break (a mailto: header injection)' => ['boss%0d%0abcc@example.org'],
            'two addresses' => ['a@example.org,b@example.org'],
            'a display name' => ['Boss <boss@example.org>'],
        ];
    }

    public function test_a_phone_number_must_look_like_one(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.leadership.store'), $this->leadershipForm(['public_phone' => 'call me maybe']))
            ->assertSessionHasErrors('public_phone');
        $this->actingAs($this->superAdmin())->post(route('admin.leadership.store'), $this->leadershipForm(['public_phone' => '+880 1711-000000']))
            ->assertSessionHasNoErrors();
    }

    // --- slugs -------------------------------------------------------------------------------------------------------

    public function test_a_slug_is_made_from_the_name_and_made_unique(): void
    {
        $admin = $this->superAdmin();

        // Made from the name: no notice, whatever number it ends up with.
        $this->actingAs($admin)->post(route('admin.leadership.store'), $this->leadershipForm())->assertSessionMissing('warning');
        $this->actingAs($admin)->post(route('admin.leadership.store'), $this->leadershipForm())->assertSessionMissing('warning');
        // Typed and taken: the number is added and the admin is told which address was used instead.
        $this->actingAs($admin)->post(route('admin.leadership.store'), $this->leadershipForm(['slug' => 'test-person']));

        $this->assertEqualsCanonicalizing(['test-person', 'test-person-2', 'test-person-3'], LeadershipProfile::query()->pluck('slug')->all());
        $this->assertStringContainsString('test-person-3', (string) session('warning'));
    }

    public function test_a_profile_with_only_a_bangla_name_still_gets_a_usable_address(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.leadership.store'), ['name_bn' => 'প্রতিষ্ঠাতা পরিচালক', 'designation_bn' => 'পদবি']);

        $slug = LeadershipProfile::query()->firstOrFail()->slug;
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug);
        $this->assertLessThanOrEqual(80, strlen($slug));
    }

    public function test_a_hostile_slug_is_refused(): void
    {
        foreach (['Has Capitals', 'with space', 'under_score', '../etc/passwd', '-leading', 'trailing-', 'double--hyphen', '<script>'] as $slug) {
            $this->actingAs($this->superAdmin())->post(route('admin.leadership.store'), $this->leadershipForm(['slug' => $slug]))
                ->assertSessionHasErrors('slug');
        }
        $this->assertSame(0, LeadershipProfile::query()->count());
    }

    public function test_changing_the_name_never_changes_the_address(): void
    {
        $profile = $this->leadershipProfile(['slug' => 'founder', 'name_bn' => 'পুরনো নাম']);

        $this->actingAs($this->superAdmin())->put(route('admin.leadership.update', $profile), $this->leadershipForm([
            'slug' => 'founder', 'name_bn' => 'নতুন নাম', 'name_en' => 'New Name',
        ]))->assertSessionHasNoErrors();

        $profile->refresh();
        $this->assertSame('founder', $profile->slug);
        $this->assertSame('নতুন নাম', $profile->name_bn);
        $this->assertSame(0, LeadershipProfileSlug::query()->count());
    }

    public function test_an_empty_slug_on_edit_keeps_the_current_one(): void
    {
        $profile = $this->leadershipProfile(['slug' => 'keep-me']);

        $this->actingAs($this->superAdmin())->put(route('admin.leadership.update', $profile), $this->leadershipForm(['slug' => '']))->assertSessionHasNoErrors();

        $this->assertSame('keep-me', $profile->fresh()->slug);
    }

    public function test_changing_the_address_keeps_the_old_one_in_history_and_nobody_can_take_it(): void
    {
        $profile = $this->leadershipProfile(['slug' => 'old-address']);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->put(route('admin.leadership.update', $profile), $this->leadershipForm(['slug' => 'new-address']))
            ->assertSessionHasNoErrors();

        $this->assertSame('new-address', $profile->fresh()->slug);
        $this->assertSame(['old-address'], LeadershipProfileSlug::query()->pluck('slug')->all());
        $this->assertSame($profile->id, LeadershipProfileSlug::query()->firstOrFail()->leadership_profile_id);

        // Another profile asking for the retired address gets a different one.
        $this->actingAs($admin)->post(route('admin.leadership.store'), $this->leadershipForm(['slug' => 'old-address']));
        $other = LeadershipProfile::query()->where('id', '!=', $profile->id)->firstOrFail();
        $this->assertSame('old-address-2', $other->slug);

        // ...while the profile that gave it up can take it back, and the address it leaves is then the one kept.
        $this->actingAs($admin)->put(route('admin.leadership.update', $profile), $this->leadershipForm(['slug' => 'old-address']));
        $this->assertSame('old-address', $profile->fresh()->slug);
        $this->assertSame(['new-address'], LeadershipProfileSlug::query()->pluck('slug')->all(), 'its address is never also in its own history');
    }

    public function test_an_address_taken_by_another_profile_gets_a_suffix_on_edit(): void
    {
        $this->leadershipProfile(['slug' => 'taken']);
        $profile = $this->leadershipProfile(['slug' => 'mine']);

        $this->actingAs($this->superAdmin())->put(route('admin.leadership.update', $profile), $this->leadershipForm(['slug' => 'taken']))
            ->assertSessionHas('success');

        $this->assertSame('taken-2', $profile->fresh()->slug);
        $this->assertStringContainsString('taken-2', (string) session('warning'), 'the admin is told the address differs from the one typed');
        $this->assertStringContainsString('"taken"', (string) session('warning'));

        // Saving again without touching the address says nothing.
        $this->actingAs($this->superAdmin())->put(route('admin.leadership.update', $profile), $this->leadershipForm(['slug' => 'taken-2']))
            ->assertSessionMissing('warning');
    }

    // --- update ------------------------------------------------------------------------------------------------------

    public function test_an_editor_without_the_publish_permission_changes_the_content_but_not_status_featuring_or_order(): void
    {
        $profile = $this->leadershipProfile(['status' => 'active', 'featured' => true, 'display_order' => 40, 'name_bn' => 'আগের নাম']);
        $editor = $this->userWith(['leadership.view', 'leadership.update']);

        $this->actingAs($editor)->put(route('admin.leadership.update', $profile), $this->leadershipForm([
            'slug' => $profile->slug, 'name_bn' => 'নতুন নাম', 'status' => 'inactive', 'featured' => '0', 'display_order' => '1',
        ]))->assertSessionHasNoErrors();

        $profile->refresh();
        $this->assertSame('নতুন নাম', $profile->name_bn);
        $this->assertSame('active', $profile->status);
        $this->assertTrue($profile->featured);
        $this->assertSame(40, $profile->display_order);
        $this->assertSame($editor->id, $profile->updated_by);
    }

    public function test_an_archived_profile_stays_archived_when_edited(): void
    {
        $profile = $this->leadershipProfile(['status' => 'archived', 'featured' => false, 'display_order' => 70]);

        $this->actingAs($this->superAdmin())->put(route('admin.leadership.update', $profile), $this->leadershipForm([
            'slug' => $profile->slug, 'status' => 'active', 'featured' => '1', 'display_order' => '1',
        ]))->assertSessionHasNoErrors();

        $profile->refresh();
        $this->assertSame('archived', $profile->status);
        $this->assertFalse($profile->featured);
        $this->assertSame(70, $profile->display_order);
    }

    public function test_a_failed_update_changes_nothing_and_keeps_what_was_typed(): void
    {
        $profile = $this->leadershipProfile(['name_bn' => 'অপরিবর্তিত']);

        $this->actingAs($this->superAdmin())->from(route('admin.leadership.edit', $profile))
            ->put(route('admin.leadership.update', $profile), $this->leadershipForm(['name_bn' => '<b>x</b>', 'name_en' => 'Typed English']))
            ->assertRedirect(route('admin.leadership.edit', $profile))->assertSessionHasErrors('name_bn')->assertSessionHasInput('name_en', 'Typed English');

        $this->assertSame('অপরিবর্তিত', $profile->fresh()->name_bn);
    }

    // --- status, featured, archive -----------------------------------------------------------------------------------

    public function test_a_profile_is_activated_and_deactivated(): void
    {
        $profile = $this->leadershipProfile(['status' => 'inactive']);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->patch(route('admin.leadership.status', $profile))->assertSessionHas('success');
        $this->assertSame('active', $profile->fresh()->status);

        $this->actingAs($admin)->patch(route('admin.leadership.status', $profile))->assertSessionHas('success');
        $this->assertSame('inactive', $profile->fresh()->status);
        $this->assertSame($admin->id, $profile->fresh()->updated_by);
    }

    public function test_an_archived_profile_cannot_be_activated_until_it_is_restored(): void
    {
        $profile = $this->leadershipProfile(['status' => 'archived']);

        $this->actingAs($this->superAdmin())->patch(route('admin.leadership.status', $profile))->assertSessionHas('error');
        $this->assertSame('archived', $profile->fresh()->status);
    }

    public function test_featuring_toggles_and_never_changes_the_order(): void
    {
        $first = $this->leadershipProfile(['display_order' => 10]);
        $second = $this->leadershipProfile(['display_order' => 20]);
        $before = $this->order();

        $this->actingAs($this->superAdmin())->patch(route('admin.leadership.featured', $second))->assertSessionHas('success');

        $this->assertTrue($second->fresh()->featured);
        $this->assertSame($before, $this->order(), 'featured only chooses what is prominent; it never reorders');

        $this->actingAs($this->superAdmin())->patch(route('admin.leadership.featured', $second))->assertSessionHas('success');
        $this->assertFalse($second->fresh()->featured);
        $this->assertFalse($first->fresh()->featured);
    }

    public function test_archiving_hides_unfeatures_and_keeps_the_address_and_restoring_comes_back_inactive_at_the_end(): void
    {
        $profile = $this->leadershipProfile(['slug' => 'kept-address', 'status' => 'active', 'featured' => true, 'display_order' => 10]);
        $this->leadershipProfile(['display_order' => 20]);
        $this->leadershipProfile(['display_order' => 30]);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.leadership.archive', $profile))->assertSessionHas('success');
        $profile->refresh();
        $this->assertSame('archived', $profile->status);
        $this->assertFalse($profile->featured);
        $this->assertSame('kept-address', $profile->slug);

        $this->actingAs($admin)->post(route('admin.leadership.restore', $profile))->assertSessionHas('success');
        $profile->refresh();
        $this->assertSame('inactive', $profile->status, 'restoring never puts anyone on the website by itself');
        $this->assertSame(40, $profile->display_order, 'a restored profile goes to the end of the order');
    }

    public function test_restoring_a_profile_that_is_not_archived_changes_nothing(): void
    {
        $profile = $this->leadershipProfile(['status' => 'active', 'display_order' => 10]);

        $this->actingAs($this->superAdmin())->post(route('admin.leadership.restore', $profile));

        $this->assertSame('active', $profile->fresh()->status);
        $this->assertSame(10, $profile->fresh()->display_order);
    }

    public function test_there_is_no_way_to_delete_a_profile(): void
    {
        $profile = $this->leadershipProfile();

        $this->actingAs($this->superAdmin())->delete(route('admin.leadership.update', $profile))->assertStatus(405);
        $this->assertNotNull($profile->fresh());
        $this->assertNull(app('router')->getRoutes()->getByName('admin.leadership.destroy'));
    }

    // --- order -------------------------------------------------------------------------------------------------------

    public function test_a_profile_moves_one_place_up_or_down(): void
    {
        $a = $this->leadershipProfile(['slug' => 'a', 'display_order' => 10]);
        $b = $this->leadershipProfile(['slug' => 'b', 'display_order' => 20]);
        $c = $this->leadershipProfile(['slug' => 'c', 'display_order' => 30]);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.leadership.move-down', $a));
        $this->assertSame(['b', 'a', 'c'], $this->order());

        $this->actingAs($admin)->post(route('admin.leadership.move-up', $c));
        $this->assertSame(['b', 'c', 'a'], $this->order());

        // At the ends there is nowhere to go.
        $this->actingAs($admin)->post(route('admin.leadership.move-up', $b));
        $this->actingAs($admin)->post(route('admin.leadership.move-down', $a));
        $this->assertSame(['b', 'c', 'a'], $this->order());
    }

    public function test_profiles_that_share_a_display_order_are_numbered_apart_so_a_move_really_moves(): void
    {
        $a = $this->leadershipProfile(['slug' => 'a', 'display_order' => 0]);
        $b = $this->leadershipProfile(['slug' => 'b', 'display_order' => 0]);
        $c = $this->leadershipProfile(['slug' => 'c', 'display_order' => 0]);
        $this->assertSame(['a', 'b', 'c'], $this->order(), 'ties are broken by id: the order is always the same');

        $this->actingAs($this->superAdmin())->post(route('admin.leadership.move-down', $a));

        $this->assertSame(['b', 'a', 'c'], $this->order());
        $this->assertCount(3, array_unique(LeadershipProfile::query()->pluck('display_order')->all()), 'no two profiles share a number any more');
    }

    public function test_the_archived_take_no_part_in_the_order(): void
    {
        $this->leadershipProfile(['slug' => 'a', 'display_order' => 10]);
        $this->leadershipProfile(['slug' => 'gone', 'display_order' => 15, 'status' => 'archived']);
        $b = $this->leadershipProfile(['slug' => 'b', 'display_order' => 20]);

        $this->actingAs($this->superAdmin())->post(route('admin.leadership.move-up', $b));

        $this->assertSame(['b', 'a'], $this->order());
        $this->assertSame(15, (int) LeadershipProfile::query()->where('slug', 'gone')->value('display_order'), 'the archived profile was not renumbered into the order');

        // An archived profile has no place to move to.
        $this->actingAs($this->superAdmin())->post(route('admin.leadership.move-up', LeadershipProfile::query()->where('slug', 'gone')->firstOrFail()));
        $this->assertSame(['b', 'a'], $this->order());
        $this->assertSame(15, (int) LeadershipProfile::query()->where('slug', 'gone')->value('display_order'));
    }

    // --- who may do what ---------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> method, route, the permission it needs */
    public static function protectedRoutes(): array
    {
        return [
            'index' => ['get', 'admin.leadership.index', 'leadership.view', ''],
            'create form' => ['get', 'admin.leadership.create', 'leadership.create', ''],
            'store' => ['post', 'admin.leadership.store', 'leadership.create', ''],
            'edit form' => ['get', 'admin.leadership.edit', 'leadership.update', 'profile'],
            'update' => ['put', 'admin.leadership.update', 'leadership.update', 'profile'],
            'preview' => ['get', 'admin.leadership.preview', 'leadership.view', 'profile'],
            'status' => ['patch', 'admin.leadership.status', 'leadership.publish', 'profile'],
            'featured' => ['patch', 'admin.leadership.featured', 'leadership.publish', 'profile'],
            'move up' => ['post', 'admin.leadership.move-up', 'leadership.publish', 'profile'],
            'move down' => ['post', 'admin.leadership.move-down', 'leadership.publish', 'profile'],
            'archive' => ['post', 'admin.leadership.archive', 'leadership.archive', 'profile'],
            'restore' => ['post', 'admin.leadership.restore', 'leadership.archive', 'profile'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_every_route_needs_its_own_permission(string $method, string $route, string $needs, string $binds): void
    {
        $profile = $this->leadershipProfile();
        $url = $binds === '' ? route($route) : route($route, $profile);
        $payload = in_array($method, ['post', 'put'], true) ? $this->leadershipForm(['slug' => $profile->slug]) : [];
        $all = array_map(fn (string $action) => "leadership.{$action}", ['view', 'create', 'update', 'publish', 'archive']);

        // Signed out: sent to the login, nothing happens.
        $this->{$method}($url, $payload)->assertRedirect(route('login'));

        // Every OTHER leadership permission is not enough.
        $others = $this->userWith(array_values(array_diff($all, [$needs])));
        $this->actingAs($others)->{$method}($url, $payload)->assertForbidden();

        // A user with other modules' permissions only.
        $unrelated = $this->userWith(['notices.view', 'settings.view', 'organization.view', 'membership.view']);
        $this->actingAs($unrelated)->{$method}($url, $payload)->assertForbidden();

        // The one permission it needs is enough to get past the gate (what the screen then does is tested elsewhere).
        $allowed = $this->userWith([$needs, 'leadership.view']);
        $status = $this->actingAs($allowed)->{$method}($url, $payload)->getStatusCode();
        $this->assertNotContains($status, [401, 403, 404, 405], "{$route} must be reachable with {$needs}");
    }

    public function test_the_other_seeded_roles_have_no_access_and_the_super_admin_has_every_permission(): void
    {
        foreach (['regional_admin', 'membership_admin'] as $slug) {
            $role = Role::query()->where('slug', $slug)->firstOrFail();
            $this->assertSame(0, $role->permissions()->where('module', 'leadership')->count(), "{$slug} must not get Leadership by default");
        }

        $super = Role::query()->where('slug', 'super_admin')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            ['leadership.view', 'leadership.create', 'leadership.update', 'leadership.publish', 'leadership.archive'],
            $super->permissions()->where('module', 'leadership')->pluck('slug')->all(),
        );
    }

    public function test_the_sidebar_offers_leadership_only_to_someone_who_may_view_it(): void
    {
        $this->actingAs($this->superAdmin())->get(route('admin.dashboard'))->assertOk()->assertSee(route('admin.leadership.index'), false);
        $this->actingAs($this->userWith(['leadership.view']))->get(route('admin.leadership.index'))->assertOk()->assertSee(route('admin.leadership.index'), false);
        $this->actingAs($this->userWith(['notices.view']))->get(route('admin.dashboard'))->assertOk()->assertDontSee(route('admin.leadership.index'), false);
    }

    public function test_the_create_and_publish_controls_follow_the_permissions(): void
    {
        $profile = $this->leadershipProfile();

        $viewer = $this->userWith(['leadership.view']);
        $this->actingAs($viewer)->get(route('admin.leadership.index'))->assertOk()
            ->assertDontSee(route('admin.leadership.create'), false)
            ->assertDontSee(route('admin.leadership.archive', $profile), false)
            ->assertDontSee(route('admin.leadership.status', $profile), false);

        $editor = $this->userWith(['leadership.update']);
        $this->actingAs($editor)->get(route('admin.leadership.edit', $profile))->assertOk()
            ->assertDontSee('name="featured"', false)
            ->assertDontSee('name="display_order"', false)
            ->assertSee('প্রকাশের অনুমতি আছে এমন ব্যক্তি'); // the Bangla publish_locked_notice (a new user's admin language is Bangla)

        $this->actingAs($this->superAdmin())->get(route('admin.leadership.edit', $profile))->assertOk()
            ->assertSee('name="featured"', false)->assertSee('name="display_order"', false);
    }

    // --- the screens -------------------------------------------------------------------------------------------------

    public function test_the_form_posts_only_underscore_field_names_and_every_field_the_controller_reads(): void
    {
        $html = $this->actingAs($this->superAdmin())->get(route('admin.leadership.create'))->assertOk()->getContent();

        preg_match_all('/<(?:input|textarea|select)\b[^>]*\bname="([^"]+)"/', $html, $found);
        $names = array_unique($found[1]);

        foreach ($names as $name) {
            if (in_array($name, ['_token', '_method', 'locale'], true)) {
                continue;
            }
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/', $name, "'{$name}' would be rewritten by PHP");
        }
        foreach (['name_bn', 'name_en', 'designation_bn', 'designation_en', 'slug', 'short_bio_bn', 'short_bio_en', 'full_bio_bn', 'full_bio_en',
            'quote_bn', 'quote_en', 'expertise_bn', 'expertise_en', 'associated_since_year', 'photo', 'public_email', 'public_phone',
            'facebook_url', 'linkedin_url', 'youtube_url', 'website_url', 'status', 'featured', 'display_order'] as $field) {
            $this->assertContains($field, $names, "the form is missing '{$field}'");
        }
    }

    public function test_the_edit_form_shows_every_saved_value(): void
    {
        $profile = $this->leadershipProfile([
            'name_bn' => 'সংরক্ষিত নাম', 'name_en' => 'Saved Name', 'designation_en' => 'Saved Designation',
            'short_bio_en' => 'Saved short bio', 'full_bio_bn' => 'সংরক্ষিত পূর্ণ পরিচিতি', 'quote_en' => 'Saved quote',
            'expertise_en' => 'Saved expertise', 'associated_since_year' => 2018, 'public_phone' => '+8801711000000',
            'website_url' => 'https://example.org/saved',
        ]);

        $this->actingAs($this->superAdmin())->get(route('admin.leadership.edit', $profile))->assertOk()
            ->assertSee('সংরক্ষিত নাম')->assertSee('Saved Name')->assertSee('Saved Designation')->assertSee('Saved short bio')
            ->assertSee('সংরক্ষিত পূর্ণ পরিচিতি')->assertSee('Saved quote')->assertSee('Saved expertise')->assertSee('2018')
            ->assertSee('+8801711000000')->assertSee('https://example.org/saved')->assertSee($profile->slug);
    }

    public function test_the_screens_render_in_both_admin_languages_without_a_missing_translation_or_a_doubly_escaped_character(): void
    {
        $profile = $this->leadershipProfile(['name_en' => "O'Neil & Sons", 'designation_en' => 'Director']);

        foreach (['bn', 'en'] as $locale) {
            $admin = $this->superAdmin();
            $admin->forceFill(['ui_locale' => $locale])->save();

            foreach ([route('admin.leadership.index'), route('admin.leadership.create'), route('admin.leadership.edit', $profile), route('admin.leadership.preview', $profile)] as $url) {
                $html = $this->actingAs($admin)->get($url)->assertOk()->assertDontSee('admin.leadership.', false)->assertDontSee('admin.nav.', false)->getContent();
                $this->assertDoesNotMatchRegularExpression('/&amp;(#\d+|[a-z]+);/i', $html, "a doubly escaped entity on {$url} ({$locale})");
            }
        }
    }

    public function test_a_stored_script_in_a_name_is_shown_as_text_not_run(): void
    {
        $profile = $this->leadershipProfile(['name_bn' => '<script>alert("x")</script>', 'name_en' => '<img src=x onerror=alert(1)>', 'full_bio_en' => "<script>alert('bio')</script>"]);
        $admin = $this->superAdmin();

        foreach ([route('admin.leadership.index'), route('admin.leadership.edit', $profile), route('admin.leadership.preview', $profile)] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert(', $html);
            $this->assertStringNotContainsString('<img src=x onerror', $html);
        }
    }

    public function test_the_preview_shows_both_languages_and_the_translation_note_where_english_is_missing(): void
    {
        $profile = $this->leadershipProfile([
            'status' => 'inactive', 'name_bn' => 'বাংলা নাম', 'name_en' => 'English Name', 'designation_bn' => 'বাংলা পদবি', 'designation_en' => null,
            'full_bio_bn' => "প্রথম অনুচ্ছেদ\n\nদ্বিতীয় অনুচ্ছেদ", 'full_bio_en' => null,
        ]);

        $html = $this->actingAs($this->superAdmin())->get(route('admin.leadership.preview', $profile))->assertOk()
            ->assertSee('এই প্রোফাইল প্রকাশ্য নয়')->getContent(); // the Bangla preview_hidden

        $this->assertStringContainsString('English Name', $html);
        $this->assertStringContainsString('বাংলা পদবি', $html);
        $this->assertStringContainsString('The English translation for this section is not yet available.', $html, 'the English pane says so where it falls back to Bangla');
        $this->assertStringContainsString('প্রথম অনুচ্ছেদ', $html);
    }

    // --- what every change does to the public site -------------------------------------------------------------------

    public function test_every_change_that_shows_on_the_website_tells_the_site_before_the_admin_sees_saved(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.leadership.store'), $this->leadershipForm(['status' => 'active']))->assertSessionHasNoErrors();
        $this->assertSame([[PublicSiteRevalidator::LEADERSHIP_TAG]], $this->calls(), 'create');
        $profile = LeadershipProfile::query()->firstOrFail();

        $steps = [
            'update' => fn () => $this->put(route('admin.leadership.update', $profile), $this->leadershipForm(['slug' => $profile->slug, 'name_bn' => 'সম্পাদিত'])),
            'status' => fn () => $this->patch(route('admin.leadership.status', $profile)),
            'featured' => fn () => $this->patch(route('admin.leadership.featured', $profile)),
            'archive' => fn () => $this->post(route('admin.leadership.archive', $profile)),
            'restore' => fn () => $this->post(route('admin.leadership.restore', $profile)),
        ];
        foreach ($steps as $label => $step) {
            $before = count($this->calls());
            $step()->assertSessionHasNoErrors();
            $this->assertCount($before + 1, $this->calls(), "{$label} must send exactly one call");
            $this->assertSame([PublicSiteRevalidator::LEADERSHIP_TAG], $this->calls()[$before], $label);
        }
    }

    public function test_a_reorder_is_one_call_even_though_two_profiles_change(): void
    {
        $a = $this->leadershipProfile(['display_order' => 10]);
        $this->leadershipProfile(['display_order' => 20]);

        $this->actingAs($this->superAdmin())->post(route('admin.leadership.move-down', $a))->assertSessionHasNoErrors();

        $this->assertSame([[PublicSiteRevalidator::LEADERSHIP_TAG]], $this->calls());
    }

    public function test_a_refused_save_tells_the_site_nothing(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.leadership.store'), $this->leadershipForm(['facebook_url' => 'javascript:alert(1)']))
            ->assertSessionHasErrors('facebook_url');

        $this->assertSame([], $this->calls());
    }

    public function test_an_unreachable_public_site_never_breaks_a_save(): void
    {
        Http::swap((new HttpFactory)->preventStrayRequests());
        Http::fake([self::URL => Http::response('down', 503)]);

        $this->actingAs($this->superAdmin())->post(route('admin.leadership.store'), $this->leadershipForm())->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(1, LeadershipProfile::query()->count());
    }
}
