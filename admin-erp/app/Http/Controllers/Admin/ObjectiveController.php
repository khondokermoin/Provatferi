<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Objective;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ObjectiveController extends Controller
{
    public function index(): View
    {
        return view('admin.content.objectives.index', [
            'title' => __('admin.nav.objectives'),
            'breadcrumbs' => [['label' => __('admin.nav.groups.content')], ['label' => __('admin.nav.objectives')]],
            'objectives' => Objective::query()->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function create(): View
    {
        $nextOrder = (int) Objective::query()->max('sort_order') + 1;

        return view('admin.content.objectives.form', [
            'title' => __('admin.fields.new_objective'),
            'breadcrumbs' => [
                ['label' => __('admin.nav.objectives'), 'route' => 'admin.content.objectives.index'],
                ['label' => __('admin.actions.create')],
            ],
            'objective' => new Objective(['sort_order' => $nextOrder, 'active' => true]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Objective::query()->create($this->validated($request));

        return redirect()->route('admin.content.objectives.index')->with('success', __('admin.flash.objective_created'));
    }

    public function edit(Objective $objective): View
    {
        return view('admin.content.objectives.form', [
            'title' => __('admin.fields.edit_objective_title'),
            'breadcrumbs' => [
                ['label' => __('admin.nav.objectives'), 'route' => 'admin.content.objectives.index'],
                ['label' => __('admin.actions.edit')],
            ],
            'objective' => $objective,
        ]);
    }

    public function update(Request $request, Objective $objective): RedirectResponse
    {
        $objective->update($this->validated($request));

        return redirect()->route('admin.content.objectives.index')->with('success', __('admin.flash.objective_updated'));
    }

    public function destroy(Objective $objective): RedirectResponse
    {
        $objective->delete();

        return redirect()->route('admin.content.objectives.index')->with('success', __('admin.flash.objective_deleted'));
    }

    public function toggleActive(Objective $objective): RedirectResponse
    {
        $objective->update(['active' => ! $objective->active]);

        return back()->with('success', $objective->active ? __('admin.flash.objective_activated') : __('admin.flash.objective_deactivated'));
    }

    /** Swaps sort_order with the immediately preceding objective. */
    public function moveUp(Objective $objective): RedirectResponse
    {
        $previous = Objective::query()
            ->where('sort_order', '<', $objective->sort_order)
            ->orderByDesc('sort_order')
            ->first();

        if ($previous) {
            [$objective->sort_order, $previous->sort_order] = [$previous->sort_order, $objective->sort_order];
            $objective->save();
            $previous->save();
        }

        return back();
    }

    /** Swaps sort_order with the immediately following objective. */
    public function moveDown(Objective $objective): RedirectResponse
    {
        $next = Objective::query()
            ->where('sort_order', '>', $objective->sort_order)
            ->orderBy('sort_order')
            ->first();

        if ($next) {
            [$objective->sort_order, $next->sort_order] = [$next->sort_order, $objective->sort_order];
            $objective->save();
            $next->save();
        }

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'title_en' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:2000'],
            'body_en' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ], [], ['body' => __('admin.common.description'), 'sort_order' => __('admin.common.order')]);
        $data['active'] = $request->boolean('active');

        return $data;
    }
}
