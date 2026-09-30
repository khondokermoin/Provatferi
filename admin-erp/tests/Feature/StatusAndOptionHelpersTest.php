<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Notice;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * Phase 3 — regression coverage for status_options()/option_label()/
 * option_options(), the helpers that let controller-generated dropdown
 * labels (previously read straight off a Model's own STATUSES/TYPES
 * constant, always in Bangla) follow the admin's chosen locale instead.
 *
 * Committee::STATUSES['archived'] is deliberately used here: its own raw
 * value ("আর্কাইভ") differs from the canonical statuses.php value for the
 * same slug ("সংরক্ষিত") — a real, pre-existing drift between this
 * constant and the status-badge component, which already read the
 * canonical catalogue via status_label(). status_options() must resolve
 * to the canonical value, not the constant's own, to close that gap
 * rather than adding a second one.
 */
class StatusAndOptionHelpersTest extends TestCase
{
    public function test_status_options_uses_the_canonical_catalogue_not_the_constants_own_value(): void
    {
        App::setLocale('bn');

        $options = status_options(Committee::STATUSES);

        $this->assertSame('সংরক্ষিত', $options['archived']);
        $this->assertSame(array_keys(Committee::STATUSES), array_keys($options));
    }

    public function test_status_options_respects_the_current_locale(): void
    {
        App::setLocale('en');

        $options = status_options(Committee::STATUSES);

        $this->assertSame('Archived', $options['archived']);
        $this->assertSame('Active', $options['active']);
    }

    public function test_option_options_reads_from_the_options_catalogue(): void
    {
        App::setLocale('bn');
        $bn = option_options('notice_types', Notice::TYPES);
        $this->assertSame('সাধারণ বিজ্ঞপ্তি', $bn['general']);

        App::setLocale('en');
        $en = option_options('notice_types', Notice::TYPES);
        $this->assertSame('General notice', $en['general']);

        // The key set always matches the source constant, regardless of locale.
        $this->assertSame(array_keys(Notice::TYPES), array_keys($en));
    }

    public function test_option_label_falls_back_to_a_humanised_key_when_missing(): void
    {
        $this->assertSame('Some Unknown Key', option_label('notice_types', 'some_unknown_key'));
    }

    public function test_option_options_accepts_a_plain_list_of_keys_not_only_a_map(): void
    {
        App::setLocale('bn');

        $options = option_options('field_requirement_levels', ['required', 'optional']);

        $this->assertSame(['required' => 'আবশ্যক', 'optional' => 'ঐচ্ছিক'], $options);
    }

    public function test_upload_error_label_translates_a_known_message_under_english_locale(): void
    {
        App::setLocale('en');

        $this->assertSame(
            'Only PDF files are accepted.',
            upload_error_label('শুধুমাত্র PDF ফাইল গ্রহণযোগ্য।'),
        );
    }

    public function test_upload_error_label_leaves_the_message_untouched_under_bangla_locale(): void
    {
        App::setLocale('bn');

        $this->assertSame(
            'শুধুমাত্র PDF ফাইল গ্রহণযোগ্য।',
            upload_error_label('শুধুমাত্র PDF ফাইল গ্রহণযোগ্য।'),
        );
    }

    public function test_upload_error_label_returns_an_unrecognised_message_unchanged_under_english_locale(): void
    {
        App::setLocale('en');

        $this->assertSame('কিছু একটা ভুল হয়েছে।', upload_error_label('কিছু একটা ভুল হয়েছে।'));
    }
}
