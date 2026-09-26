@extends('layouts.admin')

@section('content')
    @php $isEdit = $type->exists; @endphp

    <form method="POST" action="{{ $isEdit ? route('admin.membership.types.update', $type) : route('admin.membership.types.store') }}">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        <div class="row">
            <div class="col-lg-8">
                <x-admin.card title="{{ __('admin.common.description') }}">
                    <x-admin.form-input name="name" label="{{ __('admin.common.name') }}" :value="$type->name" required />
                    <x-admin.form-input name="name_en" label="নাম (English, ঐচ্ছিক)" :value="$type->name_en" />
                    <x-admin.form-textarea name="description" label="বিবরণ / যোগ্যতা" :value="$type->description" :rows="4"
                        help="সদস্যপদের যোগ্যতা বা শর্ত থাকলে এখানে লিখুন।" />
                    <x-admin.form-textarea name="description_en" label="বিবরণ / যোগ্যতা (English, ঐচ্ছিক)" :value="$type->description_en" :rows="4" />
                    <x-admin.form-input name="duration_months" label="মেয়াদ (মাস)" type="number" :value="$type->duration_months"
                        min="1" help="আজীবন সদস্যপদের ক্ষেত্রে খালি রাখুন।" />
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card title="{{ __('admin.common.publication') }}">
                    <x-admin.form-input name="fee" label="{{ __('admin.fields.fee') }}" type="number" :value="$type->fee ?? 0" required min="0" step="0.01"
                        help="ফি এখনো নির্ধারিত না হলে ০ রাখুন — অনুমান করে বসাবেন না।" />
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="is_student" name="is_student" value="1"
                               @checked(old('is_student', $type->is_student ?? false))>
                        <label class="form-check-label" for="is_student">শিক্ষার্থীদের জন্য</label>
                    </div>
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="is_public_self_apply" name="is_public_self_apply" value="1"
                               @checked(old('is_public_self_apply', $type->is_public_self_apply ?? true))>
                        <label class="form-check-label" for="is_public_self_apply">পাবলিক ওয়েবসাইট থেকে সরাসরি আবেদন করা যাবে</label>
                        <p class="fs-12 text-muted mb-0 mt-1">
                            বন্ধ রাখলে (যেমন: সাম্মানিক সদস্যপদ) এই ধরনটি পাবলিক আবেদন ফর্মে দেখানো হবে না।
                        </p>
                    </div>
                    <x-admin.form-select name="status" label="{{ __('admin.common.status') }}" :options="$statuses"
                        :value="$type->status" :placeholder="null" required />
                    <x-admin.form-input name="sort_order" label="{{ __('admin.common.order') }}" type="number" :value="$type->sort_order ?? 0" required min="0" />
                </x-admin.card>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? __('admin.actions.update') : __('admin.actions.create') }}
                    </button>
                    <a href="{{ route('admin.membership.types.index') }}" class="btn btn-light">{{ __('admin.actions.cancel') }}</a>
                </div>
            </div>
        </div>
    </form>
@endsection
