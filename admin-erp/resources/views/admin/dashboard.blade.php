@extends('layouts.admin')

@section('content')
    <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 g-3 mb-2">
        @foreach ($stats as $stat)
            @continue(! auth()->user()->can($stat['permission']))
            <div class="col">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-start gap-3">
                            <div class="flex-grow-1">
                                <h2 class="text-muted fs-13 text-uppercase mb-2">{{ $stat['label'] }}</h2>
                                <p class="pf-stat-value mb-1">{{ number_format($stat['value']) }}</p>
                                <p class="text-muted fs-12 mb-0">{{ $stat['hint'] ?? 'বর্তমান মোট' }}</p>
                            </div>
                            <i class="ti {{ $stat['icon'] }} fs-1 text-muted opacity-50" aria-hidden="true"></i>
                        </div>
                        <a href="{{ route($stat['route']) }}" class="stretched-link fs-13 mt-2 d-inline-block">
                            বিস্তারিত দেখুন<span class="visually-hidden"> — {{ $stat['label'] }}</span>
                            <i class="ti ti-arrow-right" aria-hidden="true"></i>
                        </a>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @canany(['organization.create', 'activities.create', 'membership.view', 'recruitment.create'])
        <x-admin.card title="দ্রুত কাজ" class="mb-3">
            <div class="d-flex flex-wrap gap-2">
                @can('organization.create')
                    <a href="{{ route('admin.organization.units.create') }}" class="btn btn-primary btn-sm">
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>নতুন সাংগঠনিক ইউনিট
                    </a>
                @endcan
                @can('activities.view')
                    <a href="{{ route('admin.activities.index') }}" class="btn btn-light btn-sm">
                        <i class="ti ti-calendar-event me-1" aria-hidden="true"></i>কার্যক্রম
                    </a>
                @endcan
                @can('membership.view')
                    <a href="{{ route('admin.membership.index') }}" class="btn btn-light btn-sm">
                        <i class="ti ti-file-description me-1" aria-hidden="true"></i>সদস্যপদ আবেদন
                    </a>
                @endcan
                @can('recruitment.view')
                    <a href="{{ route('admin.recruitment.index') }}" class="btn btn-light btn-sm">
                        <i class="ti ti-briefcase me-1" aria-hidden="true"></i>চাকরির বিজ্ঞপ্তি
                    </a>
                @endcan
            </div>
        </x-admin.card>
    @endcanany

    <div class="row">
        @can('activities.view')
            <div class="col-xl-4">
                <x-admin.card title="সাম্প্রতিক কার্যক্রম" bodyClass="pt-2">
                    @forelse ($recentActivities as $activity)
                        <div class="d-flex justify-content-between align-items-start gap-2 py-2 border-bottom">
                            <div>
                                <p class="mb-0 fw-semibold fs-14">{{ $activity->title }}</p>
                                <p class="text-muted fs-12 mb-0">
                                    {{ $activity->start_datetime ? bn_date($activity->start_datetime) : 'তারিখ নেই' }}
                                </p>
                            </div>
                            <x-admin.status-badge :status="$activity->status" class="fs-11 flex-shrink-0" />
                        </div>
                    @empty
                        <x-admin.empty-state icon="ti-calendar-off" title="কোনো কার্যক্রম নেই"
                            message="কার্যক্রম যোগ করা হলে এখানে দেখা যাবে।" />
                    @endforelse
                </x-admin.card>
            </div>
        @endcan

        @can('membership.view')
            <div class="col-xl-4">
                <x-admin.card title="সাম্প্রতিক সদস্য আবেদন" bodyClass="pt-2">
                    @forelse ($recentMembershipApplications as $application)
                        <div class="d-flex justify-content-between align-items-start gap-2 py-2 border-bottom">
                            <div>
                                <p class="mb-0 fw-semibold fs-14">{{ $application->application_no }}</p>
                                <p class="text-muted fs-12 mb-0">{{ $application->membershipType?->name ?? '—' }}</p>
                            </div>
                            <x-admin.status-badge :status="$application->status" class="fs-11 flex-shrink-0" />
                        </div>
                    @empty
                        <x-admin.empty-state icon="ti-file-off" title="কোনো আবেদন নেই"
                            message="সদস্য আবেদন জমা হলে এখানে দেখা যাবে।" />
                    @endforelse
                </x-admin.card>
            </div>
        @endcan

        @can('recruitment.view')
            <div class="col-xl-4">
                <x-admin.card title="সাম্প্রতিক নিয়োগ আবেদন" bodyClass="pt-2">
                    @forelse ($recentJobApplications as $application)
                        <div class="d-flex justify-content-between align-items-start gap-2 py-2 border-bottom">
                            <div>
                                <p class="mb-0 fw-semibold fs-14">{{ $application->applicant_name }}</p>
                                <p class="text-muted fs-12 mb-0">{{ $application->jobPosting?->title ?? '—' }}</p>
                            </div>
                            <x-admin.status-badge :status="$application->status" class="fs-11 flex-shrink-0" />
                        </div>
                    @empty
                        <x-admin.empty-state icon="ti-user-off" title="কোনো নিয়োগ আবেদন নেই"
                            message="আবেদন জমা হলে এখানে দেখা যাবে।" />
                    @endforelse
                </x-admin.card>
            </div>
        @endcan
    </div>
@endsection
