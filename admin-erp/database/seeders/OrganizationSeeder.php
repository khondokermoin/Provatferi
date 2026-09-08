<?php

namespace Database\Seeders;

use App\Models\ActivityType;
use App\Models\MembershipType;
use App\Models\OrganizationalPosition;
use App\Models\OrganizationalUnit;
use Illuminate\Database\Seeder;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        OrganizationalUnit::query()->firstOrCreate(
            ['slug' => 'provatferi-central'],
            [
                'name' => 'প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র',
                'unit_type' => 'central',
                'address' => 'লেবাশ, ডাকঘর দোল্লাই নোয়াবপুর, ইউনিয়ন দোল্লাই নোয়াবপুর, উপজেলা চান্দিনা, জেলা কুমিল্লা, বিভাগ চট্টগ্রাম',
                'status' => 'active',
            ],
        );

        $positions = [
            ['name' => 'প্রতিষ্ঠাতা ও নির্বাহী পরিচালক', 'slug' => 'founder-executive-director', 'level' => 0],
            ['name' => 'সভাপতি', 'slug' => 'president', 'level' => 1],
            ['name' => 'সহ-সভাপতি', 'slug' => 'vice-president', 'level' => 2],
            ['name' => 'সাধারণ সম্পাদক', 'slug' => 'general-secretary', 'level' => 2],
            ['name' => 'কোষাধ্যক্ষ', 'slug' => 'treasurer', 'level' => 3],
            ['name' => 'কার্যনির্বাহী সদস্য', 'slug' => 'executive-member', 'level' => 5],
        ];
        foreach ($positions as $position) {
            OrganizationalPosition::query()->firstOrCreate(['slug' => $position['slug']], $position);
        }

        $activityTypes = [
            ['name' => 'পাঠচক্র ও বইপড়া', 'slug' => 'reading-circle'],
            ['name' => 'সাংস্কৃতিক কার্যক্রম', 'slug' => 'cultural'],
            ['name' => 'সামাজিক সচেতনতা', 'slug' => 'social-awareness'],
            ['name' => 'মানবিক কার্যক্রম', 'slug' => 'humanitarian'],
        ];
        foreach ($activityTypes as $type) {
            ActivityType::query()->firstOrCreate(['slug' => $type['slug']], $type);
        }

        $membershipTypes = [
            ['name' => 'সাধারণ সদস্য', 'slug' => 'general', 'description' => 'সকল প্রাপ্তবয়স্ক আগ্রহী ব্যক্তির জন্য'],
            ['name' => 'শিক্ষার্থী সদস্য', 'slug' => 'student', 'description' => 'স্কুল-কলেজ-বিশ্ববিদ্যালয় শিক্ষার্থীদের জন্য', 'is_student' => true],
            ['name' => 'আজীবন সদস্য', 'slug' => 'life', 'description' => 'দীর্ঘমেয়াদী সম্পৃক্ততা ইচ্ছুকদের জন্য'],
            ['name' => 'সম্মানসূচক সদস্য', 'slug' => 'honorary', 'description' => 'বিশেষ অবদানের স্বীকৃতিস্বরূপ'],
        ];
        foreach ($membershipTypes as $type) {
            MembershipType::query()->firstOrCreate(['slug' => $type['slug']], $type);
        }
    }
}
