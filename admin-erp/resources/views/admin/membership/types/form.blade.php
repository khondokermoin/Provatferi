@extends('layouts.admin')

@section('content')
    @php $isEdit = $type->exists; @endphp

    <form method="POST" action="{{ $isEdit ? route('admin.membership.types.update', $type) : route('admin.membership.types.store') }}">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        <div class="row">
            <div class="col-lg-8">
                <x-admin.card title="বিবরণ">
                    <x-admin.form-input name="name" label="নাম" :value="$type->name" required />
                    <x-admin.form-textarea name="description" label="বিবরণ / যোগ্যতা" :value="$type->description" :rows="4"
                        help="সদস্যপদের যোগ্যতা বা শর্ত থাকলে এখানে লিখুন।" />
                    <x-admin.form-input name="duration_months" label="মেয়াদ (মাস)" type="number" :value="$type->duration_months"
                        min="1" help="আজীবন সদস্যপদের ক্ষেত্রে খালি রাখুন।" />
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card title="প্রকাশনা">
                    <x-admin.form-input name="fee" label="ফি" type="number" :value="$type->fee ?? 0" required min="0" step="0.01"
                        help="ফি এখনো নির্ধারিত না হলে ০ রাখুন — অনুমান করে বসাবেন না।" />
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="is_student" name="is_student" value="1"
                               @checked(old('is_student', $type->is_student ?? false))>
                        <label class="form-check-label" for="is_student">শিক্ষার্থীদের জন্য</label>
                    </div>
                    <x-admin.form-select name="status" label="স্ট্যাটাস" :options="$statuses"
                        :value="$type->status" :placeholder="null" required />
                    <x-admin.form-input name="sort_order" label="ক্রম" type="number" :value="$type->sort_order ?? 0" required min="0" />
                </x-admin.card>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? 'হালনাগাদ করুন' : 'তৈরি করুন' }}
                    </button>
                    <a href="{{ route('admin.membership.types.index') }}" class="btn btn-light">বাতিল</a>
                </div>
            </div>
        </div>
    </form>
@endsection
