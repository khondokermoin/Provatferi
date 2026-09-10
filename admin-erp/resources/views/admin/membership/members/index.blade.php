@extends('layouts.admin')

@section('content')
    @php $isFiltered = collect($filters)->filter(fn ($v) => $v !== '')->isNotEmpty(); @endphp

    <x-admin.table :paginator="$members" caption="সদস্যদের তালিকা"
        :headers="['সদস্য নং', 'নাম', 'ধরন', 'থেকে', 'স্ট্যাটাস']">

        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.membership.members.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-5">
                    <label for="f-search" class="form-label fs-13 mb-1">খুঁজুন</label>
                    <input type="search" id="f-search" name="search" value="{{ $filters['search'] }}" class="form-control" placeholder="মেম্বার নং, নাম বা ই-মেইল">
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
                <div class="col-6 col-md-2">
                    <label for="f-type" class="form-label fs-13 mb-1">ধরন</label>
                    <select id="f-type" name="type" class="form-select">
                        <option value="">সব</option>
                        @foreach ($types as $id => $name)
                            <option value="{{ $id }}" @selected($filters['type'] == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-light border w-100">
                        <i class="ti ti-filter me-1" aria-hidden="true"></i>ফিল্টার
                    </button>
                    @if ($isFiltered)
                        <a href="{{ route('admin.membership.members.index') }}" class="btn btn-light" aria-label="ফিল্টার সরান">
                            <i class="ti ti-x" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </form>
        </x-slot:toolbar>

        @forelse ($members as $member)
            <tr>
                <td data-label="সদস্য নং">
                    <a href="{{ route('admin.membership.members.show', $member) }}" class="fw-semibold">{{ $member->member_code }}</a>
                </td>
                <td data-label="নাম">{{ $member->user->name ?? '—' }}</td>
                <td data-label="ধরন">{{ $member->membershipType->name ?? '—' }}</td>
                <td data-label="থেকে">{{ $member->start_date ? bn_date($member->start_date) : '—' }}</td>
                <td data-label="স্ট্যাটাস"><x-admin.status-badge :status="$member->status" /></td>
            </tr>
        @empty
            <x-admin.empty-state colspan="5" icon="{{ $isFiltered ? 'ti-search-off' : 'ti-users' }}"
                :title="$isFiltered ? 'এই ফিল্টারে কিছু পাওয়া যায়নি' : 'এখনো কোনো সদস্য নেই'"
                message="আবেদন অনুমোদিত হলে সদস্য এখানে তালিকাভুক্ত হবে।">
                @if ($isFiltered)
                    <a href="{{ route('admin.membership.members.index') }}" class="btn btn-light btn-sm">ফিল্টার সরান</a>
                @endif
            </x-admin.empty-state>
        @endforelse
    </x-admin.table>
@endsection
