<?php

namespace Tests\Unit\Support;

use App\Support\LeadershipProfileInput;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The one definition of what a Leadership profile may contain (docs/LEADERSHIP_SYSTEM.md): which fields exist for a role that
 * may publish and for one that may not, what is cleaned silently, what is refused, and how every value is stored.
 */
class LeadershipProfileInputTest extends TestCase
{
    private function validate(array $input, bool $canPublish = true): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($input + ['name_bn' => 'নাম', 'designation_bn' => 'পদবি'], LeadershipProfileInput::rules($canPublish), [], LeadershipProfileInput::attributes());
    }

    // --- clean -------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: bool, 2: string}> input, multiline, expected */
    public static function cleaning(): array
    {
        return [
            'surrounding space and runs of space' => ["  Test    Person  ", false, 'Test Person'],
            'a tab and a line break in one line become one space' => ["Test\t\nPerson", false, 'Test Person'],
            'a control character is dropped' => ["Te\x07st\x00 Person\x7F", false, 'Test Person'],
            'a right-to-left override is dropped' => ["Test\u{202E}Person", false, 'TestPerson'],
            'an isolate and a mark are dropped' => ["Dir\u{2066}ector\u{200E}\u{FEFF}", false, 'Director'],
            'the joiners bengali needs are kept' => ["র\u{200C}নি \u{200D}", false, "র\u{200C}নি \u{200D}"],
            'windows line endings become one kind' => ["One\r\nTwo\rThree", true, "One\nTwo\nThree"],
            'a long run of blank lines becomes one blank line' => ["One\n\n\n\n\nTwo", true, "One\n\nTwo"],
            'trailing spaces before a line break go' => ["One   \nTwo\t\n", true, "One\nTwo"],
            'a single blank line is kept' => ["One\n\nTwo", true, "One\n\nTwo"],
            'bullets and their characters are kept' => ["• one\n- two", true, "• one\n- two"],
            'an entity-looking string is just text' => ['Fish &amp; chips', false, 'Fish &amp; chips'],
        ];
    }

    #[DataProvider('cleaning')]
    public function test_clean_removes_only_what_nobody_types_on_purpose(string $input, bool $multiline, string $expected): void
    {
        $this->assertSame($expected, LeadershipProfileInput::clean($input, $multiline));
    }

    // --- rules -------------------------------------------------------------------------------------------------------

    public function test_the_publishing_fields_are_rules_only_for_someone_who_may_publish(): void
    {
        $publisher = LeadershipProfileInput::rules(true);
        $writer = LeadershipProfileInput::rules(false);

        foreach (['status', 'featured', 'display_order'] as $field) {
            $this->assertArrayHasKey($field, $publisher);
            $this->assertArrayNotHasKey($field, $writer);
        }
    }

    public function test_every_field_of_the_form_has_a_label_in_both_languages(): void
    {
        foreach (['bn', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach (array_keys(LeadershipProfileInput::rules(true)) as $field) {
                if (in_array($field, ['remove_photo'], true)) {
                    continue;
                }
                $label = LeadershipProfileInput::attributes()[$field] ?? null;
                $this->assertNotNull($label, "{$field} has no label ({$locale})");
                $this->assertStringNotContainsString('admin.', (string) $label, "{$field} shows a raw language key ({$locale})");
            }
        }
    }

    public function test_the_minimum_is_a_bangla_name_and_designation(): void
    {
        $this->assertTrue($this->validate([])->passes(), 'validate() supplies the two required Bangla fields');

        $empty = Validator::make([], LeadershipProfileInput::rules(true));
        $this->assertTrue($empty->fails());
        $this->assertEqualsCanonicalizing(['name_bn', 'designation_bn'], array_keys($empty->errors()->messages()));
    }

    public function test_markup_is_refused_but_ordinary_angle_brackets_are_not(): void
    {
        foreach (['<b>x</b>', 'a <i>b</i>', '</p>', '<!-- c -->', '<?php', '< script>', '<  a href=x>'] as $text) {
            $this->assertTrue($this->validate(['short_bio_en' => $text])->fails(), "should refuse: {$text}");
        }
        foreach (['5 < 10', 'a > b', 'x<1', '3 <= 4', 'I <3 books', 'a <- b'] as $text) {
            $this->assertTrue($this->validate(['short_bio_en' => $text])->passes(), "should allow: {$text}");
        }
    }

    // --- normalize ---------------------------------------------------------------------------------------------------

    public function test_an_empty_value_is_stored_as_null_and_an_absent_field_is_left_out(): void
    {
        $out = LeadershipProfileInput::normalize(['name_en' => '   ', 'short_bio_bn' => '', 'public_email' => null], false);

        $this->assertSame(['name_en' => null, 'short_bio_bn' => null, 'public_email' => null], $out);
        $this->assertArrayNotHasKey('designation_bn', $out, 'a field that was not submitted must not be blanked');
    }

    public function test_links_e_mail_and_phone_are_stored_normalised(): void
    {
        $out = LeadershipProfileInput::normalize([
            'facebook_url' => 'HTTPS://WWW.FACEBOOK.COM/Page#x', 'linkedin_url' => '', 'youtube_url' => 'https://www.youtube.com/@a',
            'website_url' => 'https://EXAMPLE.org:443/a', 'public_email' => '  Person@Example.ORG ', 'public_phone' => "  +880   1711 000000 ",
            'associated_since_year' => '2019',
        ], false);

        $this->assertSame('https://www.facebook.com/Page', $out['facebook_url']);
        $this->assertNull($out['linkedin_url']);
        $this->assertSame('https://www.youtube.com/@a', $out['youtube_url']);
        $this->assertSame('https://example.org/a', $out['website_url']);
        $this->assertSame('person@example.org', $out['public_email']);
        $this->assertSame('+880 1711 000000', $out['public_phone']);
        $this->assertSame(2019, $out['associated_since_year']);
    }

    public function test_status_featured_and_order_are_read_only_when_the_writer_may_publish(): void
    {
        $input = ['status' => 'active', 'featured' => '1', 'display_order' => '40', 'name_bn' => 'নাম'];

        $writer = LeadershipProfileInput::normalize($input, false);
        $this->assertSame(['name_bn' => 'নাম'], $writer);

        $publisher = LeadershipProfileInput::normalize($input, true);
        $this->assertSame('active', $publisher['status']);
        $this->assertTrue($publisher['featured']);
        $this->assertSame(40, $publisher['display_order']);
    }

    public function test_a_switch_that_is_off_is_false_not_missing(): void
    {
        $this->assertFalse(LeadershipProfileInput::normalize(['featured' => '0'], true)['featured']);
        $this->assertArrayNotHasKey('featured', LeadershipProfileInput::normalize([], true));
    }
}
