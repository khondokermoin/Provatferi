<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MembershipType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MembershipTypeController extends Controller
{
    public const STATUSES = ['active' => 'সক্রিয়', 'inactive' => 'নিষ্ক্রিয়'];

    public function index(): View
    {
        $types = MembershipType::query()->withCount(['applications', 'memberships'])
            ->orderBy('sort_order')->orderBy('name')->paginate(15);

        return view('admin.membership.types.index', [
            'title' => 'সদস্যপদের ধরন',
            'breadcrumbs' => [['label' => 'সদস্যপদ'], ['label' => 'সদস্যপদের ধরন']],
            'types' => $types,
        ]);
    }

    public function create(): View
    {
        return view('admin.membership.types.form', [
            'title' => 'নতুন সদস্যপদের ধরন',
            'breadcrumbs' => [['label' => 'সদস্যপদের ধরন', 'route' => 'admin.membership.types.index'], ['label' => 'তৈরি করুন']],
            'type' => new MembershipType(['status' => 'active', 'sort_order' => 0, 'fee' => 0, 'is_student' => false]),
            'statuses' => self::STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules(), [], $this->attributes());
        $data['slug'] = Str::slug($data['name']);
        $data['is_student'] = $request->boolean('is_student');

        $type = MembershipType::query()->create($data);

        return redirect()->route('admin.membership.types.index')->with('success', "\u{201c}{$type->name}\u{201d} তৈরি হয়েছে।");
    }

    public function edit(MembershipType $membershipType): View
    {
        return view('admin.membership.types.form', [
            'title' => 'সম্পাদনা — '.$membershipType->name,
            'breadcrumbs' => [['label' => 'সদস্যপদের ধরন', 'route' => 'admin.membership.types.index'], ['label' => $membershipType->name]],
            'type' => $membershipType,
            'statuses' => self::STATUSES,
        ]);
    }

    public function update(Request $request, MembershipType $membershipType): RedirectResponse
    {
        $data = $request->validate($this->rules(), [], $this->attributes());
        $data['is_student'] = $request->boolean('is_student');

        $membershipType->update($data);

        return redirect()->route('admin.membership.types.index')->with('success', "\u{201c}{$membershipType->name}\u{201d} হালনাগাদ হয়েছে।");
    }

    public function destroy(MembershipType $membershipType): RedirectResponse
    {
        if ($membershipType->applications()->exists() || $membershipType->memberships()->exists()) {
            return back()->with('error', 'এই ধরনটি আবেদন বা সদস্যপদের সঙ্গে যুক্ত — মুছে ফেলা যাবে না।');
        }

        $name = $membershipType->name;
        $membershipType->delete();

        return redirect()->route('admin.membership.types.index')->with('success', "\u{201c}{$name}\u{201d} মুছে ফেলা হয়েছে।");
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'duration_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'fee' => ['required', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(array_keys(self::STATUSES))],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return ['name' => 'নাম', 'fee' => 'ফি', 'status' => 'স্ট্যাটাস', 'sort_order' => 'ক্রম'];
    }
}
