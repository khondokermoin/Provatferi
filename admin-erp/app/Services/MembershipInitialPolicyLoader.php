<?php

namespace App\Services;

use App\Models\MembershipFeePolicy;
use App\Models\MembershipType;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * THE ONE-TIME DATA LOAD of the owner's approved fee schedule (Membership Registry, task 1, 2026-10-05).
 *
 * THIS IS DATA, NOT BUSINESS LOGIC. The three figures below are what the owner approved on 2026-10-05 and are
 * written into the database exactly once, as ordinary policy rows. After that the database is the only source of truth:
 * nothing in the application reads this table, an admin changes a fee by creating a new dated policy version, and a
 * second run of this loader changes nothing.
 *
 *   Lifetime  LM  registration 500  monthly 200      General  GM  registration 100  monthly 0      Student  ST  0 / 0
 *
 * What it does, for the membership types that already exist (it never creates a type):
 *   1. MATCHES the three owner types by their seeded slug (life / general / student) AND their Bangla name. A type that
 *      matches by slug but not by name, a code already taken by another type, or two matches for one entry is a PROBLEM:
 *      nothing is written at all and the caller is told why. Uncertain production data is never guessed at.
 *   2. For a matched type: sets its code (only if it has none), its English name (only if it has none), and, if every
 *      type still has the factory-default order 0, the display order of the owner's list.
 *   3. Creates the first fee policy of every type that has NONE, effective today (the organisation's calendar). A type
 *      that is not on the owner's list (honorary) gets a CARRY-OVER policy built from its legacy flat fee (registration =
 *      that fee, monthly 0) — the status quo, labelled as such in the note, not an owner-approved figure. A type that
 *      already has any policy is left alone.
 *
 * It runs inside one transaction: all of it, or none of it.
 */
class MembershipInitialPolicyLoader
{
    /**
     * The owner-approved schedule, keyed by the seeded slug of the type it applies to.
     *
     * @var array<string, array{code:string, name:string, name_en:string, registration:string, monthly:string, order:int}>
     */
    public const OWNER_APPROVED = [
        'life' => ['code' => 'LM', 'name' => 'আজীবন সদস্য', 'name_en' => 'Lifetime Member', 'registration' => '500', 'monthly' => '200', 'order' => 1],
        'general' => ['code' => 'GM', 'name' => 'সাধারণ সদস্য', 'name_en' => 'General Member', 'registration' => '100', 'monthly' => '0', 'order' => 2],
        'student' => ['code' => 'ST', 'name' => 'শিক্ষার্থী সদস্য', 'name_en' => 'Student Member', 'registration' => '0', 'monthly' => '0', 'order' => 3],
    ];

    public const NOTE_OWNER_APPROVED = 'প্রাথমিক নীতি — মালিক-অনুমোদিত ফি তালিকা / Initial policy — owner-approved fee schedule';

    public const NOTE_CARRY_OVER = 'প্রাথমিক নীতি — আগের নির্ধারিত ফি থেকে নেওয়া, মালিক-অনুমোদিত তালিকায় নেই / Initial policy — carried over from the previous flat fee (not part of the owner-approved schedule)';

    public function __construct(private readonly MembershipFeePolicyService $fees) {}

    /**
     * What a run would do, writing nothing.
     *
     * @return array{ok: bool, today: string, problems: array<int,string>, types: array<int, array<string,mixed>>}
     */
    public function plan(): array
    {
        $today = $this->fees->today();
        $types = MembershipType::query()->orderBy('id')->get();
        $problems = [];
        $rows = [];

        $byCode = $types->filter(fn (MembershipType $t) => $t->code !== null)->groupBy('code');
        $untouched = $types->every(fn (MembershipType $t) => (int) $t->sort_order === 0);
        $nextOrder = count(self::OWNER_APPROVED) + 1;

        $matchedSlugs = [];
        foreach ($types as $type) {
            $owner = self::OWNER_APPROVED[$type->slug] ?? null;
            $row = ['type_id' => $type->id, 'slug' => $type->slug, 'name' => $type->name, 'kind' => $owner ? 'owner-approved' : 'carry-over', 'actions' => [], 'skipped' => []];

            if ($owner !== null) {
                $matchedSlugs[$type->slug] = ($matchedSlugs[$type->slug] ?? 0) + 1;
                if ($type->name !== $owner['name']) {
                    $problems[] = "type #{$type->id} has the slug '{$type->slug}' but its name is '{$type->name}', not '{$owner['name']}' — not matched, nothing written";
                }
                $taken = $byCode->get($owner['code']);
                if ($taken !== null && $taken->contains(fn (MembershipType $t) => $t->id !== $type->id)) {
                    $problems[] = "the code {$owner['code']} is already used by another type — nothing written";
                }
                if ($type->code !== null && $type->code !== $owner['code']) {
                    $problems[] = "type #{$type->id} ('{$type->slug}') already has the code '{$type->code}', expected {$owner['code']} — not changed, nothing written";
                }

                $type->code === null ? $row['actions'][] = ['set_code', $owner['code']] : $row['skipped'][] = 'code already '.$type->code;
                blank($type->name_en) ? $row['actions'][] = ['set_name_en', $owner['name_en']] : $row['skipped'][] = 'English name already set';
                if ($untouched) {
                    $row['actions'][] = ['set_sort_order', $owner['order']];
                }
            } elseif ($untouched) {
                $row['actions'][] = ['set_sort_order', $nextOrder++];
            }

            if (MembershipFeePolicy::query()->where('membership_type_id', $type->id)->exists()) {
                $row['skipped'][] = 'already has a fee policy';
            } elseif ($owner !== null) {
                $row['actions'][] = ['create_policy', $owner['registration'], $owner['monthly'], $today, self::NOTE_OWNER_APPROVED];
            } else {
                $legacy = Money::parse($type->getRawOriginal('fee') ?? '0') ?? '0.00';
                $row['actions'][] = ['create_policy', $legacy, '0', $today, self::NOTE_CARRY_OVER];
            }

            $rows[] = $row;
        }

        foreach (self::OWNER_APPROVED as $slug => $owner) {
            if (($matchedSlugs[$slug] ?? 0) === 0) {
                $problems[] = "no existing membership type has the slug '{$slug}' ({$owner['code']}) — this loader never creates types; nothing written";
            }
        }

        return ['ok' => $problems === [], 'today' => $today, 'problems' => $problems, 'types' => $rows];
    }

    /**
     * Applies the plan, all or nothing.
     *
     * @return array{ok: bool, today: string, problems: array<int,string>, types: array<int, array<string,mixed>>, applied: bool, created_policies: int}
     */
    public function apply(): array
    {
        $plan = $this->plan();
        $plan += ['applied' => false, 'created_policies' => 0];
        if (! $plan['ok']) {
            return $plan;
        }

        DB::transaction(function () use (&$plan) {
            foreach ($plan['types'] as $row) {
                $type = MembershipType::query()->findOrFail($row['type_id']);

                foreach ($row['actions'] as $action) {
                    match ($action[0]) {
                        'set_code' => $type->forceFill(['code' => $action[1]])->save(),
                        'set_name_en' => $type->forceFill(['name_en' => $action[1]])->save(),
                        'set_sort_order' => $type->forceFill(['sort_order' => $action[1]])->save(),
                        'create_policy' => $this->createPolicy($type, $action, $plan['created_policies']),
                    };
                }
            }
        });

        $plan['applied'] = true;

        return $plan;
    }

    /** @param array{0:string,1:string,2:string,3:string,4:string} $action */
    private function createPolicy(MembershipType $type, array $action, int &$counter): void
    {
        $this->fees->create($type, [
            'registration_fee' => $action[1],
            'monthly_contribution' => $action[2],
            'effective_from' => $action[3],
            'note' => $action[4],
        ], null);
        $counter++;
    }
}
