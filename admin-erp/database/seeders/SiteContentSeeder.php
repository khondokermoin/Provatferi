<?php

namespace Database\Seeders;

use App\Models\AboutPage;
use App\Models\ContentBlock;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Seeds real, already-established Provatferi content — the same facts
 * currently hard-coded in institutional/lib/content.ts and the live site's
 * <head> metadata — so the future API can be pointed at without inventing
 * placeholder text. Fields with no established real content (About's
 * history, why_exists, identity_explanation) are left null for an admin to
 * fill in, never fabricated.
 */
class SiteContentSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // Identity
            'site.name_bn' => 'প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র',
            'site.name_en' => 'Provatferi Literary and Cultural Center',
            'site.short_name' => 'Provatferi',
            // Documented secondary alias — see institutional/app/layout.tsx structured data.
            'site.acronym' => 'PLCC',
            'site.tagline' => 'শিক্ষা • সাহিত্য • সংস্কৃতি • মানবতা',

            // Contact — canonical public values (2026-09-08 decision). The old
            // gmail address is retired as the canonical public contact.
            'site.email' => 'info@provatferi.org',
            'site.phone' => '+8801625050408',
            'site.address' => 'লেবাশ, ডাকঘর দোল্লাই নোয়াবপুর, ইউনিয়ন দোল্লাই নোয়াবপুর, উপজেলা চান্দিনা, জেলা কুমিল্লা, বিভাগ চট্টগ্রাম',
            'site.facebook_url' => 'https://fb.com/provatfericenter',

            // SEO/entity — copied verbatim from the live institutional site's
            // <head> metadata, not reworded here.
            'site.seo_title' => 'প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র',
            'site.seo_description' => 'Provatferi Literary and Cultural Center — শিক্ষা, সাহিত্য, সংস্কৃতি ও মানবিক কার্যক্রমের একটি অলাভজনক প্রতিষ্ঠান।',

            // Related site links.
            'site.website_url' => 'https://provatferi.org',
            'site.literature_url' => 'https://sahittopata.provatferi.org',
        ];

        foreach ($settings as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'value_type' => 'string', 'group_name' => 'site']);
        }

        // Alternate names, matching the live Organization structured data exactly.
        Setting::query()->updateOrCreate(
            ['key' => 'site.alternate_names'],
            [
                'value' => json_encode(['PLCC', 'প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র', 'Provatferi'], JSON_UNESCAPED_UNICODE),
                'value_type' => 'json',
                'group_name' => 'site',
            ],
        );

        // Superseded by about_page.registration_status below — no longer a
        // generic setting.
        Setting::query()->where('key', 'site.founded_note')->delete();

        $contentBlocks = [
            [
                'key' => 'about.vision',
                'title' => 'Vision',
                'body' => 'আমাদের স্বপ্ন হলো এমন একটি আলোকিত, মানবিক ও সৃজনশীল সমাজ গড়ে তোলা, যেখানে মানুষ বই পড়বে, জ্ঞানচর্চা করবে, সাহিত্য ও সংস্কৃতির সঙ্গে যুক্ত থাকবে এবং নিজের পাশাপাশি সমাজের জন্যও দায়িত্বশীল হয়ে উঠবে।',
            ],
            [
                'key' => 'about.mission',
                'title' => 'Mission',
                'body' => 'বইপড়া, সাহিত্য, সংস্কৃতি, সৃজনশীলতা ও মানবিক সামাজিক কর্মকাণ্ডের মাধ্যমে শিশু-কিশোর, তরুণ ও সাধারণ মানুষের মধ্যে জ্ঞান, নৈতিকতা, সচেতনতা ও দায়িত্ববোধের বিকাশ ঘটানো এবং একটি আলোকিত ও মানবিক সমাজ নির্মাণে অবদান রাখা।',
            ],
        ];

        foreach ($contentBlocks as $block) {
            ContentBlock::query()->updateOrCreate(['key' => $block['key']], [...$block, 'group_name' => 'about']);
        }

        // Replaced by the ordered `objectives` table — see ObjectivesSeeder.
        ContentBlock::query()->where('key', 'about.objectives')->delete();

        // About page — only the two fields with real, already-live wording are
        // filled in; everything else stays null until an admin writes it.
        $about = AboutPage::current();
        $about->fill([
            'description' => 'প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র (Provatferi Literary and Cultural Center) একটি অরাজনৈতিক, অলাভজনক ও স্বেচ্ছাসেবী সাহিত্য, সংস্কৃতি, শিক্ষা ও সমাজ-সচেতনতামূলক প্রতিষ্ঠান।',
            'registration_status' => 'প্রস্তাবিত প্রতিষ্ঠাকাল ২০১৯ (নিবন্ধন প্রক্রিয়াধীন)',
        ])->save();
    }
}
