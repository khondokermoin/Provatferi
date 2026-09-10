@extends('layouts.admin')

@section('page-actions')
    @can('recruitment.create')
        <a href="{{ route('admin.recruitment.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>নতুন চাকরির বিজ্ঞপ্তি
        </a>
    @endcan
@endsection

@section('content')
    @php $isFiltered = collect($filters)->filter(fn ($v) => $v !== '')->isNotEmpty(); @endphp

    <x-admin.table :paginator="$jobPostings" caption="নিয়োগ বিজ্ঞপ্তির তালিকা"
        :headers="['শিরোনাম', 'স্ট্যাটাস', 'শেষ তারিখ', 'আবেদনসমূহ', ['label' => 'অ্যাকশন', 'align' => 'end']]">

        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.recruitment.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-6">
                    <label for="f-search" class="form-label fs-13 mb-1">খুঁজুন</label>
                    <input type="search" id="f-search" name="search" value="{{ $filters['search'] }}" class="form-control" placeholder="শিরোনাম">
                </div>
                <div class="col-6 col-md-3">
                    <label for="f-status" class="form-label fs-13 mb-1">স্ট্যাটাস</label>
                    <select id="f-status" name="status" class="form-select">
                        <option value="">সব</option>
                        @foreach ($statuses as $v => $l)
                            <option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-light border w-100">
                        <i class="ti ti-filter me-1" aria-hidden="true"></i>ফিল্টার
                    </button>
                    @if ($isFiltered)
                        <a href="{{ route('admin.recruitment.index') }}" class="btn btn-light" aria-label="ফিল্টার সরান">
                            <i class="ti ti-x" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </form>
        </x-slot:toolbar>

        @forelse ($jobPostings as $job)
            <tr>
                <td data-label="শিরোনাম">
                    <a href="{{ route('admin.recruitment.show', $job) }}" class="fw-semibold">{{ $job->title }}</a>
                </td>
                <td data-label="স্ট্যাটাস"><x-admin.status-badge :status="$job->status" /></td>
                <td data-label="শেষ তারিখ">{{ $job->application_deadline ? bn_date($job->application_deadline) : '—' }}</td>
                <td data-label="আবেদনসমূহ">{{ $job->applications_count }}</td>
                <td data-label="অ্যাকশন" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="{{ $job->title }} — অ্যাকশন মেনু">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a href="{{ route('admin.recruitment.show', $job) }}" class="dropdown-item">
                                <i class="ti ti-eye me-1" aria-hidden="true"></i>দেখুন
                            </a>
                            @can('recruitment.update')
                                <a href="{{ route('admin.recruitment.edit', $job) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>সম্পাদনা
                                </a>
                            @endcan
                            @can('recruitment.delete')
                                <div class="dropdown-divider"></div>
                                <button type="button" class="dropdown-item text-danger"
                                        data-bs-toggle="modal" data-bs-target="#delete-job-{{ $job->id }}">
                                    <i class="ti ti-trash me-1" aria-hidden="true"></i>মুছে ফেলুন
                                </button>
                            @endcan
                        </div>
                    </div>
                </td>
            </tr>
        @empty
            <x-admin.empty-state colspan="5" icon="{{ $isFiltered ? 'ti-search-off' : 'ti-briefcase-off' }}"
                :title="$isFiltered ? 'এই ফিল্টারে কিছু পাওয়া যায়নি' : 'এখনো কোনো নিয়োগ বিজ্ঞপ্তি নেই'"
                message="বিজ্ঞপ্তি প্রকাশ করা হলে এখানে দেখা যাবে।">
                @can('recruitment.create')
                    @unless ($isFiltered)
                        <a href="{{ route('admin.recruitment.create') }}" class="btn btn-primary btn-sm">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>নতুন চাকরির বিজ্ঞপ্তি
                        </a>
                    @endunless
                @endcan
            </x-admin.empty-state>
        @endforelse
    </x-admin.table>

    @can('recruitment.delete')
        @foreach ($jobPostings as $job)
            <x-admin.modal :id="'delete-job-'.$job->id" title="বিজ্ঞপ্তি মুছে ফেলবেন?">
                <p class="mb-0">
                    <strong>{{ $job->title }}</strong> মুছে ফেলা হবে।
                    @if ($job->applications_count > 0)
                        <span class="d-block text-danger mt-2">
                            <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                            এই বিজ্ঞপ্তিতে আবেদন জমা পড়েছে — মুছে ফেলা যাবে না।
                        </span>
                    @endif
                </p>
                <x-slot:confirm>
                    <form method="POST" action="{{ route('admin.recruitment.destroy', $job) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger">মুছে ফেলুন</button>
                    </form>
                </x-slot:confirm>
            </x-admin.modal>
        @endforeach
    @endcan
@endsection
