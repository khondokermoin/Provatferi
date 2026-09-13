@extends('layouts.admin')

@section('page-actions')
    <a href="{{ route('admin.committees.show', $committee) }}" class="btn btn-light">
        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>ফিরে যান
    </a>
@endsection

@section('content')
    <x-admin.table :paginator="$submissions" caption="আবেদনের তালিকা"
        :headers="['নাম', 'পদ', 'জমার তারিখ', 'স্ট্যাটাস']">

        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.committees.submissions.index', $committee) }}" class="row g-2 align-items-end">
                <div class="col-8 col-md-4">
                    <label for="f-status" class="form-label fs-13 mb-1">স্ট্যাটাস</label>
                    <select id="f-status" name="status" class="form-select">
                        <option value="">সব</option>
                        @foreach ($statuses as $v => $l)
                            <option value="{{ $v }}" @selected($currentStatus === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-4 col-md-2">
                    <button type="submit" class="btn btn-light border w-100">
                        <i class="ti ti-filter me-1" aria-hidden="true"></i>ফিল্টার
                    </button>
                </div>
            </form>
        </x-slot:toolbar>

        @forelse ($submissions as $submission)
            <tr>
                <td data-label="নাম">
                    <a href="{{ route('admin.committees.submissions.show', [$committee, $submission]) }}" class="fw-semibold">
                        {{ $submission->full_name }}
                    </a>
                </td>
                <td data-label="পদ">{{ $submission->position?->name ?? '—' }}</td>
                <td data-label="জমার তারিখ">{{ $submission->submitted_at ? bn_datetime($submission->submitted_at) : '—' }}</td>
                <td data-label="স্ট্যাটাস"><x-admin.status-badge :status="$submission->status" /></td>
            </tr>
        @empty
            <x-admin.empty-state colspan="4" icon="ti-clipboard-off"
                title="{{ $currentStatus !== '' ? 'এই ফিল্টারে কিছু পাওয়া যায়নি' : 'এখনো কোনো আবেদন নেই' }}"
                message="নিবন্ধন লিংকের মাধ্যমে আবেদন জমা পড়লে এখানে দেখা যাবে।" />
        @endforelse
    </x-admin.table>
@endsection
