@extends('layouts.admin')

@section('page-actions')
    @can('activities.update')
        <a href="{{ route('admin.activities.edit', $activity) }}" class="btn btn-primary">
            <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ __('admin.actions.edit') }}
        </a>
    @endcan
    <a href="{{ route('admin.activities.index') }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ __('admin.actions.back') }}
    </a>
@endsection

@section('content')
    <div class="row">
        <div class="col-lg-8">
            <x-admin.card title="{{ __('admin.common.description') }}">
                <dl class="row mb-0">
                    <dt class="col-sm-3 fs-13 text-muted">{{ __('admin.common.summary') }}</dt>
                    <dd class="col-sm-9">{{ $activity->summary ?: '—' }}</dd>

                    <dt class="col-sm-3 fs-13 text-muted">{{ __('admin.common.type') }}</dt>
                    <dd class="col-sm-9">{{ $activity->type?->name ?? '—' }}</dd>

                    <dt class="col-sm-3 fs-13 text-muted">{{ __('admin.fields.start') }}</dt>
                    <dd class="col-sm-9">{{ $activity->start_datetime ? bn_datetime($activity->start_datetime) : '—' }}</dd>

                    <dt class="col-sm-3 fs-13 text-muted">{{ __('admin.fields.end') }}</dt>
                    <dd class="col-sm-9">{{ $activity->end_datetime ? bn_datetime($activity->end_datetime) : '—' }}</dd>

                    <dt class="col-sm-3 fs-13 text-muted">{{ __('admin.fields.venue') }}</dt>
                    <dd class="col-sm-9">{{ $activity->venue ?: '—' }}</dd>

                    <dt class="col-sm-3 fs-13 text-muted">{{ __('admin.common.address') }}</dt>
                    <dd class="col-sm-9">{{ $activity->address ?: '—' }}</dd>

                    <dt class="col-sm-3 fs-13 text-muted">{{ __('admin.fields.participants') }}</dt>
                    <dd class="col-sm-9 mb-0">{{ $activity->participant_count }}</dd>
                </dl>
            </x-admin.card>

            <x-admin.card title="{{ __('admin.fields.details_and_outcomes') }}">
                <h3 class="fs-14 fw-semibold">{{ __('admin.fields.objective') }}</h3>
                <p>{{ $activity->objective ?: '—' }}</p>
                <h3 class="fs-14 fw-semibold">{{ __('admin.fields.what_happened') }}</h3>
                <p>{{ $activity->what_happened ?: '—' }}</p>
                <h3 class="fs-14 fw-semibold">{{ __('admin.fields.outcomes_impact') }}</h3>
                <p class="mb-0">{{ $activity->outcomes ?: '—' }}</p>
            </x-admin.card>

            <x-admin.card title="{{ __('admin.fields.gallery') }}">
                @if (empty($activity->gallery))
                    <p class="text-muted mb-0">{{ __('admin.fields.no_photos_yet') }}</p>
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
            <x-admin.card title="{{ __('admin.common.status') }}">
                <x-admin.status-badge :status="$activity->status" class="mb-3" />
                @if ($activity->featured)
                    <p class="fs-13 mb-2"><i class="ti ti-star me-1 text-warning" aria-hidden="true"></i>{{ __('admin.fields.featured') }}</p>
                @endif
                @if ($activity->published_at)
                    <p class="fs-12 text-muted mb-0">{{ __('admin.fields.published_label') }}: {{ bn_datetime($activity->published_at) }}</p>
                @endif
            </x-admin.card>

            @if ($activity->facebook_post_url)
                <x-admin.card title="{{ __('admin.fields.attached_links') }}">
                    <a href="{{ $activity->facebook_post_url }}" target="_blank" rel="noreferrer">
                        {{ __('admin.fields.facebook_post') }} <i class="ti ti-external-link" aria-hidden="true"></i>
                    </a>
                </x-admin.card>
            @endif
        </div>
    </div>
@endsection
