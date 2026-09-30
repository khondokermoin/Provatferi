<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HomepageCarouselSlide;
use App\Services\PhotoUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * Phase 4: the homepage carousel is a plain ordered list, same shape as
 * ObjectiveController (sort_order + move-up/move-down + an active/inactive
 * toggle, no drag-and-drop UI — matching this codebase's own established
 * convention), plus an image upload on top. A slide's image is promoted to
 * the public disk immediately on save — unlike a Notice's cover image, a
 * carousel slide has no separate draft/publish workflow of its own; "active"
 * vs "inactive" only controls whether it currently rotates into view.
 */
class HomepageCarouselController extends Controller
{
    public function __construct(
        private readonly PhotoUploadService $photos,
    ) {
    }

    public function index(): View
    {
        return view('admin.homepage_carousel.index', [
            'title' => __('admin.nav.homepage_carousel'),
            'breadcrumbs' => [['label' => __('admin.nav.groups.content')], ['label' => __('admin.nav.homepage_carousel')]],
            'slides' => HomepageCarouselSlide::query()->orderBy('sort_order')->orderBy('id')->get(),
            'photos' => $this->photos,
        ]);
    }

    public function create(): View
    {
        $nextOrder = (int) HomepageCarouselSlide::query()->max('sort_order') + 1;

        return view('admin.homepage_carousel.form', [
            'title' => __('admin.fields.new_slide'),
            'breadcrumbs' => [
                ['label' => __('admin.nav.homepage_carousel'), 'route' => 'admin.homepage-carousel.index'],
                ['label' => __('admin.actions.create')],
            ],
            'slide' => new HomepageCarouselSlide(['sort_order' => $nextOrder, 'status' => 'active']),
            'statuses' => status_options(HomepageCarouselSlide::STATUSES),
            'photos' => $this->photos,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['image_path'] = $this->storeImage($request);
        $data['created_by'] = $request->user()->id;

        HomepageCarouselSlide::query()->create($data);

        return redirect()->route('admin.homepage-carousel.index')->with('success', __('admin.flash.carousel_slide_created'));
    }

    public function edit(HomepageCarouselSlide $homepageCarouselSlide): View
    {
        return view('admin.homepage_carousel.form', [
            'title' => __('admin.fields.edit_slide_title'),
            'breadcrumbs' => [
                ['label' => __('admin.nav.homepage_carousel'), 'route' => 'admin.homepage-carousel.index'],
                ['label' => __('admin.actions.edit')],
            ],
            'slide' => $homepageCarouselSlide,
            'statuses' => status_options(HomepageCarouselSlide::STATUSES),
            'photos' => $this->photos,
        ]);
    }

    public function update(Request $request, HomepageCarouselSlide $homepageCarouselSlide): RedirectResponse
    {
        $data = $this->validated($request, $homepageCarouselSlide);

        if ($request->hasFile('image')) {
            $oldPath = $homepageCarouselSlide->image_path;
            $data['image_path'] = $this->storeImage($request);
            $this->photos->deletePublic($oldPath);
        }

        $homepageCarouselSlide->update($data);

        return redirect()->route('admin.homepage-carousel.index')->with('success', __('admin.flash.carousel_slide_updated'));
    }

    public function destroy(HomepageCarouselSlide $homepageCarouselSlide): RedirectResponse
    {
        $this->photos->deletePublic($homepageCarouselSlide->image_path);
        $homepageCarouselSlide->delete();

        return redirect()->route('admin.homepage-carousel.index')->with('success', __('admin.flash.carousel_slide_deleted'));
    }

    public function toggleStatus(HomepageCarouselSlide $homepageCarouselSlide): RedirectResponse
    {
        $next = $homepageCarouselSlide->status === 'active' ? 'inactive' : 'active';
        $homepageCarouselSlide->update(['status' => $next]);

        return back()->with('success', $next === 'active' ? __('admin.flash.objective_activated') : __('admin.flash.objective_deactivated'));
    }

    /** Swaps sort_order with the immediately preceding slide. */
    public function moveUp(HomepageCarouselSlide $homepageCarouselSlide): RedirectResponse
    {
        $previous = HomepageCarouselSlide::query()
            ->where('sort_order', '<', $homepageCarouselSlide->sort_order)
            ->orderByDesc('sort_order')
            ->first();

        if ($previous) {
            [$homepageCarouselSlide->sort_order, $previous->sort_order] = [$previous->sort_order, $homepageCarouselSlide->sort_order];
            $homepageCarouselSlide->save();
            $previous->save();
        }

        return back();
    }

    /** Swaps sort_order with the immediately following slide. */
    public function moveDown(HomepageCarouselSlide $homepageCarouselSlide): RedirectResponse
    {
        $next = HomepageCarouselSlide::query()
            ->where('sort_order', '>', $homepageCarouselSlide->sort_order)
            ->orderBy('sort_order')
            ->first();

        if ($next) {
            [$homepageCarouselSlide->sort_order, $next->sort_order] = [$next->sort_order, $homepageCarouselSlide->sort_order];
            $homepageCarouselSlide->save();
            $next->save();
        }

        return back();
    }

    private function storeImage(Request $request): string
    {
        try {
            $privatePath = $this->photos->storePrivate($request->file('image'), 'homepage-carousel');
            $publicPath = $this->photos->promoteToPublic($privatePath, 'homepage-carousel');
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['image' => upload_error_label($e->getMessage())]);
        }

        $this->photos->deletePrivate($privatePath);

        return $publicPath;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?HomepageCarouselSlide $slide = null): array
    {
        $data = $request->validate([
            'image' => [$slide ? 'nullable' : 'required', 'file', 'max:5120'],
            'title' => ['nullable', 'string', 'max:255'],
            'title_en' => ['nullable', 'string', 'max:255'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'alt_text_en' => ['nullable', 'string', 'max:255'],
            'link_url' => ['nullable', 'required_with:link_label', 'url', 'max:500'],
            'link_label' => ['nullable', 'required_with:link_url', 'string', 'max:100'],
            'link_label_en' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'status' => ['required', Rule::in(array_keys(HomepageCarouselSlide::STATUSES))],
        ], [], [
            'image' => __('admin.fields.slide_image'),
            'link_url' => __('admin.fields.link_url'),
            'link_label' => __('admin.fields.link_label'),
            'sort_order' => __('admin.common.order'),
            'status' => __('admin.common.status'),
        ]);

        unset($data['image']);

        return $data;
    }
}
