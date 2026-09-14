@extends('layouts.admin')

@section('page-actions')
    @can('notices.create')
        <a href="{{ route('admin.notices.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>নতুন নোটিশ
        </a>
    @endcan
@endsection

@section('content')
    @php
        $isFiltered = $filters['search'] !== '' || $filters['type'] !== '';
        $tabs = ['' => 'সকল', 'published' => 'প্রকাশিত', 'draft' => 'খসড়া', 'scheduled' => 'নির্ধারিত', 'archived' => 'আর্কাইভ'];
    @endphp

    <nav aria-label="স্ট্যাটাস অনুযায়ী নোটিশ" class="mb-3">
        <ul class="nav nav-pills flex-wrap gap-1">
            @foreach ($tabs as $key => $label)
                @php $active = $filters['status'] === (string) $key; @endphp
                <li class="nav-item">
                    <a href="{{ route('admin.notices.index', array_filter(['status' => (string) $key, 'type' => $filters['type'], 'search' => $filters['search']], fn ($v) => $v !== '')) }}"
                       class="nav-link py-1 px-3 {{ $active ? 'active' : '' }}" @if ($active) aria-current="page" @endif>{{ $label }}</a>
                </li>
            @endforeach
        </ul>
    </nav>

    <x-admin.table :paginator="$notices" caption="নোটিশের তালিকা"
        :headers="['বিষয়', 'স্ট্যাটাস', 'প্রকাশের তারিখ', 'মেয়াদ', ['label' => 'অ্যাকশন', 'align' => 'end']]">

        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.notices.index') }}" class="row g-2 align-items-end">
                @if ($filters['status'] !== '')
                    <input type="hidden" name="status" value="{{ $filters['status'] }}">
                @endif
                <div class="col-12 col-md-5">
                    <label for="f-search" class="form-label fs-13 mb-1">খুঁজুন</label>
                    <input type="search" id="f-search" name="search" value="{{ $filters['search'] }}" class="form-control" placeholder="নোটিশের বিষয়">
                </div>
                <div class="col-12 col-md-4">
                    <label for="f-type" class="form-label fs-13 mb-1">ধরন</label>
                    <select id="f-type" name="type" class="form-select">
                        <option value="">সব ধরন</option>
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-light border w-100">
                        <i class="ti ti-filter me-1" aria-hidden="true"></i>ফিল্টার
                    </button>
                    @if ($isFiltered)
                        <a href="{{ route('admin.notices.index', array_filter(['status' => $filters['status']])) }}" class="btn btn-light" aria-label="ফিল্টার সরান">
                            <i class="ti ti-x" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </form>
        </x-slot:toolbar>

        @forelse ($notices as $notice)
            <tr>
                <td data-label="বিষয়">
                    <div class="d-flex flex-column gap-1">
                        <span class="d-flex flex-wrap align-items-center gap-2">
                            <span class="badge bg-light text-body border fw-medium">{{ $notice->typeLabel() }}</span>
                            @if ($notice->isActivelyPinned())
                                <span class="badge bg-warning-subtle text-warning-emphasis d-inline-flex align-items-center gap-1">
                                    <i class="ti ti-pin" aria-hidden="true"></i>গুরুত্বপূর্ণ
                                </span>
                            @endif
                        </span>
                        <a href="{{ route('admin.notices.show', $notice) }}" class="fw-semibold">{{ $notice->title }}</a>
                        @if ($notice->jobPosting)
                            <span class="fs-12 text-muted">
                                <i class="ti ti-link" aria-hidden="true"></i> নিয়োগ বিজ্ঞপ্তি: {{ $notice->jobPosting->title }}
                            </span>
                        @endif
                    </div>
                </td>
                <td data-label="স্ট্যাটাস"><x-admin.status-badge :status="$notice->effectiveStatus()" /></td>
                <td data-label="প্রকাশের তারিখ">{{ $notice->published_at ? bn_datetime($notice->localPublishedAt()) : '—' }}</td>
                <td data-label="মেয়াদ">
                    @if ($notice->expires_at)
                        {{ bn_date($notice->localExpiresAt()) }}
                        @if ($notice->isExpired())
                            <span class="badge bg-danger-subtle text-danger-emphasis ms-1">মেয়াদোত্তীর্ণ</span>
                        @endif
                    @else
                        <span class="text-muted">নেই</span>
                    @endif
                </td>
                <td data-label="অ্যাকশন" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="{{ $notice->title }} — অ্যাকশন মেনু">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a href="{{ route('admin.notices.show', $notice) }}" class="dropdown-item">
                                <i class="ti ti-eye me-1" aria-hidden="true"></i>দেখুন
                            </a>
                            @can('notices.update')
                                <a href="{{ route('admin.notices.edit', $notice) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>সম্পাদনা
                                </a>
                            @endcan
                            @can('notices.publish')
                                @if ($notice->effectiveStatus() !== 'published')
                                    <form method="POST" action="{{ route('admin.notices.publish', $notice) }}">
                                        @csrf @method('PATCH')
                                        <button type="submit" class="dropdown-item">
                                            <i class="ti ti-world me-1" aria-hidden="true"></i>এখনই প্রকাশ করুন
                                        </button>
                                    </form>
                                @endif
                            @endcan
                            @can('notices.archive')
                                @if ($notice->status !== 'archived')
                                    <form method="POST" action="{{ route('admin.notices.archive', $notice) }}">
                                        @csrf @method('PATCH')
                                        <button type="submit" class="dropdown-item">
                                            <i class="ti ti-archive me-1" aria-hidden="true"></i>আর্কাইভ করুন
                                        </button>
                                    </form>
                                @endif
                            @endcan
                        </div>
                    </div>
                </td>
            </tr>
        @empty
            <x-admin.empty-state colspan="5" icon="{{ $isFiltered ? 'ti-search-off' : 'ti-speakerphone' }}"
                :title="$isFiltered ? 'এই ফিল্টারে কোনো নোটিশ পাওয়া যায়নি' : 'এখানে কোনো নোটিশ নেই'"
                message="প্রকাশিত, খসড়া, নির্ধারিত ও আর্কাইভ করা নোটিশ এখানে দেখা যাবে।">
                @can('notices.create')
                    @unless ($isFiltered)
                        <a href="{{ route('admin.notices.create') }}" class="btn btn-primary btn-sm">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>নতুন নোটিশ
                        </a>
                    @endunless
                @endcan
            </x-admin.empty-state>
        @endforelse
    </x-admin.table>
@endsection
