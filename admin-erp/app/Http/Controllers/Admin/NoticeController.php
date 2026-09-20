<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use App\Models\OrganizationalUnit;
use App\Services\NoticeFileService;
use App\Services\NoticeRecruitmentLinker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NoticeController extends Controller
{
    public function __construct(
        private readonly NoticeFileService $files,
        private readonly NoticeRecruitmentLinker $linker,
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'type' => (string) $request->query('type', ''),
            'status' => (string) $request->query('status', ''),
        ];
        $now = now();

        $notices = Notice::query()
            ->with('jobPosting:id,title')
            ->when($filters['search'] !== '', fn ($q) => $q->where('title', 'like', '%'.$filters['search'].'%'))
            ->when(array_key_exists($filters['type'], Notice::TYPES), fn ($q) => $q->where('notice_type', $filters['type']))
            ->when($filters['status'] === 'published', fn ($q) => $q->where(fn ($w) => $w
                ->where('status', 'published')
                ->orWhere(fn ($s) => $s->where('status', 'scheduled')->where('published_at', '<=', $now))))
            ->when($filters['status'] === 'scheduled', fn ($q) => $q->where('status', 'scheduled')
                ->where(fn ($w) => $w->whereNull('published_at')->orWhere('published_at', '>', $now)))
            ->when(in_array($filters['status'], ['draft', 'archived'], true), fn ($q) => $q->where('status', $filters['status']))
            ->latest('updated_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $statusLabel = Notice::STATUSES[$filters['status']] ?? null;

        return view('admin.notices.index', [
            'title' => $statusLabel ? "নোটিশ বোর্ড — {$statusLabel}" : 'নোটিশ বোর্ড',
            'breadcrumbs' => [['label' => 'নোটিশ বোর্ড']],
            'notices' => $notices,
            'filters' => $filters,
            'types' => Notice::TYPES,
            'statuses' => Notice::STATUSES,
        ]);
    }

    public function create(): View
    {
        return view('admin.notices.form', [
            'title' => 'নতুন নোটিশ',
            'breadcrumbs' => [['label' => 'নোটিশ বোর্ড', 'route' => 'admin.notices.index'], ['label' => 'নতুন নোটিশ']],
            'notice' => new Notice(['status' => 'draft', 'notice_type' => 'general']),
            'units' => $this->unitOptions(),
            'types' => Notice::TYPES,
            'statuses' => Notice::STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $notice = new Notice();
        $note = $this->persist($request, $notice);

        return redirect()->route('admin.notices.show', $notice)
            ->with('success', "\u{201c}{$notice->title}\u{201d} তৈরি হয়েছে।".$note);
    }

    public function show(Notice $notice): View
    {
        $notice->load(['jobPosting', 'organizationUnit', 'creator:id,name', 'updater:id,name']);

        return view('admin.notices.show', [
            'title' => $notice->title,
            'breadcrumbs' => [['label' => 'নোটিশ বোর্ড', 'route' => 'admin.notices.index'], ['label' => $notice->title]],
            'notice' => $notice,
        ]);
    }

    public function edit(Notice $notice): View
    {
        $notice->load('jobPosting');

        return view('admin.notices.form', [
            'title' => 'সম্পাদনা — '.$notice->title,
            'breadcrumbs' => [
                ['label' => 'নোটিশ বোর্ড', 'route' => 'admin.notices.index'],
                ['label' => $notice->title, 'route' => 'admin.notices.show', 'params' => $notice],
                ['label' => 'সম্পাদনা'],
            ],
            'notice' => $notice,
            'units' => $this->unitOptions(),
            'types' => Notice::TYPES,
            'statuses' => Notice::STATUSES,
        ]);
    }

    public function update(Request $request, Notice $notice): RedirectResponse
    {
        $note = $this->persist($request, $notice);

        return redirect()->route('admin.notices.show', $notice)
            ->with('success', "\u{201c}{$notice->title}\u{201d} হালনাগাদ হয়েছে।".$note);
    }

    public function publish(Request $request, Notice $notice): RedirectResponse
    {
        $publishedAt = $notice->published_at !== null && ! $notice->published_at->isFuture() ? $notice->published_at : now();

        $notice->forceFill([
            'status' => 'published',
            'published_at' => $publishedAt,
            'first_published_at' => $notice->first_published_at ?? $publishedAt,
            'updated_by' => $request->user()->id,
        ])->save();

        return back()->with('success', "\u{201c}{$notice->title}\u{201d} প্রকাশিত হয়েছে।");
    }

    public function archive(Request $request, Notice $notice): RedirectResponse
    {
        if ($notice->first_published_at === null && $notice->isPubliclyVisible()) {
            $notice->first_published_at = $notice->published_at;
        }

        $notice->forceFill(['status' => 'archived', 'updated_by' => $request->user()->id])->save();

        return back()->with('success', "\u{201c}{$notice->title}\u{201d} আর্কাইভ করা হয়েছে। নোটিশটি ইতিহাস হিসেবে সাইটে থেকে যাবে।");
    }

    public function destroy(Notice $notice): RedirectResponse
    {
        if ($notice->wasEverPublic()) {
            return back()->with('error', 'একবার প্রকাশিত নোটিশ মুছে ফেলা যায় না — প্রাতিষ্ঠানিক ইতিহাস রক্ষায় আর্কাইভ করুন।');
        }

        $title = $notice->title;
        $this->files->delete($notice->cover_image_path);
        $this->files->delete($notice->attachment_path);
        // Free the one-notice-per-posting slot so the posting can be linked again.
        $notice->forceFill(['job_posting_id' => null, 'cover_image_path' => null, 'attachment_path' => null])->save();
        $notice->delete();

        return redirect()->route('admin.notices.index')->with('success', "\u{201c}{$title}\u{201d} মুছে ফেলা হয়েছে।");
    }

    /** Admin-only preview of private files, including those on unpublished notices. */
    public function file(Notice $notice, string $kind): StreamedResponse
    {
        if ($kind === 'cover') {
            abort_unless($notice->cover_image_path, 404);
            $extension = pathinfo($notice->cover_image_path, PATHINFO_EXTENSION);

            return $this->files->response($notice->cover_image_path, "{$notice->slug}-cover.{$extension}", $this->files->coverMime($notice->cover_image_path), true);
        }

        if ($kind === 'share') {
            abort_unless($notice->share_image_path, 404);
            $extension = pathinfo($notice->share_image_path, PATHINFO_EXTENSION);

            return $this->files->response($notice->share_image_path, "{$notice->slug}-share.{$extension}", $this->files->coverMime($notice->share_image_path), true);
        }

        abort_unless($notice->attachment_path, 404);

        return $this->files->response($notice->attachment_path, "{$notice->slug}.pdf", $notice->attachment_mime ?? 'application/pdf', false);
    }

    /** Validates, enforces lifecycle + permission rules, stores files, and saves. Returns an extra flash note. */
    private function persist(Request $request, Notice $notice): string
    {
        $data = $request->validate($this->rules($notice), [], $this->attributes());
        $user = $request->user();
        $isNew = ! $notice->exists;

        foreach (['title', 'summary', 'body'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = str_replace(["\r\n", "\r"], "\n", $data[$field]);
            }
        }

        $status = $data['status'];
        $publishedAt = Notice::toUtc($data['published_at'] ?? null);
        $expiresAt = Notice::toUtc($data['expires_at'] ?? null);

        // The form only carries minute precision; keep the stored moment when the admin didn't move it.
        // Both null is "not moved" too, but then there is no stored moment to keep.
        if (! $isNew && $notice->published_at !== null && $this->sameMinute($notice->published_at, $publishedAt)) {
            $publishedAt = $notice->published_at->copy();
        }

        if ($status === 'published' && $publishedAt === null) {
            $publishedAt = now();
        }

        // A scheduled notice that already went live is simply published now.
        if ($status === 'scheduled' && $publishedAt !== null && ! $publishedAt->isFuture()
            && ! $isNew && $notice->status === 'scheduled' && $notice->isPubliclyVisible()) {
            $status = 'published';
        }

        $errors = [];
        if ($status === 'published' && $publishedAt->isFuture()) {
            $errors['status'] = 'ভবিষ্যতের তারিখে প্রকাশ করতে স্ট্যাটাস “নির্ধারিত” নির্বাচন করুন।';
        }
        if ($status === 'scheduled' && ($publishedAt === null || ! $publishedAt->isFuture())) {
            $errors['published_at'] = 'নির্ধারিত নোটিশের জন্য ভবিষ্যতের প্রকাশের তারিখ ও সময় দিন।';
        }
        if ($expiresAt !== null && $publishedAt !== null && $expiresAt->lte($publishedAt)) {
            $errors['expires_at'] = 'মেয়াদ শেষের তারিখ প্রকাশের তারিখের পরে হতে হবে।';
        }

        $wasPublic = ! $isNew && $notice->wasEverPublic();
        $requestedSlug = $data['slug'] ?? null;
        if ($wasPublic && $requestedSlug !== null && $requestedSlug !== $notice->slug) {
            $errors['slug'] = 'প্রকাশিত নোটিশের URL পরিবর্তন করা যাবে না — আগে শেয়ার করা লিংক ভেঙে যাবে।';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $originalStatus = $isNew ? null : $notice->effectiveStatus();
        $dateMoved = ! $isNew && ! $this->sameMinute($notice->published_at, $publishedAt);
        if (in_array($status, ['published', 'scheduled'], true) && ($originalStatus !== $status || $dateMoved)) {
            abort_unless($user->can('notices.publish'), 403, 'নোটিশ প্রকাশ বা নির্ধারণের অনুমতি আপনার নেই।');
        }
        if ($status === 'archived' && $originalStatus !== 'archived') {
            abort_unless($user->can('notices.archive'), 403, 'নোটিশ আর্কাইভ করার অনুমতি আপনার নেই।');
        }

        $note = '';
        $resync = false;
        if (! $isNew && $notice->job_posting_id !== null) {
            $copyChanged = $notice->title !== $data['title']
                || (string) $notice->summary !== (string) ($data['summary'] ?? '')
                || $notice->body !== $data['body'];
            $wantsSync = $request->boolean('syncs_from_job_posting');

            if ($notice->syncs_from_job_posting && $copyChanged) {
                $notice->syncs_from_job_posting = false;
                $note = ' শিরোনাম বা বিবরণ হাতে সম্পাদনা করায় নিয়োগ বিজ্ঞপ্তি থেকে স্বয়ংক্রিয় হালনাগাদ বন্ধ করা হয়েছে।';
            } elseif (! $notice->syncs_from_job_posting && $wantsSync) {
                $notice->syncs_from_job_posting = true;
                $resync = true;
                $note = ' নিয়োগ বিজ্ঞপ্তি থেকে শিরোনাম ও বিবরণ আবার হালনাগাদ করা হয়েছে।';
            } else {
                $notice->syncs_from_job_posting = $notice->syncs_from_job_posting && $wantsSync;
            }
        }

        $newCover = null;
        $newShareImage = null;
        $newAttachment = null;
        if ($request->hasFile('cover_image')) {
            try {
                $newCover = $this->files->storeCover($request->file('cover_image'));
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['cover_image' => $e->getMessage()]);
            }
        }
        if ($request->hasFile('share_image')) {
            try {
                $newShareImage = $this->files->storeShareImage($request->file('share_image'));
            } catch (RuntimeException $e) {
                $this->files->delete($newCover);
                throw ValidationException::withMessages(['share_image' => $e->getMessage()]);
            }
        }
        if ($request->hasFile('attachment')) {
            try {
                $newAttachment = $this->files->storeAttachment($request->file('attachment'));
            } catch (RuntimeException $e) {
                $this->files->delete($newCover);
                $this->files->delete($newShareImage);
                throw ValidationException::withMessages(['attachment' => $e->getMessage()]);
            }
        }

        if (! $isNew && $notice->first_published_at === null && $notice->isPubliclyVisible()) {
            $notice->first_published_at = $notice->published_at;
        }

        $notice->fill([
            'title' => $data['title'],
            'notice_type' => $data['notice_type'],
            'summary' => $data['summary'] ?? null,
            'body' => $data['body'],
            'status' => $status,
            'published_at' => $publishedAt,
            'expires_at' => $expiresAt,
            'is_pinned' => $request->boolean('is_pinned'),
            'organization_unit_id' => $data['organization_unit_id'] ?? null,
            'action_url' => $data['action_url'] ?? null,
            'action_label' => filled($data['action_url'] ?? null) ? ($data['action_label'] ?? null) : null,
        ]);

        if ($resync && $notice->jobPosting !== null) {
            $this->linker->copyFrom($notice, $notice->jobPosting);
        }

        if (! $wasPublic) {
            $notice->slug = $requestedSlug ?? ($notice->slug ?: Notice::uniqueSlug($data['title'], $notice->id));
        }

        if ($notice->first_published_at === null && $notice->isPubliclyVisible()) {
            $notice->first_published_at = $notice->published_at;
        }

        $oldCover = $notice->cover_image_path;
        $oldShareImage = $notice->share_image_path;
        $oldAttachment = $notice->attachment_path;

        if ($newCover !== null) {
            $notice->cover_image_path = $newCover;
        } elseif ($request->boolean('remove_cover_image')) {
            $notice->cover_image_path = null;
        }

        if ($newShareImage !== null) {
            $notice->share_image_path = $newShareImage;
        } elseif ($request->boolean('remove_share_image')) {
            $notice->share_image_path = null;
        }

        if ($newAttachment !== null) {
            $notice->forceFill([
                'attachment_path' => $newAttachment['path'],
                'attachment_mime' => $newAttachment['mime'],
                'attachment_size' => $newAttachment['size'],
            ]);
        } elseif ($request->boolean('remove_attachment')) {
            $notice->forceFill(['attachment_path' => null, 'attachment_mime' => null, 'attachment_size' => null]);
        }

        $notice->updated_by = $user->id;
        if ($isNew) {
            $notice->created_by = $user->id;
        }

        $notice->save();

        // Only after the new state is persisted: drop files nothing references any more.
        if ($oldCover !== null && $oldCover !== $notice->cover_image_path) {
            $this->files->delete($oldCover);
        }
        if ($oldShareImage !== null && $oldShareImage !== $notice->share_image_path) {
            $this->files->delete($oldShareImage);
        }
        if ($oldAttachment !== null && $oldAttachment !== $notice->attachment_path) {
            $this->files->delete($oldAttachment);
        }

        return $note;
    }

    private function sameMinute(?Carbon $a, ?Carbon $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return $a->copy()->utc()->format('Y-m-d H:i') === $b->copy()->utc()->format('Y-m-d H:i');
    }

    /** @return array<string, mixed> */
    private function rules(Notice $notice): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(Notice::RESERVED_SLUGS), Rule::unique('notices', 'slug')->ignore($notice->id)],
            'notice_type' => ['required', Rule::in(array_keys(Notice::TYPES))],
            'summary' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:30000'],
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'organization_unit_id' => ['nullable', Rule::exists('organizational_units', 'id')],
            'cover_image' => ['nullable', 'file', 'max:5120'],
            'share_image' => ['nullable', 'file', 'max:5120'],
            'attachment' => ['nullable', 'file', 'max:10240'],
            'remove_cover_image' => ['nullable', 'boolean'],
            'remove_share_image' => ['nullable', 'boolean'],
            'remove_attachment' => ['nullable', 'boolean'],
            'action_url' => ['nullable', 'url:http,https', 'max:500'],
            'action_label' => ['nullable', 'string', 'max:100', 'required_with:action_url'],
            'is_pinned' => ['nullable', 'boolean'],
            'syncs_from_job_posting' => ['nullable', 'boolean'],
            'status' => ['required', Rule::in(array_keys(Notice::STATUSES))],
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return [
            'title' => 'বিষয়',
            'slug' => 'URL স্লাগ',
            'notice_type' => 'নোটিশের ধরন',
            'summary' => 'সংক্ষিপ্ত বিবরণ',
            'body' => 'পূর্ণ বিবরণ',
            'published_at' => 'প্রকাশের তারিখ',
            'expires_at' => 'মেয়াদ শেষের তারিখ',
            'organization_unit_id' => 'সাংগঠনিক ইউনিট',
            'cover_image' => 'ছবি',
            'share_image' => 'সামাজিক শেয়ার ছবি',
            'attachment' => 'সংযুক্তি',
            'action_url' => 'অ্যাকশন লিংক',
            'action_label' => 'বাটনের লেখা',
            'status' => 'স্ট্যাটাস',
        ];
    }

    /** @return array<int, string> */
    private function unitOptions(): array
    {
        return OrganizationalUnit::query()->orderBy('name')->pluck('name', 'id')->all();
    }
}
