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

        return back()->with('success', "\u{201c}{$data['name']}\u{201d} পদ যোগ হয়েছে।");
    }

    public function update(Request $request, Committee $committee, CommitteePosition $position): RedirectResponse
    {
        abort_unless($position->committee_id === $committee->id, 404);

        $data = $request->validate($this->rules($committee, $position), [], $this->attributes());
        $data['allow_duplicates'] = $request->boolean('allow_duplicates');

        $position->update($data);

        return back()->with('success', 'পদ হালনাগাদ হয়েছে।');
    }

    public function destroy(Committee $committee, CommitteePosition $position): RedirectResponse
    {
        abort_unless($position->committee_id === $committee->id, 404);

        if ($position->members()->exists() || $position->submissions()->exists()) {
            return back()->with('error', 'এই পদে সদস্য বা আবেদন যুক্ত আছে — মুছে ফেলা যাবে না।');
        }

        $position->delete();

        return back()->with('success', 'পদ মুছে ফেলা হয়েছে।');
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
            'display_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'allow_duplicates' => ['nullable', 'boolean'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return ['name' => 'পদের নাম', 'display_order' => 'ক্রম', 'status' => 'স্ট্যাটাস'];
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
