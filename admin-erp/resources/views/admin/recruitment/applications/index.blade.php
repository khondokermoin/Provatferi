@extends('layouts.admin')

@section('content')
    @php $isFiltered = collect($filters)->filter(fn ($v) => $v !== '')->isNotEmpty(); @endphp

    <x-admin.table :paginator="$applications" caption="নিয়োগ আবেদনের তালিকা"
        :headers="['Applicant', 'Posting', 'Status', 'Applied']">

        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.recruitment.applications.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="f-search" class="form-label fs-13 mb-1">Search</label>
                    <input type="search" id="f-search" name="search" value="{{ $filters['search'] }}" class="form-control" placeholder="নাম বা ই-মেইল">
                </div>
                <div class="col-6 col-md-3">
                    <label for="f-status" class="form-label fs-13 mb-1">Status</label>
                    <select id="f-status" name="status" class="form-select">
                        <option value="">All</option>
                        @foreach ($statuses as $v => $l)
                            <option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label for="f-posting" class="form-label fs-13 mb-1">Posting</label>
                    <select id="f-posting" name="posting" class="form-select">
                        <option value="">All</option>
                        @foreach ($postings as $id => $title)
                            <option value="{{ $id }}" @selected($filters['posting'] == $id)>{{ $title }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-light border w-100">
                        <i class="ti ti-filter me-1" aria-hidden="true"></i>Filter
                    </button>
                    @if ($isFiltered)
                        <a href="{{ route('admin.recruitment.applications.index') }}" class="btn btn-light" aria-label="ফিল্টার সরান">
                            <i class="ti ti-x" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </form>
        </x-slot:toolbar>

        @forelse ($applications as $application)
            <tr>
                <td data-label="Applicant">
                    <a href="{{ route('admin.recruitment.applications.show', $application) }}" class="fw-semibold">{{ $application->applicant_name }}</a>
                    <span class="d-block text-muted fs-12">{{ $application->applicant_email }}</span>
                </td>
                <td data-label="Posting">{{ $application->jobPosting?->title ?? '—' }}</td>
                <td data-label="Status"><x-admin.status-badge :status="$application->status" /></td>
                <td data-label="Applied">{{ $application->created_at->format('d M Y') }}</td>
            </tr>
        @empty
            <x-admin.empty-state colspan="4" icon="{{ $isFiltered ? 'ti-search-off' : 'ti-user-off' }}"
                :title="$isFiltered ? 'এই ফিল্টারে কিছু পাওয়া যায়নি' : 'এখনো কোনো নিয়োগ আবেদন নেই'" />
        @endforelse
    </x-admin.table>
@endsection
