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
            'title' => __('admin.nav.membership_types'),
            'breadcrumbs' => [['label' => __('admin.nav.membership')], ['label' => __('admin.nav.membership_types')]],
            'types' => $types,
        ]);
    }

    public function create(): View
    {
        return view('admin.membership.types.form', [
            'title' => __('admin.fields.new_membership_type'),
            'breadcrumbs' => [['label' => __('admin.nav.membership_types'), 'route' => 'admin.membership.types.index'], ['label' => __('admin.actions.create')]],
            'type' => new MembershipType([
                'status' => 'active', 'sort_order' => 0, 'fee' => 0, 'is_student' => false, 'is_public_self_apply' => true,
            ]),
            'statuses' => status_options(self::STATUSES),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules(), [], $this->attributes());
        $data['slug'] = Str::slug($data['name']);
        $data['is_student'] = $request->boolean('is_student');
        $data['is_public_self_apply'] = $request->boolean('is_public_self_apply');

        $type = MembershipType::query()->create($data);

        return redirect()->route('admin.membership.types.index')->with('success', __('admin.flash.membership_type_created', ['name' => $type->name]));
    }

    public function edit(MembershipType $membershipType): View
    {
        return view('admin.membership.types.form', [
            'title' => __('admin.fields.edit_prefix').' — '.$membershipType->name,
            'breadcrumbs' => [['label' => __('admin.nav.membership_types'), 'route' => 'admin.membership.types.index'], ['label' => $membershipType->name]],
            'type' => $membershipType,
            'statuses' => status_options(self::STATUSES),
        ]);
    }

    public function update(Request $request, MembershipType $membershipType): RedirectResponse
    {
        $data = $request->validate($this->rules(), [], $this->attributes());
        $data['is_student'] = $request->boolean('is_student');
        $data['is_public_self_apply'] = $request->boolean('is_public_self_apply');

        $membershipType->update($data);

        return redirect()->route('admin.membership.types.index')->with('success', __('admin.flash.membership_type_updated', ['name' => $membershipType->name]));
    }

    public function destroy(MembershipType $membershipType): RedirectResponse
    {
        if ($membershipType->applications()->exists() || $membershipType->memberships()->exists()) {
            return back()->with('error', __('admin.fields.type_in_use_cannot_delete'));
        }

        $name = $membershipType->name;
        $membershipType->delete();

        return redirect()->route('admin.membership.types.index')->with('success', __('admin.flash.membership_type_deleted', ['name' => $name]));
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'duration_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'fee' => ['required', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(array_keys(self::STATUSES))],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return ['name' => __('admin.common.name'), 'fee' => __('admin.fields.fee'), 'status' => __('admin.common.status'), 'sort_order' => __('admin.common.order')];
    }
}
