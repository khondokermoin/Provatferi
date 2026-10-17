<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeadershipProfile;
use App\Services\LeadershipPhotoService;
use App\Support\LeadershipProfileInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * Leadership / Key Persons management (docs/LEADERSHIP_SYSTEM.md). A profile is never deleted: it is deactivated (hidden,
 * editable) or archived (hidden, kept for the record, restorable), so its public address and its history stay intact.
 *
 * Who may do what (module `leadership`, enforced by route middleware and again here wherever a request could carry a
 * field the role must not set):
 *   view     the list and the preview
 *   create   add a profile — always saved INACTIVE unless the user may also publish
 *   update   edit content and photo; the slug too (the old address keeps working)
 *   publish  status, featured, display order — everything that decides what the website shows and in what order
 *   archive  archive and restore
 */
class LeadershipProfileController extends Controller
{
    private const PER_PAGE = 15;

    public function __construct(private readonly LeadershipPhotoService $photos)
    {
    }

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
            'featured' => (string) $request->query('featured', ''),
        ];
        if (! in_array($filters['status'], ['', ...array_keys(LeadershipProfile::STATUSES)], true)) {
            $filters['status'] = '';
        }
        if (! in_array($filters['featured'], ['', '1', '0'], true)) {
            $filters['featured'] = '';
        }
        $isFiltered = $filters['q'] !== '' || $filters['status'] !== '' || $filters['featured'] !== '';

        $profiles = LeadershipProfile::query()
            ->with('editor:id,name')
            ->when($filters['q'] !== '', function ($query) use ($filters) {
                $like = '%'.addcslashes($filters['q'], '%_\\').'%';
                $query->where(fn ($w) => $w->where('name_bn', 'like', $like)->orWhere('name_en', 'like', $like)
                    ->orWhere('designation_bn', 'like', $like)->orWhere('designation_en', 'like', $like)->orWhere('slug', 'like', $like));
            })
            // Without an explicit status filter the archived stay out of the way; "Archived" shows exactly them.
            ->when($filters['status'] === '', fn ($query) => $query->orderable(), fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['featured'] !== '', fn ($query) => $query->where('featured', $filters['featured'] === '1'))
            ->inDisplayOrder()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $orderable = LeadershipProfile::query()->orderable()->inDisplayOrder()->pluck('id');

        return view('admin.leadership.index', [
            'title' => __('admin.nav.leadership'),
            'breadcrumbs' => [['label' => __('admin.nav.groups.institution')], ['label' => __('admin.nav.leadership')]],
            'profiles' => $profiles,
            'filters' => $filters,
            'isFiltered' => $isFiltered,
            'statuses' => status_options(array_keys(LeadershipProfile::STATUSES)),
            // The arrows move a profile within the whole order, so they are offered only on the unfiltered list.
            'canReorder' => ! $isFiltered && request()->user()->can('leadership.publish'),
            'firstId' => $orderable->first(),
            'lastId' => $orderable->last(),
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form($request, new LeadershipProfile([
            'status' => LeadershipProfile::STATUS_INACTIVE,
            'featured' => false,
            'display_order' => LeadershipProfile::nextDisplayOrder(),
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        $canPublish = $request->user()->can('leadership.publish');
        $validated = $this->validate($request, $canPublish);
        $data = LeadershipProfileInput::normalize($validated, $canPublish);

        $typedSlug = (string) ($validated['slug'] ?? '');
        $data['slug'] = LeadershipProfile::uniqueSlug($typedSlug !== '' ? $typedSlug : ($data['name_en'] ?? $data['name_bn']));
        // Without the publish permission a new profile is always saved hidden, whatever the request says.
        $data['status'] = $canPublish ? ($data['status'] ?? LeadershipProfile::STATUS_INACTIVE) : LeadershipProfile::STATUS_INACTIVE;
        $data['featured'] = $canPublish ? ($data['featured'] ?? false) : false;
        $data['display_order'] = $canPublish ? ($data['display_order'] ?? LeadershipProfile::nextDisplayOrder()) : LeadershipProfile::nextDisplayOrder();
        $data['created_by'] = $data['updated_by'] = $request->user()->id;

        $photo = $request->hasFile('photo') ? $this->storePhoto($request) : [];

        try {
            $profile = LeadershipProfile::query()->create($data + $photo);
        } catch (Throwable $e) {
            $this->photos->delete($photo['photo_path'] ?? null, $photo['photo_sm_path'] ?? null);
            throw $e;
        }

        $this->log($request, $profile, 'created');

        return $this->withSlugNotice(
            redirect()->route('admin.leadership.index')->with('success', __('admin.leadership.flash_created', ['name' => $profile->displayName()])),
            $typedSlug,
            $profile->slug,
        );
    }

    public function edit(Request $request, LeadershipProfile $leadershipProfile): View
    {
        return $this->form($request, $leadershipProfile);
    }

    public function update(Request $request, LeadershipProfile $leadershipProfile): RedirectResponse
    {
        $canPublish = $request->user()->can('leadership.publish');
        $validated = $this->validate($request, $canPublish);
        $data = LeadershipProfileInput::normalize($validated, $canPublish);

        // An archived profile stays archived, unfeatured and out of the order until it is restored; its form offers no
        // publishing fields, and a crafted request cannot add them.
        if ($leadershipProfile->isArchived()) {
            unset($data['status'], $data['featured'], $data['display_order']);
        }

        // The slug changes only when an admin types a different one — never because the name changed, so a public
        // address does not move under people who shared it.
        $newSlug = $leadershipProfile->slug;
        $typedSlug = (string) ($validated['slug'] ?? '');
        if ($typedSlug !== '' && $typedSlug !== $leadershipProfile->slug) {
            $newSlug = LeadershipProfile::uniqueSlug($typedSlug, $leadershipProfile->id);
        }

        $oldPhoto = [$leadershipProfile->photo_path, $leadershipProfile->photo_sm_path];
        $photo = [];
        $removePhoto = false;
        if ($request->hasFile('photo')) {
            $photo = $this->storePhoto($request);
        } elseif ($request->boolean('remove_photo') && $leadershipProfile->hasPhoto()) {
            $removePhoto = true;
            $photo = ['photo_path' => null, 'photo_sm_path' => null, 'photo_width' => null, 'photo_height' => null];
        }

        $data['updated_by'] = $request->user()->id;

        try {
            DB::transaction(function () use ($leadershipProfile, $data, $photo, $newSlug): void {
                if ($newSlug !== $leadershipProfile->slug) {
                    // The address given up is kept (old links keep working); an address taken back leaves the history.
                    $leadershipProfile->slugHistory()->where('slug', $newSlug)->delete();
                    $leadershipProfile->slugHistory()->create(['slug' => $leadershipProfile->slug]);
                }
                $leadershipProfile->update($data + $photo + ['slug' => $newSlug]);
            });
        } catch (Throwable $e) {
            if (! $removePhoto) {
                $this->photos->delete($photo['photo_path'] ?? null, $photo['photo_sm_path'] ?? null);
            }
            throw $e;
        }

        // The old files go only after the new row is committed: a failure above never leaves a profile without a photo.
        if ($photo !== []) {
            $this->photos->delete($oldPhoto[0], $oldPhoto[1]);
        }

        $this->log($request, $leadershipProfile, 'updated');

        return $this->withSlugNotice(
            redirect()->route('admin.leadership.index')->with('success', __('admin.leadership.flash_updated', ['name' => $leadershipProfile->displayName()])),
            $typedSlug,
            $newSlug,
        );
    }

    /**
     * A web address that someone typed but that is taken (now, or as another profile's earlier address) is made unique with
     * a number, never refused and never reused. The admin is told, so "saved" does not hide that the address differs.
     */
    private function withSlugNotice(RedirectResponse $response, string $typed, string $used): RedirectResponse
    {
        return $typed !== '' && $typed !== $used
            ? $response->with('warning', __('admin.leadership.flash_slug_adjusted', ['typed' => $typed, 'used' => $used]))
            : $response;
    }

    /** What visitors will see, in both languages, whether or not the profile is public yet. */
    public function preview(LeadershipProfile $leadershipProfile): View
    {
        return view('admin.leadership.preview', [
            'title' => __('admin.leadership.preview_title').' — '.$leadershipProfile->displayName(),
            'breadcrumbs' => [
                ['label' => __('admin.nav.leadership'), 'route' => 'admin.leadership.index'],
                ['label' => __('admin.leadership.preview_title')],
            ],
            'profile' => $leadershipProfile,
        ]);
    }

    /** Activate or deactivate. An archived profile is restored first. */
    public function setStatus(Request $request, LeadershipProfile $leadershipProfile): RedirectResponse
    {
        if ($leadershipProfile->isArchived()) {
            return back()->with('error', __('admin.leadership.flash_archived_first'));
        }

        $next = $leadershipProfile->isPublic() ? LeadershipProfile::STATUS_INACTIVE : LeadershipProfile::STATUS_ACTIVE;
        $leadershipProfile->update(['status' => $next, 'updated_by' => $request->user()->id]);
        $this->log($request, $leadershipProfile, $next === LeadershipProfile::STATUS_ACTIVE ? 'activated' : 'deactivated');

        return back()->with('success', __($next === LeadershipProfile::STATUS_ACTIVE ? 'admin.leadership.flash_activated' : 'admin.leadership.flash_deactivated', ['name' => $leadershipProfile->displayName()]));
    }

    public function toggleFeatured(Request $request, LeadershipProfile $leadershipProfile): RedirectResponse
    {
        $leadershipProfile->update(['featured' => ! $leadershipProfile->featured, 'updated_by' => $request->user()->id]);
        $this->log($request, $leadershipProfile, $leadershipProfile->featured ? 'featured' : 'unfeatured');

        return back()->with('success', __($leadershipProfile->featured ? 'admin.leadership.flash_featured' : 'admin.leadership.flash_unfeatured', ['name' => $leadershipProfile->displayName()]));
    }

    public function moveUp(Request $request, LeadershipProfile $leadershipProfile): RedirectResponse
    {
        return $this->move($request, $leadershipProfile, 'up');
    }

    public function moveDown(Request $request, LeadershipProfile $leadershipProfile): RedirectResponse
    {
        return $this->move($request, $leadershipProfile, 'down');
    }

    public function archive(Request $request, LeadershipProfile $leadershipProfile): RedirectResponse
    {
        // Hidden everywhere and out of the order; kept, with its address, for the record.
        $leadershipProfile->update([
            'status' => LeadershipProfile::STATUS_ARCHIVED,
            'featured' => false,
            'updated_by' => $request->user()->id,
        ]);
        $this->log($request, $leadershipProfile, 'archived');

        return back()->with('success', __('admin.leadership.flash_archived', ['name' => $leadershipProfile->displayName()]));
    }

    /** Back as INACTIVE: restoring never puts anyone on the website by itself. */
    public function restore(Request $request, LeadershipProfile $leadershipProfile): RedirectResponse
    {
        if (! $leadershipProfile->isArchived()) {
            return back();
        }

        $leadershipProfile->update([
            'status' => LeadershipProfile::STATUS_INACTIVE,
            'display_order' => LeadershipProfile::nextDisplayOrder(),
            'updated_by' => $request->user()->id,
        ]);
        $this->log($request, $leadershipProfile, 'restored');

        return back()->with('success', __('admin.leadership.flash_restored', ['name' => $leadershipProfile->displayName()]));
    }

    private function move(Request $request, LeadershipProfile $profile, string $direction): RedirectResponse
    {
        if ($profile->isArchived()) {
            return back();
        }

        if ($profile->move($direction)) {
            $profile->forceFill(['updated_by' => $request->user()->id])->saveQuietly();
            $this->log($request, $profile, 'moved_'.$direction);
        }

        return back();
    }

    private function form(Request $request, LeadershipProfile $profile): View
    {
        $isEdit = $profile->exists;

        return view('admin.leadership.form', [
            'title' => $isEdit ? __('admin.leadership.edit_title', ['name' => $profile->displayName()]) : __('admin.leadership.new_title'),
            'breadcrumbs' => [
                ['label' => __('admin.nav.leadership'), 'route' => 'admin.leadership.index'],
                ['label' => $isEdit ? __('admin.actions.edit') : __('admin.actions.create')],
            ],
            'profile' => $profile,
            'canPublish' => $request->user()->can('leadership.publish'),
            'statuses' => status_options([LeadershipProfile::STATUS_ACTIVE, LeadershipProfile::STATUS_INACTIVE]),
            'max' => LeadershipProfileInput::MAX,
        ]);
    }

    /** @return array<string, mixed> */
    private function validate(Request $request, bool $canPublish): array
    {
        return $request->validate(LeadershipProfileInput::rules($canPublish), [], LeadershipProfileInput::attributes());
    }

    /** @return array{photo_path: string, photo_sm_path: string, photo_width: int, photo_height: int} */
    private function storePhoto(Request $request): array
    {
        try {
            return $this->photos->store($request->file('photo'));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['photo' => upload_error_label($e->getMessage())]);
        }
    }

    /** Who did what to which profile: ids and the action only, never any of the content. */
    private function log(Request $request, LeadershipProfile $profile, string $action): void
    {
        Log::info('Leadership profile changed.', ['user_id' => $request->user()->id, 'profile_id' => $profile->id, 'action' => $action]);
    }
}
