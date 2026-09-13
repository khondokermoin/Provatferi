<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MembershipSeason;
use App\Models\MembershipType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * §33: admin-controlled registration seasons/campaigns. `status` is the
 * only thing that ever opens/closes a season for real (see
 * MembershipSeason::acceptsApplicationsNow()) — dates are a schedule the
 * admin can act on, never an automatic trigger (§3: "do not silently
 * perform destructive business transitions").
 */
class MembershipSeasonController extends Controller
{
    public function index(): View
    {
        $seasons = MembershipSeason::query()->withCount('applications')
            ->orderBy('display_order')->orderByDesc('opens_at')->paginate(15);

        return view('admin.membership.seasons.index', [
            'title' => 'সদস্য নিবন্ধন সিজন',
            'breadcrumbs' => [['label' => 'সদস্যপদ'], ['label' => 'নিবন্ধন সিজন']],
            'seasons' => $seasons,
        ]);
    }

    public function create(): View
    {
        return view('admin.membership.seasons.form', [
            'title' => 'নতুন নিবন্ধন সিজন',
            'breadcrumbs' => [['label' => 'নিবন্ধন সিজন', 'route' => 'admin.membership.seasons.index'], ['label' => 'তৈরি করুন']],
            'season' => new MembershipSeason([
                'campaign_type' => 'regular', 'status' => 'draft', 'public_profile_opt_in' => true, 'display_order' => 0,
            ]),
            'campaignTypes' => MembershipSeason::CAMPAIGN_TYPES,
            'statuses' => MembershipSeason::STATUSES,
            'membershipTypes' => MembershipType::query()->where('status', 'active')->orderBy('sort_order')->get(),
            'selectedTypeIds' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules(), [], $this->attributes());
        $data['slug'] = $this->uniqueSlug($data['name']);
        $data['public_profile_opt_in'] = $request->boolean('public_profile_opt_in');
        $data['created_by'] = $request->user()->id;
        $typeIds = $data['membership_type_ids'] ?? [];
        unset($data['membership_type_ids']);

        $season = MembershipSeason::query()->create($data);
        $season->membershipTypes()->sync($typeIds);

        return redirect()->route('admin.membership.seasons.index')->with('success', "\u{201c}{$season->name}\u{201d} তৈরি হয়েছে।");
    }

    public function edit(MembershipSeason $season): View
    {
        return view('admin.membership.seasons.form', [
            'title' => 'সম্পাদনা — '.$season->name,
            'breadcrumbs' => [['label' => 'নিবন্ধন সিজন', 'route' => 'admin.membership.seasons.index'], ['label' => $season->name]],
            'season' => $season,
            'campaignTypes' => MembershipSeason::CAMPAIGN_TYPES,
            'statuses' => MembershipSeason::STATUSES,
            'membershipTypes' => MembershipType::query()->where('status', 'active')->orderBy('sort_order')->get(),
            'selectedTypeIds' => $season->membershipTypes()->pluck('membership_types.id')->all(),
        ]);
    }

    public function update(Request $request, MembershipSeason $season): RedirectResponse
    {
        $data = $request->validate($this->rules($season), [], $this->attributes());
        $data['public_profile_opt_in'] = $request->boolean('public_profile_opt_in');
        $typeIds = $data['membership_type_ids'] ?? [];
        unset($data['membership_type_ids']);

        $season->update($data);
        $season->membershipTypes()->sync($typeIds);

        return redirect()->route('admin.membership.seasons.index')->with('success', "\u{201c}{$season->name}\u{201d} হালনাগাদ হয়েছে।");
    }

    /**
     * §3: admin-only, explicit status transitions — never inferred from
     * dates automatically. The current suggestion (if any) is shown in the
     * view via MembershipSeason::suggestedStatusFromDates() so the admin can
     * act on it deliberately.
     */
    public function updateStatus(Request $request, MembershipSeason $season): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(MembershipSeason::STATUSES))],
        ]);

        $season->update($data);

        return back()->with('success', 'সিজনের স্ট্যাটাস হালনাগাদ হয়েছে।');
    }

    public function destroy(MembershipSeason $season): RedirectResponse
    {
        if ($season->applications()->exists()) {
            return back()->with('error', 'এই সিজনের সঙ্গে আবেদন যুক্ত আছে — মুছে ফেলা যাবে না।');
        }

        $name = $season->name;
        $season->delete();

        return redirect()->route('admin.membership.seasons.index')->with('success', "\u{201c}{$name}\u{201d} মুছে ফেলা হয়েছে।");
    }

    /** @return array<string, mixed> */
    private function rules(?MembershipSeason $season = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'campaign_type' => ['required', Rule::in(array_keys(MembershipSeason::CAMPAIGN_TYPES))],
            'opens_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date', 'after:opens_at'],
            'membership_period_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'description' => ['nullable', 'string', 'max:4000'],
            'status' => ['required', Rule::in(array_keys(MembershipSeason::STATUSES))],
            'cash_payment_instructions' => ['nullable', 'string', 'max:2000'],
            'display_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'membership_type_ids' => ['nullable', 'array'],
            'membership_type_ids.*' => [Rule::exists('membership_types', 'id')],
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return [
            'name' => 'নাম', 'campaign_type' => 'ধরন', 'opens_at' => 'শুরুর তারিখ', 'closes_at' => 'শেষের তারিখ',
            'status' => 'স্ট্যাটাস', 'display_order' => 'ক্রম',
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name).'-'.now()->format('Y');
        $slug = $base;
        $suffix = 1;
        while (MembershipSeason::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
