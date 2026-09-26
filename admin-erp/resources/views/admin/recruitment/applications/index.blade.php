@extends('layouts.admin')

@section('content')
    @php $isFiltered = collect($filters)->filter(fn ($v) => $v !== '')->isNotEmpty(); @endphp

    <x-admin.table :paginator="$applications" caption="{{ __('admin.fields.submissions_list') }}"
        :headers="[__('admin.fields.applicant'), __('admin.fields.notice'), __('admin.fields.district'), __('admin.fields.area_of_interest'), __('admin.fields.attachment'), __('admin.common.status'), __('admin.fields.applied_at')]">

        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.recruitment.applications.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-3">
                    <label for="f-search" class="form-label fs-13 mb-1">{{ __('admin.actions.search') }}</label>
                    <input type="search" id="f-search" name="search" value="{{ $filters['search'] }}" class="form-control" placeholder="{{ __('admin.fields.search_applicant_placeholder') }}">
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-status" class="form-label fs-13 mb-1">{{ __('admin.common.status') }}</label>
                    <select id="f-status" name="status" class="form-select">
                        <option value="">{{ __('admin.common.all') }}</option>
                        @foreach ($statuses as $v => $l)
                            <option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-skill" class="form-label fs-13 mb-1">{{ __('admin.fields.skill') }}</label>
                    <select id="f-skill" name="skill" class="form-select">
                        <option value="">{{ __('admin.common.all') }}</option>
                        @foreach ($skills as $v => $l)
                            <option value="{{ $v }}" @selected($filters['skill'] === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-district" class="form-label fs-13 mb-1">{{ __('admin.fields.district') }}</label>
                    <select id="f-district" name="district" class="form-select">
                        <option value="">{{ __('admin.common.all') }}</option>
                        @foreach ($districts as $v => $l)
                            <option value="{{ $v }}" @selected($filters['district'] === (string) $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label for="f-posting" class="form-label fs-13 mb-1">{{ __('admin.fields.notice') }}</label>
                    <select id="f-posting" name="posting" class="form-select">
                        <option value="">{{ __('admin.common.all') }}</option>
                        @foreach ($postings as $id => $title)
                            <option value="{{ $id }}" @selected($filters['posting'] == $id)>{{ $title }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-from" class="form-label fs-13 mb-1">{{ __('admin.fields.date_from') }}</label>
                    <input type="date" id="f-from" name="from" value="{{ $filters['from'] }}" class="form-control">
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-to" class="form-label fs-13 mb-1">{{ __('admin.fields.date_to') }}</label>
                    <input type="date" id="f-to" name="to" value="{{ $filters['to'] }}" class="form-control">
                </div>
                <div class="col-12 col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-light border w-100">
                        <i class="ti ti-filter me-1" aria-hidden="true"></i>{{ __('admin.actions.filter') }}
                    </button>
                    @if ($isFiltered)
                        <a href="{{ route('admin.recruitment.applications.index') }}" class="btn btn-light" aria-label="{{ __('admin.actions.clear_filters') }}">
                            <i class="ti ti-x" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </form>
        </x-slot:toolbar>

        @forelse ($applications as $application)
            <tr>
                <td data-label="{{ __('admin.fields.applicant') }}">
                    <a href="{{ route('admin.recruitment.applications.show', $application) }}" class="fw-semibold">{{ $application->applicant_name }}</a>
                    <span class="d-block text-muted fs-12">{{ $application->application_no }}</span>
                </td>
                <td data-label="{{ __('admin.fields.notice') }}">{{ $application->jobPosting?->title ?? '—' }}</td>
                <td data-label="{{ __('admin.fields.district') }}">{{ $application->district ?: '—' }}</td>
                <td data-label="{{ __('admin.fields.area_of_interest') }}">
                    @php $labels = $application->skillLabels(); @endphp
                    @if ($labels === [])
                        —
                    @else
                        <span class="fs-12">{{ implode(', ', array_slice($labels, 0, 2)) }}</span>
                        @if (count($labels) > 2)
                            <span class="badge bg-secondary-subtle text-secondary-emphasis">+{{ count($labels) - 2 }}</span>
                        @endif
                    @endif
                </td>
                <td data-label="{{ __('admin.fields.attachment') }}">
                    {{-- Compact yes/no — a title tooltip carries the detail, so this
                         stays two small glyphs instead of two more text columns.
                         photoFileExists()/cvFileExists(), not the raw path columns —
                         "আছে" must mean a file an admin can actually open, not just a
                         path once recorded (see JobApplication::photoFileExists()). --}}
                    @php $rowPhoto = $application->photoFileExists(); $rowCv = $application->cvFileExists(); @endphp
                    <i class="ti ti-photo {{ $rowPhoto ? 'text-success' : 'text-muted opacity-50' }} me-1"
                       title="{{ $rowPhoto ? __('admin.fields.has_photo') : __('admin.fields.no_photo') }}" aria-label="{{ $rowPhoto ? __('admin.fields.has_photo') : __('admin.fields.no_photo') }}"></i>
                    <i class="ti ti-file-cv {{ $rowCv ? 'text-success' : 'text-muted opacity-50' }}"
                       title="{{ $rowCv ? __('admin.fields.has_cv') : __('admin.fields.no_cv') }}" aria-label="{{ $rowCv ? __('admin.fields.has_cv') : __('admin.fields.no_cv') }}"></i>
                </td>
                <td data-label="{{ __('admin.common.status') }}"><x-admin.status-badge :status="$application->status" /></td>
                <td data-label="{{ __('admin.fields.applied_at') }}">{{ bn_date($application->created_at) }}</td>
            </tr>
        @empty
            <x-admin.empty-state colspan="7" icon="{{ $isFiltered ? 'ti-search-off' : 'ti-user-off' }}"
                :title="$isFiltered ? __('admin.filters.no_results') : __('admin.fields.no_submissions_yet')" />
        @endforelse
    </x-admin.table>
@endsection
