<?php

namespace Database\Seeders;

use App\Models\Objective;
use Illuminate\Database\Seeder;

/**
 * The same 12 real objectives previously stored as one JSON blob in
 * content_blocks('about.objectives') — copied verbatim, now as individually
 * ordered records. See institutional/lib/content.ts `objectives`.
 */
class ObjectivesSeeder extends Seeder
{
    public function run(): void
    {
        $objectives = [
            'সমাজে বইপড়া ও জ্ঞানচর্চার সংস্কৃতি গড়ে তোলা।',
            'শিশু-কিশোর, তরুণ ও সাধারণ মানুষের মধ্যে পাঠাভ্যাস বৃদ্ধি করা।',
            'একটি উন্মুক্ত ও মানবিক পাঠাগার সংস্কৃতি গড়ে তোলা।',
            'সাহিত্য, সংস্কৃতি ও সৃজনশীল চর্চার বিকাশ ঘটানো।',
            'আবৃত্তি, সংগীত, নাটক, সাহিত্য আলোচনা ও সাংস্কৃতিক কর্মকাণ্ডের সুযোগ সৃষ্টি করা।',
            'স্কুল ও শিক্ষা প্রতিষ্ঠান ভিত্তিক বইপড়া ও পাঠচক্র গড়ে তোলা।',
            'মাদক, অপরাধ, সহিংসতা ও সামাজিক অবক্ষয়ের বিরুদ্ধে সচেতনতা সৃষ্টি করা।',
            'পরিবেশ সংরক্ষণ, পরিচ্ছন্নতা ও সামাজিক দায়িত্ববোধ সম্পর্কে মানুষকে সচেতন করা।',
            'নারী, শিশু-কিশোর ও যুবসমাজের ইতিবাচক বিকাশে সহায়ক কার্যক্রম পরিচালনা করা।',
            'মানবিক, সামাজিক ও সমাজকল্যাণমূলক কর্মকাণ্ড পরিচালনা করা।',
            'স্থানীয় পর্যায়ে সাহিত্যিক, সাংস্কৃতিক ও সৃজনশীল প্রতিভা বিকাশের সুযোগ সৃষ্টি করা।',
            'জ্ঞান, মানবিকতা, অসাম্প্রদায়িকতা, সহমর্মিতা, নৈতিকতা ও সামাজিক দায়িত্ববোধকে উৎসাহিত করা।',
        ];

        foreach ($objectives as $index => $body) {
            Objective::query()->firstOrCreate(
                ['body' => $body],
                ['sort_order' => $index + 1, 'active' => true],
            );
        }
    }
}
