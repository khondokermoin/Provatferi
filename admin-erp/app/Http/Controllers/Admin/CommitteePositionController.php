<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Committee;
use App\Models\CommitteePosition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * §21: a committee's own position list, scoped to that committee only — not
 * fixed-size, not shared with any other committee's positions. Managed
 * inline from the committee show page, so there is no standalone index/show.
 */
class CommitteePositionController extends Controller
{
    public function store(Request $request, Committee $committee): RedirectResponse
    {
        $data = $request->validate($this->rules($committee), [], $this->attributes());
        $data['slug'] = $this->uniqueSlug($committee, $data['name']);
        $data['allow_duplicates'] = $request->boolean('allow_duplicates');

        $committee->positions()->create($data);

        return back()->with('success', __('admin.flash.position_added', ['name' => $data['name']]));
    }

    public function update(Request $request, Committee $committee, CommitteePosition $position): RedirectResponse
    {
        abort_unless($position->committee_id === $committee->id, 404);

        $data = $request->validate($this->rules($committee, $position), [], $this->attributes());
        $data['allow_duplicates'] = $request->boolean('allow_duplicates');

        $position->update($data);

        return back()->with('success', __('admin.flash.position_updated_generic'));
    }

    public function destroy(Committee $committee, CommitteePosition $position): RedirectResponse
    {
        abort_unless($position->committee_id === $committee->id, 404);

        if ($position->members()->exists() || $position->submissions()->exists()) {
            return back()->with('error', __('admin.fields.position_has_members_cannot_delete'));
        }

        $position->delete();

        return back()->with('success', __('admin.flash.position_deleted_generic'));
    }

    /** @return array<string, mixed> */
    private function rules(Committee $committee, ?CommitteePosition $position = null): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('committee_positions', 'name')
                    ->where(fn ($q) => $q->where('committee_id', $committee->id))
                    ->ignore($position?->id),
            ],
            'name_en' => ['nullable', 'string', 'max:255'],
            'display_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'allow_duplicates' => ['nullable', 'boolean'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return ['name' => __('admin.fields.position_name'), 'display_order' => __('admin.common.order'), 'status' => __('admin.common.status')];
    }

    private function uniqueSlug(Committee $committee, string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;
        while (CommitteePosition::query()->where('committee_id', $committee->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
