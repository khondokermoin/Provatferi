@extends('layouts.admin')

@section('page-actions')
    @can('notices.update')
        <a href="{{ route('admin.notices.edit', $notice) }}" class="btn btn-primary">
            <i class="ti ti-pencil me-1" aria-hidden="true"></i>সম্পাদনা
        </a>
    @endcan
    @if ($notice->isPubliclyVisible())
        <a href="{{ $notice->publicUrl() }}" class="btn btn-light" target="_blank" rel="noopener noreferrer">
            <i class="ti ti-external-link me-1" aria-hidden="true"></i>সাইটে দেখুন
        </a>
    @endif
    <a href="{{ route('admin.notices.index') }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>ফিরে যান
    </a>
@endsection

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <x-admin.card title="নোটিশের বিষয়বস্তু">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <span class="badge bg-light text-body border fw-medium">{{ $notice->typeLabel() }}</span>
                    @if ($notice->isActivelyPinned())
                        <span class="badge bg-warning-subtle text-warning-emphasis d-inline-flex align-items-center gap-1">
                            <i class="ti ti-pin" aria-hidden="true"></i>গুরুত্বপূর্ণ
                        </span>
                    @endif
                </div>
                @if ($notice->summary)
                    <p class="fs-15 fw-medium">{{ $notice->summary }}</p>
                    <hr>
                @endif
                <div class="lh-lg">{!! nl2br(e($notice->body)) !!}</div>
            </x-admin.card>

            @if ($notice->cover_image_path || $notice->attachment_path)
                <x-admin.card title="ছবি ও সংযুক্তি">
                    @if ($notice->cover_image_path)
                        <img src="{{ route('admin.notices.file', [$notice, 'cover']) }}" alt="{{ $notice->title }} — ছবি"
                             class="img-fluid rounded border mb-3" style="max-height: 320px">
                    @endif
                    @if ($notice->attachment_path)
                        <div>
                            <a href="{{ route('admin.notices.file', [$notice, 'attachment']) }}" class="btn btn-light border">
                                <i class="ti ti-file-type-pdf me-1" aria-hidden="true"></i>সংযুক্তি ডাউনলোড
                                @if ($notice->attachment_size)
                                    ({{ bn_digits(number_format($notice->attachment_size / 1048576, 1)) }} MB)
                                @endif
                            </a>
                        </div>
                    @endif
                </x-admin.card>
            @endif
        </div>

        <div class="col-lg-4">
            <x-admin.card title="প্রকাশনা">
                <x-admin.status-badge :status="$notice->effectiveStatus()" class="mb-3" />
                <dl class="mb-0">
                    <dt class="fs-13 text-muted">প্রকাশের তারিখ</dt>
                    <dd>{{ $notice->published_at ? bn_datetime($notice->localPublishedAt()) : '—' }}</dd>
                    <dt class="fs-13 text-muted">মেয়াদ শেষ</dt>
                    <dd>
                        {{ $notice->expires_at ? bn_datetime($notice->localExpiresAt()) : 'নির্ধারিত নয়' }}
                        @if ($notice->isExpired())
                            <span class="badge bg-danger-subtle text-danger-emphasis ms-1">মেয়াদোত্তীর্ণ</span>
                        @endif
                    </dd>
                    <dt class="fs-13 text-muted">সাংগঠনিক ইউনিট</dt>
                    <dd>{{ $notice->organizationUnit?->name ?? '—' }}</dd>
                    <dt class="fs-13 text-muted">পাবলিক URL</dt>
                    <dd class="text-break fs-13">{{ $notice->publicUrl() }}</dd>
                    @if ($notice->action_url)
                        <dt class="fs-13 text-muted">অ্যাকশন বাটন</dt>
                        <dd class="text-break fs-13">
                            {{ $notice->action_label }} —
                            <a href="{{ $notice->action_url }}" target="_blank" rel="noopener noreferrer">{{ \Illuminate\Support\Str::limit($notice->action_url, 48) }}</a>
                        </dd>
                    @endif
                    <dt class="fs-13 text-muted">তৈরি করেছেন / সর্বশেষ হালনাগাদ</dt>
                    <dd class="mb-0 fs-13">
                        {{ $notice->creator?->name ?? '—' }} / {{ $notice->updater?->name ?? '—' }}
                        <span class="d-block text-muted">{{ bn_datetime($notice->updated_at?->copy()->timezone(\App\Models\Notice::DISPLAY_TIMEZONE)) }}</span>
                    </dd>
                </dl>
            </x-admin.card>

            @canany(['notices.publish', 'notices.archive', 'notices.delete'])
                <x-admin.card title="অ্যাকশন">
                    <div class="d-grid gap-2">
                        @can('notices.publish')
                            @if ($notice->effectiveStatus() !== 'published')
                                <form method="POST" action="{{ route('admin.notices.publish', $notice) }}" class="d-grid">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="btn btn-success">
                                        <i class="ti ti-world me-1" aria-hidden="true"></i>এখনই প্রকাশ করুন
                                    </button>
                                </form>
                            @endif
                        @endcan
                        @can('notices.archive')
                            @if ($notice->status !== 'archived')
                                <form method="POST" action="{{ route('admin.notices.archive', $notice) }}" class="d-grid">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="btn btn-light border">
                                        <i class="ti ti-archive me-1" aria-hidden="true"></i>আর্কাইভ করুন
                                    </button>
                                </form>
                            @endif
                        @endcan
                        @can('notices.delete')
                            @if (! $notice->wasEverPublic())
                                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#delete-notice">
                                    <i class="ti ti-trash me-1" aria-hidden="true"></i>মুছে ফেলুন
                                </button>
                            @else
                                <p class="fs-12 text-muted mb-0">একবার প্রকাশিত নোটিশ মুছে ফেলা যায় না — প্রাতিষ্ঠানিক ইতিহাস রক্ষায় আর্কাইভ করুন।</p>
                            @endif
                        @endcan
                    </div>
                </x-admin.card>
            @endcanany

            @if ($notice->jobPosting)
                <x-admin.card title="যুক্ত নিয়োগ বিজ্ঞপ্তি">
                    <p class="mb-2">
                        @can('recruitment.view')
                            <a href="{{ route('admin.recruitment.show', $notice->jobPosting) }}" class="fw-semibold">{{ $notice->jobPosting->title }}</a>
                        @else
                            <span class="fw-semibold">{{ $notice->jobPosting->title }}</span>
                        @endcan
                    </p>
                    <p class="fs-12 text-muted mb-0">
                        {{ $notice->syncs_from_job_posting
                            ? 'নিয়োগ বিজ্ঞপ্তি হালনাগাদ হলে এই নোটিশের শিরোনাম ও বিবরণও হালনাগাদ হবে।'
                            : 'এই নোটিশের লেখা আলাদাভাবে সম্পাদিত — স্বয়ংক্রিয় হালনাগাদ বন্ধ।' }}
                    </p>
                </x-admin.card>
            @endif
        </div>
    </div>

    @can('notices.delete')
        @if (! $notice->wasEverPublic())
            <x-admin.modal id="delete-notice" title="নোটিশ মুছে ফেলবেন?">
                <p class="mb-0"><strong>{{ $notice->title }}</strong> মুছে ফেলা হবে। এটি কখনো প্রকাশিত হয়নি, তাই কোনো পাবলিক লিংক ভাঙবে না।</p>
                <x-slot:confirm>
                    <form method="POST" action="{{ route('admin.notices.destroy', $notice) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger">মুছে ফেলুন</button>
                    </form>
                </x-slot:confirm>
            </x-admin.modal>
        @endif
    @endcan
@endsection
