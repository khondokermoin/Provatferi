<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MembershipFeePolicy;
use App\Models\MembershipType;
use App\Services\MembershipFeePolicyService;
use App\Services\MembershipNumbering;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Membership types and — since 2026-10-05 — their effective-dated fee policies.
 *
 * What a type's metadata form edits: names/descriptions (BN/EN tabs), code, flags, order, status. It no longer edits
 * a fee: fees live in append-only policy versions (MembershipFeePolicyService), created from the type's own page and
 * never edited afterwards. The `fee` column of membership_types is legacy and untouched by anything here.
 */
class MembershipTypeController extends Controller
{
    public const STATUSES = ['active' => 'সক্রিয়', 'inactive' => 'নিষ্ক্রিয়'];

    public function __construct(
        private readonly MembershipFeePolicyService $fees,
        private readonly MembershipNumbering $numbering,
    ) {}

    public function index(): View
    {
        $types = MembershipType::query()->withCount(['applications', 'memberships'])
            ->orderBy('sort_order')->orderBy('name')->paginate(15);

        return view('admin.membership.types.index', [
            'title' => __('admin.nav.membership_types'),
            'breadcrumbs' => [['label' => __('admin.nav.membership')], ['label' => __('admin.nav.membership_types')]],
            'types' => $types,
            'current' => $this->fees->effectiveForMany($types->items()),
            'upcoming' => $this->fees->upcomingForMany($types->items()),
        ]);
    }

    public function create(): View
    {
        return view('admin.membership.types.form', [
            'title' => __('admin.fields.new_membership_type'),
            'breadcrumbs' => [['label' => __('admin.nav.membership_types'), 'route' => 'admin.membership.types.index'], ['label' => __('admin.actions.create')]],
            'type' => new MembershipType([
                'status' => 'active', 'sort_order' => (int) MembershipType::query()->max('sort_order') + 1,
                'is_student' => false, 'is_public_self_apply' => true, 'is_public_visible' => true,
            ]),
            'statuses' => status_options(self::STATUSES),
            'today' => $this->fees->today(),
            'current' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->normaliseCode($request);
        $data = $request->validate($this->rules(null) + $this->initialFeeRules(), [], $this->attributes());

        $type = DB::transaction(function () use ($request, $data) {
            $type = MembershipType::query()->create([
                'name' => $data['name'],
                'name_en' => $data['name_en'] ?? null,
                'description' => $data['description'] ?? null,
                'description_en' => $data['description_en'] ?? null,
                'duration_months' => $data['duration_months'] ?? null,
                'status' => $data['status'],
                'sort_order' => $data['sort_order'],
                'code' => $data['code'],
                'slug' => $this->uniqueSlug($data['name'], $data['name_en'] ?? null, $data['code']),
                'is_student' => $request->boolean('is_student'),
                'is_public_self_apply' => $request->boolean('is_public_self_apply'),
                'is_public_visible' => $request->boolean('is_public_visible'),
            ]);

            // A type is born WITH its first fee policy, so it can never exist in a state where its price is unknown. A
            // refusal (a past date, a bad amount) throws and rolls the whole creation back.
            $this->fees->create($type, [
                'registration_fee' => $data['registration_fee'],
                'monthly_contribution' => $data['monthly_contribution'],
                'effective_from' => $data['effective_from'],
                'note' => ($data['fee_note'] ?? '') !== '' ? $data['fee_note'] : __('admin.fee_policy.initial_note'),
            ], $request->user());

            return $type;
        });

        return redirect()->route('admin.membership.types.show', $type)->with('success', __('admin.flash.membership_type_created', ['name' => $type->name]));
    }

    public function show(MembershipType $membershipType): View
    {
        $today = $this->fees->today();

        return view('admin.membership.types.show', [
            'title' => $membershipType->name,
            'breadcrumbs' => [['label' => __('admin.nav.membership_types'), 'route' => 'admin.membership.types.index'], ['label' => $membershipType->name]],
            'type' => $membershipType->loadCount(['applications', 'memberships']),
            'current' => $this->fees->effectiveFor($membershipType),
            'upcoming' => $this->fees->upcomingFor($membershipType),
            'history' => $this->fees->history($membershipType),
            'nextMemberNumber' => $this->numbering->nextMemberNumber($membershipType),
            'today' => $today,
            'defaultFrom' => Carbon::parse($today)->addDay()->toDateString(),
        ]);
    }

    public function edit(MembershipType $membershipType): View
    {
        return view('admin.membership.types.form', [
            'title' => __('admin.fields.edit_prefix').' — '.$membershipType->name,
            'breadcrumbs' => [['label' => __('admin.nav.membership_types'), 'route' => 'admin.membership.types.index'], ['label' => $membershipType->name, 'route' => 'admin.membership.types.show', 'params' => $membershipType], ['label' => __('admin.actions.edit')]],
            'type' => $membershipType,
            'statuses' => status_options(self::STATUSES),
            'today' => $this->fees->today(),
            'current' => $this->fees->effectiveFor($membershipType),
        ]);
    }

    public function update(Request $request, MembershipType $membershipType): RedirectResponse
    {
        $this->normaliseCode($request);
        $data = $request->validate($this->rules($membershipType), [], $this->attributes());

        $attributes = [
            'name' => $data['name'],
            'name_en' => $data['name_en'] ?? null,
            'description' => $data['description'] ?? null,
            'description_en' => $data['description_en'] ?? null,
            'duration_months' => $data['duration_months'] ?? null,
            'status' => $data['status'],
            'sort_order' => $data['sort_order'],
            'is_student' => $request->boolean('is_student'),
            'is_public_self_apply' => $request->boolean('is_public_self_apply'),
            'is_public_visible' => $request->boolean('is_public_visible'),
        ];
        // A code is stable: it can be SET once on a legacy type that has none, never changed afterwards.
        if ($membershipType->code === null && ! empty($data['code'])) {
            $attributes['code'] = $data['code'];
        }

        $membershipType->update($attributes);

        return redirect()->route('admin.membership.types.show', $membershipType)->with('success', __('admin.flash.membership_type_updated', ['name' => $membershipType->name]));
    }

    public function destroy(MembershipType $membershipType): RedirectResponse
    {
        if ($membershipType->applications()->exists() || $membershipType->memberships()->exists()) {
            return back()->with('error', __('admin.fields.type_in_use_cannot_delete'));
        }

        $name = $membershipType->name;
        // An UNUSED type's fee policies go with it — nothing can have been quoted them. (The foreign keys are RESTRICT,
        // so a policy an application refers to could never be removed here even if the guard above were bypassed.)
        DB::transaction(function () use ($membershipType) {
            MembershipFeePolicy::query()->where('membership_type_id', $membershipType->id)->delete();
            $membershipType->delete();
        });

        return redirect()->route('admin.membership.types.index')->with('success', __('admin.flash.membership_type_deleted', ['name' => $name]));
    }

    /** Activate / deactivate. Inactive types disappear from every public surface; nothing already recorded is touched. */
    public function toggle(MembershipType $membershipType): RedirectResponse
    {
        $next = $membershipType->isActive() ? 'inactive' : 'active';
        $membershipType->update(['status' => $next]);

        return back()->with('success', __($next === 'active' ? 'admin.fee_policy.flash.type_activated' : 'admin.fee_policy.flash.type_deactivated', ['name' => $membershipType->name]));
    }

    public function moveUp(MembershipType $membershipType): RedirectResponse
    {
        return $this->move($membershipType, -1);
    }

    public function moveDown(MembershipType $membershipType): RedirectResponse
    {
        return $this->move($membershipType, 1);
    }

    /**
     * Swaps a type with its neighbour in the display order. Every type starts with sort_order 0, so the list is first
     * renumbered 1..n in its current display order (otherwise equal values could never be swapped).
     */
    private function move(MembershipType $type, int $direction): RedirectResponse
    {
        DB::transaction(function () use ($type, $direction) {
            $ordered = MembershipType::query()->orderBy('sort_order')->orderBy('name')->lockForUpdate()->get()->values();

            foreach ($ordered as $position => $item) {
                if ((int) $item->sort_order !== $position + 1) {
                    $item->forceFill(['sort_order' => $position + 1])->save();
                }
            }

            $index = $ordered->search(fn (MembershipType $item) => $item->id === $type->id);
            $neighbour = $index === false ? null : ($ordered[$index + $direction] ?? null);
            if ($neighbour !== null) {
                $mine = $ordered[$index];
                [$mineOrder, $theirOrder] = [$mine->sort_order, $neighbour->sort_order];
                $mine->forceFill(['sort_order' => $theirOrder])->save();
                $neighbour->forceFill(['sort_order' => $mineOrder])->save();
            }
        });

        return back();
    }

    // ------------------------------------------------------------------ fee policies

    /** A NEW policy version. The earlier ones are never touched (see MembershipFeePolicyService). */
    public function storeFeePolicy(Request $request, MembershipType $membershipType): RedirectResponse
    {
        $data = $request->validate([
            'registration_fee' => ['required'],
            'monthly_contribution' => ['required'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'note' => ['required', 'string', 'max:1000'],
        ], [], $this->attributes());

        $policy = $this->fees->create($membershipType, $data, $request->user());

        return redirect()->route('admin.membership.types.show', $membershipType)->with('success', __('admin.fee_policy.flash.created', [
            'name' => $membershipType->name, 'date' => bn_date($policy->fromDate()),
        ]));
    }

    /** Cancels a policy that has not started yet. A policy that is in force or past can never be cancelled. */
    public function cancelFeePolicy(Request $request, MembershipType $membershipType, MembershipFeePolicy $feePolicy): RedirectResponse
    {
        abort_unless($feePolicy->membership_type_id === $membershipType->id, 404);

        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'max:500']], [], $this->attributes());
        $this->fees->cancel($feePolicy, $request->user(), $data['cancellation_reason']);

        return redirect()->route('admin.membership.types.show', $membershipType)->with('success', __('admin.fee_policy.flash.cancelled', ['name' => $membershipType->name]));
    }

    // ------------------------------------------------------------------ validation

    /**
     * Metadata rules. $existing is the type being edited (null when creating): `code` must be unique among the others,
     * and is only required when creating.
     *
     * @return array<string, mixed>
     */
    private function rules(?MembershipType $existing): array
    {
        $codeRules = [
            $existing === null ? 'required' : 'nullable', 'string', 'regex:'.MembershipType::CODE_PATTERN,
            Rule::unique('membership_types', 'code')->ignore($existing?->id),
        ];

        return [
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'duration_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'code' => $codeRules,
            'status' => ['required', Rule::in(array_keys(self::STATUSES))],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /** @return array<string, mixed> */
    private function initialFeeRules(): array
    {
        return [
            'registration_fee' => ['required'],
            'monthly_contribution' => ['required'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'fee_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return [
            'name' => __('admin.common.name'), 'status' => __('admin.common.status'), 'sort_order' => __('admin.common.order'),
            'code' => __('admin.fee_policy.code'),
            'registration_fee' => __('admin.fee_policy.registration_fee'), 'monthly_contribution' => __('admin.fee_policy.monthly_contribution'),
            'effective_from' => __('admin.fee_policy.effective_from'), 'note' => __('admin.fee_policy.reason'),
            'fee_note' => __('admin.fee_policy.reason'), 'cancellation_reason' => __('admin.fee_policy.cancel_reason'),
        ];
    }

    /** Codes are upper-case by definition: "lm " typed in a hurry becomes "LM" rather than failing the pattern. */
    private function normaliseCode(Request $request): void
    {
        $code = $request->input('code');
        if (is_string($code) && trim($code) !== '') {
            $request->merge(['code' => Str::upper(trim($code))]);
        }
    }

    private function uniqueSlug(string $name, ?string $nameEn, string $code): string
    {
        $base = Str::slug((string) $nameEn) ?: Str::slug($name) ?: Str::lower($code);
        $slug = $base;
        for ($suffix = 2; MembershipType::query()->where('slug', $slug)->exists(); $suffix++) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
