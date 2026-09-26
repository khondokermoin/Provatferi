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
                    <x-admin.form-input name="name_en" label="{{ __('admin.common.name') }} {{ __('admin.bilingual.en_label_suffix') }}" :value="$type->name_en" />
                    <x-admin.form-textarea name="description" label="{{ __('admin.fields.description_requirements') }}" :value="$type->description" :rows="4"
                        help="{{ __('admin.fields.membership_requirements_help') }}" />
                    <x-admin.form-textarea name="description_en" label="{{ __('admin.fields.description_requirements') }} {{ __('admin.bilingual.en_label_suffix') }}" :value="$type->description_en" :rows="4" />
                    <x-admin.form-input name="duration_months" label="{{ __('admin.fields.duration_months') }}" type="number" :value="$type->duration_months"
                        min="1" help="{{ __('admin.fields.lifetime_membership_help') }}" />
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card title="{{ __('admin.common.publication') }}">
                    <x-admin.form-input name="fee" label="{{ __('admin.fields.fee') }}" type="number" :value="$type->fee ?? 0" required min="0" step="0.01"
                        help="{{ __('admin.fields.fee_not_set_help') }}" />
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="is_student" name="is_student" value="1"
                               @checked(old('is_student', $type->is_student ?? false))>
                        <label class="form-check-label" for="is_student">{{ __('admin.fields.for_students') }}</label>
                    </div>
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="is_public_self_apply" name="is_public_self_apply" value="1"
                               @checked(old('is_public_self_apply', $type->is_public_self_apply ?? true))>
                        <label class="form-check-label" for="is_public_self_apply">{{ __('admin.fields.public_self_apply') }}</label>
                        <p class="fs-12 text-muted mb-0 mt-1">
                            {{ __('admin.fields.public_self_apply_help') }}
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
