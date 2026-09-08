@extends('layouts.admin')

@section('page-actions')
    @can('activities.update')
        <a href="{{ route('admin.activities.edit', $activity) }}" class="btn btn-primary">
            <i class="ti ti-pencil me-1" aria-hidden="true"></i>Edit
        </a>
    @endcan
    <a href="{{ route('admin.activities.index') }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Back
    </a>
@endsection

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <x-admin.card title="বিবরণ">
                <dl class="row mb-0">
                    <dt class="col-sm-3 fs-13 text-muted">সংক্ষিপ্ত বিবরণ</dt>
                    <dd class="col-sm-9">{{ $activity->summary ?: '—' }}</dd>

                    <dt class="col-sm-3 fs-13 text-muted">ধরন</dt>
                    <dd class="col-sm-9">{{ $activity->type?->name ?? '—' }}</dd>

                    <dt class="col-sm-3 fs-13 text-muted">শুরু</dt>
                    <dd class="col-sm-9">{{ $activity->start_datetime?->format('d M Y, H:i') ?? '—' }}</dd>

                    <dt class="col-sm-3 fs-13 text-muted">শেষ</dt>
                    <dd class="col-sm-9">{{ $activity->end_datetime?->format('d M Y, H:i') ?? '—' }}</dd>

                    <dt class="col-sm-3 fs-13 text-muted">ভেন্যু</dt>
                    <dd class="col-sm-9">{{ $activity->venue ?: '—' }}</dd>

                    <dt class="col-sm-3 fs-13 text-muted">ঠিকানা</dt>
                    <dd class="col-sm-9">{{ $activity->address ?: '—' }}</dd>

                    <dt class="col-sm-3 fs-13 text-muted">অংশগ্রহণকারী</dt>
                    <dd class="col-sm-9 mb-0">{{ $activity->participant_count }}</dd>
                </dl>
            </x-admin.card>

            <x-admin.card title="বিস্তারিত ও ফলাফল">
                <h3 class="fs-14 fw-semibold">উদ্দেশ্য</h3>
                <p>{{ $activity->objective ?: '—' }}</p>
                <h3 class="fs-14 fw-semibold">কী ঘটেছে</h3>
                <p>{{ $activity->what_happened ?: '—' }}</p>
                <h3 class="fs-14 fw-semibold">ফলাফল ও প্রভাব</h3>
                <p class="mb-0">{{ $activity->outcomes ?: '—' }}</p>
            </x-admin.card>

            <x-admin.card title="গ্যালারি">
                @if (empty($activity->gallery))
                    <p class="text-muted mb-0">এখনো কোনো ছবি যুক্ত করা হয়নি।</p>
                @else
                    <div class="d-flex flex-wrap gap-2">
                        @foreach ($activity->gallery as $path)
                            <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ $path }}</span>
                        @endforeach
                    </div>
                @endif
            </x-admin.card>
        </div>

        <div class="col-lg-4">
            <x-admin.card title="স্ট্যাটাস">
                <x-admin.status-badge :status="$activity->status" class="mb-3" />
                @if ($activity->featured)
                    <p class="fs-13 mb-2"><i class="ti ti-star me-1 text-warning" aria-hidden="true"></i>ফিচার্ড</p>
                @endif
                @if ($activity->published_at)
                    <p class="fs-12 text-muted mb-0">প্রকাশিত: {{ $activity->published_at->format('d M Y, H:i') }}</p>
                @endif
            </x-admin.card>

            @if ($activity->facebook_post_url)
                <x-admin.card title="সংযুক্ত লিঙ্ক">
                    <a href="{{ $activity->facebook_post_url }}" target="_blank" rel="noreferrer">
                        ফেসবুক পোস্ট <i class="ti ti-external-link" aria-hidden="true"></i>
                    </a>
                </x-admin.card>
            @endif
        </div>
    </div>
@endsection
