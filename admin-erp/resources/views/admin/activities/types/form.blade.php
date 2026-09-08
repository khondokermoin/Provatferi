@extends('layouts.admin')

@section('content')
    @php $isEdit = $type->exists; @endphp

    <form method="POST" action="{{ $isEdit ? route('admin.activities.types.update', $type) : route('admin.activities.types.store') }}">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        <div class="row">
            <div class="col-lg-8">
                <x-admin.card title="বিবরণ">
                    <x-admin.form-input name="name" label="নাম" :value="$type->name" required />
                    <x-admin.form-textarea name="description" label="বিবরণ" :value="$type->description" :rows="3" />
                    <x-admin.form-input name="icon" label="আইকন (ঐচ্ছিক)" :value="$type->icon"
                        help="Tabler icon নাম, যেমন: ti-book" />
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card title="প্রকাশনা">
                    <x-admin.form-select name="status" label="স্ট্যাটাস" :options="$statuses"
                        :value="$type->status" :placeholder="null" required />
                    <x-admin.form-input name="sort_order" label="ক্রম" type="number" :value="$type->sort_order ?? 0" required min="0" />
                </x-admin.card>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? 'হালনাগাদ করুন' : 'তৈরি করুন' }}
                    </button>
                    <a href="{{ route('admin.activities.types.index') }}" class="btn btn-light">বাতিল</a>
                </div>
            </div>
        </div>
    </form>
@endsection
