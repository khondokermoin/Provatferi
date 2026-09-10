@extends('layouts.admin')

@section('page-actions')
    @can('activities.create')
        <a href="{{ route('admin.activities.types.create') }}" class="btn btn-primary">
            <i class="ti ti-plus me-1" aria-hidden="true"></i>নতুন কার্যক্রমের ধরন
        </a>
    @endcan
@endsection

@section('content')
    <x-admin.table :paginator="$types" caption="কার্যক্রমের ধরনের তালিকা"
        :headers="['ক্রম', 'নাম', 'কার্যক্রম', 'স্ট্যাটাস', ['label' => 'অ্যাকশন', 'align' => 'end']]">

        @forelse ($types as $type)
            <tr>
                <td data-label="ক্রম">{{ $type->sort_order }}</td>
                <td data-label="নাম" class="fw-semibold">{{ $type->name }}</td>
                <td data-label="কার্যক্রম">{{ $type->activities_count }}</td>
                <td data-label="স্ট্যাটাস"><x-admin.status-badge :status="$type->status" /></td>
                <td data-label="অ্যাকশন" class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false"
                                aria-label="{{ $type->name }} — অ্যাকশন মেনু">
                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            @can('activities.update')
                                <a href="{{ route('admin.activities.types.edit', $type) }}" class="dropdown-item">
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>সম্পাদনা
                                </a>
                            @endcan
                            @can('activities.delete')
                                <div class="dropdown-divider"></div>
                                <button type="button" class="dropdown-item text-danger"
                                        data-bs-toggle="modal" data-bs-target="#delete-type-{{ $type->id }}">
                                    <i class="ti ti-trash me-1" aria-hidden="true"></i>মুছে ফেলুন
                                </button>
                            @endcan
                        </div>
                    </div>
                </td>
            </tr>
        @empty
            <x-admin.empty-state colspan="5" icon="ti-tag" title="এখনো কোনো কার্যক্রমের ধরন নেই"
                message="কার্যক্রম যোগ করার আগে অন্তত একটি ধরন তৈরি করুন।">
                @can('activities.create')
                    <a href="{{ route('admin.activities.types.create') }}" class="btn btn-primary btn-sm">
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>নতুন কার্যক্রমের ধরন
                    </a>
                @endcan
            </x-admin.empty-state>
        @endforelse
    </x-admin.table>

    @can('activities.delete')
        @foreach ($types as $type)
            <x-admin.modal :id="'delete-type-'.$type->id" title="ধরন মুছে ফেলবেন?">
                <p class="mb-0">
                    <strong>{{ $type->name }}</strong> মুছে ফেলা হবে।
                    @if ($type->activities_count > 0)
                        <span class="d-block text-danger mt-2">
                            <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                            এই ধরনটি {{ $type->activities_count }}টি কার্যক্রমে ব্যবহৃত — আগে সেগুলোর ধরন পরিবর্তন করতে হবে।
                        </span>
                    @endif
                </p>
                <x-slot:confirm>
                    <form method="POST" action="{{ route('admin.activities.types.destroy', $type) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger">মুছে ফেলুন</button>
                    </form>
                </x-slot:confirm>
            </x-admin.modal>
        @endforeach
    @endcan
@endsection
