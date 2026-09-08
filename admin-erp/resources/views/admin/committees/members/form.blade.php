@extends('layouts.admin')

@section('content')
    @php $isEdit = $member->exists; @endphp

    <form method="POST" action="{{ $isEdit
            ? route('admin.committees.members.update', [$committee, $member])
            : route('admin.committees.members.store', $committee) }}">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        <div class="row">
            <div class="col-lg-8">
                <x-admin.card :title="$committee->name" subtitle="কমিটির সদস্য নিয়োগ">
                    <x-admin.form-select name="user_id" label="ব্যক্তি" :options="$users"
                        :value="$member->user_id" required
                        help="একজন ব্যক্তি একই কমিটিতে একবারই থাকতে পারবেন।" />

                    <x-admin.form-select name="position_id" label="পদ / পদবি" :options="$positions"
                        :value="$member->position_id" required
                        help="সক্রিয় পদগুলোই এখানে দেখানো হয়।" />

                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-input name="start_date" label="শুরুর তারিখ" type="date"
                                :value="$member->start_date?->format('Y-m-d')" />
                        </div>
                        <div class="col-md-6">
                            <x-admin.form-input name="end_date" label="শেষ তারিখ" type="date"
                                :value="$member->end_date?->format('Y-m-d')" help="খালি রাখলে চলমান।" />
                        </div>
                    </div>
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card title="প্রদর্শন">
                    <x-admin.form-input name="serial_no" label="ক্রম" type="number"
                        :value="$member->serial_no" min="0" help="তালিকায় ছোট সংখ্যা আগে দেখাবে।" />
                    <x-admin.form-select name="status" label="স্ট্যাটাস" :options="$statuses"
                        :value="$member->status" :placeholder="null" required />
                </x-admin.card>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? 'হালনাগাদ করুন' : 'যোগ করুন' }}
                    </button>
                    <a href="{{ route('admin.committees.show', $committee) }}" class="btn btn-light">বাতিল</a>
                </div>
            </div>
        </div>
    </form>
@endsection
