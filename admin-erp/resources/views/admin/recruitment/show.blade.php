@extends('layouts.admin')

@section('page-actions')
    @can('recruitment.update')
        <a href="{{ route('admin.recruitment.edit', $jobPosting) }}" class="btn btn-primary">
            <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ __('admin.actions.edit') }}
        </a>
    @endcan
    @can('recruitment.view')
        <a href="{{ route('admin.recruitment.applications.index', ['posting' => $jobPosting->id]) }}" class="btn btn-light">
            <i class="ti ti-users me-1" aria-hidden="true"></i>Applications ({{ $jobPosting->applications_count }})
        </a>
    @endcan
    <a href="{{ route('admin.recruitment.index') }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ __('admin.actions.back') }}
    </a>
@endsection

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <x-admin.card title="{{ __('admin.common.description') }}">
                <p class="text-muted">{{ $jobPosting->summary ?: '—' }}</p>
                <h3 class="fs-14 fw-semibold">{{ __('admin.fields.full_body') }}</h3>
                <p>{!! nl2br(e($jobPosting->description)) !!}</p>
                <h3 class="fs-14 fw-semibold">{{ __('admin.fields.requirements') }}</h3>
                <p class="mb-0">{!! $jobPosting->requirements ? nl2br(e($jobPosting->requirements)) : '—' !!}</p>
            </x-admin.card>
        </div>

        <div class="col-lg-4">
            <x-admin.card title="{{ __('admin.common.status') }}">
                <x-admin.status-badge :status="$jobPosting->status" class="mb-3" />
                @if ($jobPosting->published_at)
                    <p class="fs-12 text-muted mb-0">{{ __('admin.fields.published_label') }}: {{ bn_datetime($jobPosting->published_at) }}</p>
                @endif
            </x-admin.card>

            <x-admin.card title="{{ __('admin.fields.info') }}">
                <dl class="mb-0">
                    <dt class="fs-13 text-muted">{{ __('admin.fields.unit') }}</dt>
                    <dd>{{ $jobPosting->organizationUnit?->name ?? '—' }}</dd>
                    <dt class="fs-13 text-muted">{{ __('admin.fields.department') }}</dt>
                    <dd>{{ $jobPosting->department ?: '—' }}</dd>
                    <dt class="fs-13 text-muted">{{ __('admin.fields.employment_type') }}</dt>
                    <dd>{{ $jobPosting->employmentTypeLabel() ?? '—' }}</dd>
                    <dt class="fs-13 text-muted">{{ __('admin.fields.compensation') }}</dt>
                    <dd>
                        @if ($jobPosting->isVolunteer())
                            <span class="fs-13">{{ \App\Models\JobPosting::VOLUNTEER_NOTE }}</span>
                        @else
                            {{ $jobPosting->salary_range ?: '—' }}
                        @endif
                    </dd>
                    <dt class="fs-13 text-muted">{{ __('admin.fields.application_opens') }}</dt>
                    <dd>{{ $jobPosting->opening_date ? bn_date($jobPosting->opening_date) : '—' }}</dd>
                    <dt class="fs-13 text-muted">{{ __('admin.fields.application_deadline_label') }}</dt>
                    <dd class="mb-0">
                        @if ($jobPosting->isRolling())
                            {{ __('admin.fields.applications_rolling') }}
                        @else
                            {{ $jobPosting->application_deadline ? bn_date($jobPosting->application_deadline) : '—' }}
                        @endif
                    </dd>
                </dl>
            </x-admin.card>

            <x-admin.card title="{{ __('admin.nav.notices') }}">
                @if ($jobPosting->notice)
                    <x-admin.status-badge :status="$jobPosting->notice->effectiveStatus()" class="mb-2" />
                    <p class="mb-2">
                        @can('notices.view')
                            <a href="{{ route('admin.notices.show', $jobPosting->notice) }}" class="fw-semibold">{{ $jobPosting->notice->title }}</a>
                        @else
                            <span class="fw-semibold">{{ $jobPosting->notice->title }}</span>
                        @endcan
                    </p>
                    <p class="fs-12 text-muted mb-0">
                        {{ $jobPosting->notice->syncs_from_job_posting
                            ? __('admin.fields.notice_syncs_help')
                            : __('admin.fields.notice_sync_off_help') }}
                    </p>
                @else
                    <p class="fs-13 text-muted mb-0">{{ __('admin.fields.not_on_notice_board_hint') }}</p>
                @endif
            </x-admin.card>
        </div>
    </div>
@endsection
