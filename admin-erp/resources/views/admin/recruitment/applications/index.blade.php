@extends('layouts.admin')

@section('content')
    @php $isFiltered = collect($filters)->filter(fn ($v) => $v !== '')->isNotEmpty(); @endphp

    <x-admin.table :paginator="$applications" caption="আবেদনের তালিকা"
        :headers="['আবেদনকারী', 'বিজ্ঞপ্তি', 'জেলা', 'আগ্রহের ক্ষেত্র', 'সংযুক্তি', 'স্ট্যাটাস', 'আবেদনের তারিখ']">

        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.recruitment.applications.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-3">
                    <label for="f-search" class="form-label fs-13 mb-1">খুঁজুন</label>
                    <input type="search" id="f-search" name="search" value="{{ $filters['search'] }}" class="form-control" placeholder="নাম, ই-মেইল, ফোন বা আবেদন নম্বর">
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-status" class="form-label fs-13 mb-1">স্ট্যাটাস</label>
                    <select id="f-status" name="status" class="form-select">
                        <option value="">সব</option>
                        @foreach ($statuses as $v => $l)
                            <option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-skill" class="form-label fs-13 mb-1">দক্ষতা</label>
                    <select id="f-skill" name="skill" class="form-select">
                        <option value="">সব</option>
                        @foreach ($skills as $v => $l)
                            <option value="{{ $v }}" @selected($filters['skill'] === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-district" class="form-label fs-13 mb-1">জেলা</label>
                    <select id="f-district" name="district" class="form-select">
                        <option value="">সব</option>
                        @foreach ($districts as $v => $l)
                            <option value="{{ $v }}" @selected($filters['district'] === (string) $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label for="f-posting" class="form-label fs-13 mb-1">বিজ্ঞপ্তি</label>
                    <select id="f-posting" name="posting" class="form-select">
                        <option value="">সব</option>
                        @foreach ($postings as $id => $title)
                            <option value="{{ $id }}" @selected($filters['posting'] == $id)>{{ $title }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-from" class="form-label fs-13 mb-1">তারিখ (থেকে)</label>
                    <input type="date" id="f-from" name="from" value="{{ $filters['from'] }}" class="form-control">
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-to" class="form-label fs-13 mb-1">তারিখ (পর্যন্ত)</label>
                    <input type="date" id="f-to" name="to" value="{{ $filters['to'] }}" class="form-control">
                </div>
                <div class="col-12 col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-light border w-100">
                        <i class="ti ti-filter me-1" aria-hidden="true"></i>ফিল্টার
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
                <td data-label="আবেদনকারী">
                    <a href="{{ route('admin.recruitment.applications.show', $application) }}" class="fw-semibold">{{ $application->applicant_name }}</a>
                    <span class="d-block text-muted fs-12">{{ $application->application_no }}</span>
                </td>
                <td data-label="বিজ্ঞপ্তি">{{ $application->jobPosting?->title ?? '—' }}</td>
                <td data-label="জেলা">{{ $application->district ?: '—' }}</td>
                <td data-label="আগ্রহের ক্ষেত্র">
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
                <td data-label="সংযুক্তি">
                    {{-- Compact yes/no — a title tooltip carries the detail, so this
                         stays two small glyphs instead of two more text columns. --}}
                    <i class="ti ti-photo {{ $application->photo_path ? 'text-success' : 'text-muted opacity-50' }} me-1"
                       title="{{ $application->photo_path ? 'ছবি আছে' : 'ছবি নেই' }}" aria-label="{{ $application->photo_path ? 'ছবি আছে' : 'ছবি নেই' }}"></i>
                    <i class="ti ti-file-cv {{ $application->cv_path ? 'text-success' : 'text-muted opacity-50' }}"
                       title="{{ $application->cv_path ? 'সিভি আছে' : 'সিভি নেই' }}" aria-label="{{ $application->cv_path ? 'সিভি আছে' : 'সিভি নেই' }}"></i>
                </td>
                <td data-label="স্ট্যাটাস"><x-admin.status-badge :status="$application->status" /></td>
                <td data-label="আবেদনের তারিখ">{{ bn_date($application->created_at) }}</td>
            </tr>
        @empty
            <x-admin.empty-state colspan="7" icon="{{ $isFiltered ? 'ti-search-off' : 'ti-user-off' }}"
                :title="$isFiltered ? 'এই ফিল্টারে কিছু পাওয়া যায়নি' : 'এখনো কোনো আবেদন নেই'" />
        @endforelse
    </x-admin.table>
@endsection
