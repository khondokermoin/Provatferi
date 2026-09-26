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
                                <p class="text-muted fs-12 mb-0">{{ $stat['hint'] ?? __('admin.dashboard.current_total') }}</p>
                            </div>
                            <i class="ti {{ $stat['icon'] }} fs-1 text-muted opacity-50" aria-hidden="true"></i>
                        </div>
                        <a href="{{ route($stat['route']) }}" class="stretched-link fs-13 mt-2 d-inline-block">
                            {{ __('admin.actions.view_details') }}<span class="visually-hidden"> — {{ $stat['label'] }}</span>
                            <i class="ti ti-arrow-right" aria-hidden="true"></i>
                        </a>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @canany(['organization.create', 'activities.create', 'membership.view', 'recruitment.create'])
        <x-admin.card title="{{ __('admin.dashboard.quick_actions') }}" class="mb-3">
            <div class="d-flex flex-wrap gap-2">
                @can('organization.create')
                    <a href="{{ route('admin.organization.units.create') }}" class="btn btn-primary btn-sm">
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.dashboard.new_unit') }}
                    </a>
                @endcan
                @can('activities.view')
                    <a href="{{ route('admin.activities.index') }}" class="btn btn-light btn-sm">
                        <i class="ti ti-calendar-event me-1" aria-hidden="true"></i>{{ __('admin.nav.activities') }}
                    </a>
                @endcan
                @can('membership.view')
                    <a href="{{ route('admin.membership.index') }}" class="btn btn-light btn-sm">
                        <i class="ti ti-file-description me-1" aria-hidden="true"></i>{{ __('admin.nav.membership_applications') }}
                    </a>
                @endcan
                @can('recruitment.view')
                    <a href="{{ route('admin.recruitment.index') }}" class="btn btn-light btn-sm">
                        <i class="ti ti-briefcase me-1" aria-hidden="true"></i>{{ __('admin.nav.job_postings') }}
                    </a>
                @endcan
            </div>
        </x-admin.card>
    @endcanany

    <div class="row">
        @can('activities.view')
            <div class="col-xl-4">
                <x-admin.card title="{{ __('admin.dashboard.recent_activities') }}" bodyClass="pt-2">
                    @forelse ($recentActivities as $activity)
                        <div class="d-flex justify-content-between align-items-start gap-2 py-2 border-bottom">
                            <div>
                                <p class="mb-0 fw-semibold fs-14">{{ $activity->title }}</p>
                                <p class="text-muted fs-12 mb-0">
                                    {{ $activity->start_datetime ? bn_date($activity->start_datetime) : __('admin.dashboard.no_date') }}
                                </p>
                            </div>
                            <x-admin.status-badge :status="$activity->status" class="fs-11 flex-shrink-0" />
                        </div>
                    @empty
                        <x-admin.empty-state icon="ti-calendar-off" title="{{ __('admin.dashboard.no_activities') }}"
                            message="{{ __('admin.dashboard.no_activities_body') }}" />
                    @endforelse
                </x-admin.card>
            </div>
        @endcan

        @can('membership.view')
            <div class="col-xl-4">
                <x-admin.card title="{{ __('admin.dashboard.recent_membership') }}" bodyClass="pt-2">
                    @forelse ($recentMembershipApplications as $application)
                        <div class="d-flex justify-content-between align-items-start gap-2 py-2 border-bottom">
                            <div>
                                <p class="mb-0 fw-semibold fs-14">{{ $application->application_no }}</p>
                                <p class="text-muted fs-12 mb-0">{{ $application->membershipType?->name ?? '—' }}</p>
                            </div>
                            <x-admin.status-badge :status="$application->status" class="fs-11 flex-shrink-0" />
                        </div>
                    @empty
                        <x-admin.empty-state icon="ti-file-off" title="{{ __('admin.dashboard.no_membership') }}"
                            message="{{ __('admin.dashboard.no_membership_body') }}" />
                    @endforelse
                </x-admin.card>
            </div>
        @endcan

        @can('recruitment.view')
            <div class="col-xl-4">
                <x-admin.card title="{{ __('admin.dashboard.recent_job_apps') }}" bodyClass="pt-2">
                    @forelse ($recentJobApplications as $application)
                        <div class="d-flex justify-content-between align-items-start gap-2 py-2 border-bottom">
                            <div>
                                <p class="mb-0 fw-semibold fs-14">{{ $application->applicant_name }}</p>
                                <p class="text-muted fs-12 mb-0">{{ $application->jobPosting?->title ?? '—' }}</p>
                            </div>
                            <x-admin.status-badge :status="$application->status" class="fs-11 flex-shrink-0" />
                        </div>
                    @empty
                        <x-admin.empty-state icon="ti-user-off" title="{{ __('admin.dashboard.no_job_apps') }}"
                            message="{{ __('admin.dashboard.no_job_apps_body') }}" />
                    @endforelse
                </x-admin.card>
            </div>
        @endcan
    </div>
@endsection
