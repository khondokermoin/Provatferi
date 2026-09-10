@extends('layouts.admin')

@section('content')
    @php $isFiltered = collect($filters)->filter(fn ($v) => $v !== '')->isNotEmpty(); @endphp

    <x-admin.table :paginator="$applications" caption="সদস্য আবেদনের তালিকা"
        :headers="['আবেদন নং', 'আবেদনকারী', 'ধরন', 'স্ট্যাটাস', 'জমার তারিখ']">

        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.membership.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="f-search" class="form-label fs-13 mb-1">খুঁজুন</label>
                    <input type="search" id="f-search" name="search" value="{{ $filters['search'] }}" class="form-control" placeholder="আবেদন নং, নাম বা ই-মেইল">
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
                    <label for="f-type" class="form-label fs-13 mb-1">ধরন</label>
                    <select id="f-type" name="type" class="form-select">
                        <option value="">সব</option>
                        @foreach ($types as $id => $name)
                            <option value="{{ $id }}" @selected($filters['type'] == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-date" class="form-label fs-13 mb-1">তারিখ</label>
                    <input type="date" id="f-date" name="date" value="{{ $filters['date'] }}" class="form-control">
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-light border w-100">
                        <i class="ti ti-filter me-1" aria-hidden="true"></i>ফিল্টার
                    </button>
                    @if ($isFiltered)
                        <a href="{{ route('admin.membership.index') }}" class="btn btn-light" aria-label="ফিল্টার সরান">
                            <i class="ti ti-x" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </form>
        </x-slot:toolbar>

        @forelse ($applications as $application)
            <tr>
                <td data-label="আবেদন নং">
                    <a href="{{ route('admin.membership.show', $application) }}" class="fw-semibold">{{ $application->application_no }}</a>
                </td>
                <td data-label="আবেদনকারী">
                    <span class="d-block">{{ $application->user->name ?? '—' }}</span>
                    <span class="text-muted fs-12">{{ $application->user->email ?? '' }}</span>
                </td>
                <td data-label="ধরন">{{ $application->membershipType->name ?? '—' }}</td>
                <td data-label="স্ট্যাটাস"><x-admin.status-badge :status="$application->status" /></td>
                <td data-label="জমার তারিখ">{{ bn_date($application->created_at) }}</td>
            </tr>
        @empty
            <x-admin.empty-state colspan="5" icon="{{ $isFiltered ? 'ti-search-off' : 'ti-file-off' }}"
                :title="$isFiltered ? 'এই ফিল্টারে কিছু পাওয়া যায়নি' : 'এখনো কোনো সদস্য আবেদন নেই'"
                message="আবেদন জমা হলে এখানে তালিকা দেখা যাবে।">
                @if ($isFiltered)
                    <a href="{{ route('admin.membership.index') }}" class="btn btn-light btn-sm">ফিল্টার সরান</a>
                @endif
            </x-admin.empty-state>
        @endforelse
    </x-admin.table>
@endsection
