@extends('layouts.admin')

@section('page-actions')
    @can('recruitment.update')
        <a href="{{ route('admin.recruitment.edit', $jobPosting) }}" class="btn btn-primary">
            <i class="ti ti-pencil me-1" aria-hidden="true"></i>Edit
        </a>
    @endcan
    @can('recruitment.view')
        <a href="{{ route('admin.recruitment.applications.index', ['posting' => $jobPosting->id]) }}" class="btn btn-light">
            <i class="ti ti-users me-1" aria-hidden="true"></i>Applications ({{ $jobPosting->applications_count }})
        </a>
    @endcan
    <a href="{{ route('admin.recruitment.index') }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Back
    </a>
@endsection

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <x-admin.card title="বিবরণ">
                <p class="text-muted">{{ $jobPosting->summary ?: '—' }}</p>
                <h3 class="fs-14 fw-semibold">পূর্ণ বিবরণ</h3>
                <p>{{ $jobPosting->description }}</p>
                <h3 class="fs-14 fw-semibold">যোগ্যতা</h3>
                <p class="mb-0">{{ $jobPosting->requirements ?: '—' }}</p>
            </x-admin.card>
        </div>

        <div class="col-lg-4">
            <x-admin.card title="স্ট্যাটাস">
                <x-admin.status-badge :status="$jobPosting->status" class="mb-3" />
                @if ($jobPosting->published_at)
                    <p class="fs-12 text-muted mb-0">প্রকাশিত: {{ $jobPosting->published_at->format('d M Y, H:i') }}</p>
                @endif
            </x-admin.card>

            <x-admin.card title="তথ্য">
                <dl class="mb-0">
                    <dt class="fs-13 text-muted">সাংগঠনিক ইউনিট</dt>
                    <dd>{{ $jobPosting->organizationUnit?->name ?? '—' }}</dd>
                    <dt class="fs-13 text-muted">নিয়োগের ধরন</dt>
                    <dd>{{ $jobPosting->employment_type ?? '—' }}</dd>
                    <dt class="fs-13 text-muted">আবেদন শুরু</dt>
                    <dd>{{ $jobPosting->opening_date?->format('d M Y') ?? '—' }}</dd>
                    <dt class="fs-13 text-muted">আবেদনের শেষ তারিখ</dt>
                    <dd class="mb-0">{{ $jobPosting->application_deadline?->format('d M Y') ?? '—' }}</dd>
                </dl>
            </x-admin.card>
        </div>
    </div>
@endsection
