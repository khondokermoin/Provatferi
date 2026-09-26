@extends('layouts.admin')

@section('page-actions')
    @can('recruitment.create')
        <a href="{{ route('admin.recruitment.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.fields.new_job_posting') }}
        </a>
    @endcan
@endsection

@section('content')
    @php $isFiltered = collect($filters)->filter(fn ($v) => $v !== '')->isNotEmpty(); @endphp

    <x-admin.table :paginator="$jobPostings" caption="{{ __('admin.fields.job_postings_list') }}"
        :headers="[__('admin.common.title'), __('admin.common.status'), __('admin.fields.deadline'), __('admin.fields.applications'), ['label' => __('admin.actions.actions'), 'align' => 'end']]">

        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.recruitment.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-6">
                    <label for="f-search" class="form-label fs-13 mb-1">{{ __('admin.actions.search') }}</label>
                    <input type="search" id="f-search" name="search" value="{{ $filters['search'] }}" class="form-control" placeholder="{{ __('admin.common.title') }}">
                </div>
                <div class="col-6 col-md-3">
                    <label for="f-status" class="form-label fs-13 mb-1">{{ __('admin.common.status') }}</label>
                    <select id="f-status" name="status" class="form-select">
                        <option value="">{{ __('admin.common.all') }}</option>
                        @foreach ($statuses as $v => $l)
                            <option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-light border w-100">
                        <i class="ti ti-filter me-1" aria-hidden="true"></i>{{ __('admin.actions.filter') }}
                    </button>
                    @if ($isFiltered)
                        <a href="{{ route('admin.recruitment.index') }}" class="btn btn-light" aria-label="{{ __('admin.actions.clear_filters') }}">
                            <i class="ti ti-x" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </form>
        </x-slot:toolbar>

        @forelse ($jobPostings as $job)
            <tr>
                <td data-label="{{ __('admin.common.title') }}">
                    <a href="{{ route('admin.recruitment.show', $job) }}" class="fw-semibold">{{ $job->title }}</a>
                </td>
                <td data-label="{{ __('admin.common.status') }}"><x-admin.status-badge :status="$job->status" /></td>
                <td data-label="{{ __('admin.fields.deadline') }}">{{ $job->isRolling() ? __('admin.fields.ongoing') : ($job->application_deadline ? bn_date($job->application_deadline) : '—') }}</td>
                <td data-label="{{ __('admin.fields.applications') }}">{{ $job->applications_count }}</td>
                <td data-label="{{ __('admin.actions.actions') }}" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="{{ $job->title }} — {{ __('admin.fields.action_menu') }}">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a href="{{ route('admin.recruitment.show', $job) }}" class="dropdown-item">
                                <i class="ti ti-eye me-1" aria-hidden="true"></i>{{ __('admin.actions.view') }}
                            </a>
                            @can('recruitment.update')
                                <a href="{{ route('admin.recruitment.edit', $job) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ __('admin.actions.edit') }}
                                </a>
                            @endcan
                            @can('recruitment.delete')
                                <div class="dropdown-divider"></div>
                                <button type="button" class="dropdown-item text-danger"
                                        data-bs-toggle="modal" data-bs-target="#delete-job-{{ $job->id }}">
                                    <i class="ti ti-trash me-1" aria-hidden="true"></i>{{ __('admin.actions.delete') }}
                                </button>
                            @endcan
                        </div>
                    </div>
                </td>
            </tr>
        @empty
            <x-admin.empty-state colspan="5" icon="{{ $isFiltered ? 'ti-search-off' : 'ti-briefcase-off' }}"
                :title="$isFiltered ? __('admin.filters.no_results') : __('admin.fields.no_job_postings_yet')"
                message="{{ __('admin.fields.postings_empty_hint') }}">
                @can('recruitment.create')
                    @unless ($isFiltered)
                        <a href="{{ route('admin.recruitment.create') }}" class="btn btn-primary btn-sm">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ __('admin.fields.new_job_posting') }}
                        </a>
                    @endunless
                @endcan
            </x-admin.empty-state>
        @endforelse
    </x-admin.table>

    @can('recruitment.delete')
        @foreach ($jobPostings as $job)
            <x-admin.modal :id="'delete-job-'.$job->id" title="{{ __('admin.fields.delete_posting_title') }}">
                <p class="mb-0">
                    <strong>{{ $job->title }}</strong> {{ __('admin.fields.will_be_deleted') }}
                    @if ($job->applications_count > 0)
                        <span class="d-block text-danger mt-2">
                            <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                            {{ __('admin.fields.posting_has_applications_cannot_delete') }}
                        </span>
                    @endif
                </p>
                <x-slot:confirm>
                    <form method="POST" action="{{ route('admin.recruitment.destroy', $job) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger">{{ __('admin.actions.delete') }}</button>
                    </form>
                </x-slot:confirm>
            </x-admin.modal>
        @endforeach
    @endcan
@endsection
