<?php

namespace Tests\Concerns;

use App\Models\LeadershipProfile;
use Illuminate\Support\Str;

/** Leadership profiles for tests: a plain model write, no controller, so a test starts from exactly the rows it names. */
trait MakesLeadershipProfiles
{
    private int $leadershipSequence = 0;

    /** @param array<string, mixed> $overrides */
    protected function leadershipProfile(array $overrides = []): LeadershipProfile
    {
        $n = ++$this->leadershipSequence;

        return LeadershipProfile::query()->create(array_merge([
            'slug' => 'profile-'.$n.'-'.Str::lower(Str::random(4)),
            'name_bn' => 'প্রোফাইল '.$n,
            'designation_bn' => 'পদবি '.$n,
            'status' => LeadershipProfile::STATUS_ACTIVE,
            'featured' => false,
            'display_order' => $n * LeadershipProfile::ORDER_STEP,
        ], $overrides));
    }

    /**
     * The body of the create/edit form as the screen posts it (underscore field names only), for a profile with the
     * minimum the form requires.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function leadershipForm(array $overrides = []): array
    {
        return array_merge([
            'name_bn' => 'পরীক্ষামূলক ব্যক্তি',
            'name_en' => 'Test Person',
            'designation_bn' => 'পরীক্ষামূলক পদবি',
            'designation_en' => 'Test designation',
        ], $overrides);
    }
}
