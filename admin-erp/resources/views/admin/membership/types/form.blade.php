@extends('layouts.admin')

@section('content')
    @php $isEdit = $type->exists; @endphp

    <form method="POST" action="{{ $isEdit ? route('admin.membership.types.update', $type) : route('admin.membership.types.store') }}">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        <div class="row">
            <div class="col-lg-8">
                <x-admin.card title="{{ __('admin.common.description') }}">
                    <x-admin.bilingual-field name="name" label="{{ __('admin.common.name') }}"
                        :bn-value="$type->name" :en-value="$type->name_en" required />
                    <x-admin.bilingual-field as="textarea" name="description" label="{{ __('admin.fields.description_requirements') }}"
                        :bn-value="$type->description" :en-value="$type->description_en" :rows="4"
                        help="{{ __('admin.fields.membership_requirements_help') }}" />
                    <x-admin.form-input name="code" label="{{ __('admin.fee_policy.code') }}" :value="$type->code"
                        :required="! $isEdit" maxlength="10" autocomplete="off" style="text-transform: uppercase"
                        :readonly="$isEdit && $type->code !== null"
                        help="{{ $isEdit && $type->code !== null ? __('admin.fee_policy.code_locked_help') : __('admin.fee_policy.code_help') }}" />
                    <x-admin.form-input name="duration_months" label="{{ __('admin.fields.duration_months') }}" type="number" :value="$type->duration_months"
                        min="1" help="{{ __('admin.fields.lifetime_membership_help') }}" />
                </x-admin.card>

                @unless ($isEdit)
                    {{-- A type is created together with its first fee policy, so its price is never unknown. --}}
                    <x-admin.card title="{{ __('admin.fee_policy.initial_policy') }}" :subtitle="__('admin.fee_policy.initial_policy_help')" class="mt-3">
                        <div class="row">
                            <div class="col-md-6">
                                <x-admin.form-input name="registration_fee" label="{{ __('admin.fee_policy.registration_fee') }}" type="number"
                                    :value="'0'" required min="0" step="0.01" max="99999999.99" inputmode="decimal" />
                            </div>
                            <div class="col-md-6">
                                <x-admin.form-input name="monthly_contribution" label="{{ __('admin.fee_policy.monthly_contribution') }}" type="number"
                                    :value="'0'" required min="0" step="0.01" max="99999999.99" inputmode="decimal" />
                            </div>
                        </div>
                        <x-admin.form-input name="effective_from" label="{{ __('admin.fee_policy.effective_from') }}" type="date"
                            :value="$today" required min="{{ $today }}" help="{{ __('admin.fee_policy.effective_from_help') }}" />
                        <x-admin.form-textarea name="fee_note" label="{{ __('admin.fee_policy.reason') }} ({{ __('admin.common.optional') }})" :rows="2" />
                    </x-admin.card>
                @endunless
            </div>

            <div class="col-lg-4">
                <x-admin.card title="{{ __('admin.common.publication') }}">
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="is_student" name="is_student" value="1"
                               @checked(old('is_student', $type->is_student ?? false))>
                        <label class="form-check-label" for="is_student">{{ __('admin.fields.for_students') }}</label>
                    </div>
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="is_public_visible" name="is_public_visible" value="1"
                               @checked(old('is_public_visible', $type->is_public_visible ?? true))>
                        <label class="form-check-label" for="is_public_visible">{{ __('admin.fee_policy.public_visible') }}</label>
                        <p class="fs-12 text-muted mb-0 mt-1">{{ __('admin.fee_policy.public_visible_help') }}</p>
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

                @if ($isEdit)
                    {{-- Fees are not edited here: they are dated versions with a history, managed on the type's own page. --}}
                    <x-admin.card title="{{ __('admin.fee_policy.fee_policy') }}" class="mt-3">
                        @if ($current)
                            <dl class="row mb-2">
                                <dt class="col-7 fs-13 text-muted">{{ __('admin.fee_policy.registration_fee') }}</dt>
                                <dd class="col-5 text-end fw-semibold mb-1">{{ bn_money($current->registration_fee) }}</dd>
                                <dt class="col-7 fs-13 text-muted">{{ __('admin.fee_policy.monthly_contribution') }}</dt>
                                <dd class="col-5 text-end fw-semibold mb-1">{{ bn_money($current->monthly_contribution) }}</dd>
                            </dl>
                            <p class="fs-12 text-muted">{{ __('admin.fee_policy.in_force_since_date', ['date' => bn_date($current->fromDate())]) }}</p>
                        @else
                            <p class="text-warning-emphasis fs-13"><i class="ti ti-alert-triangle" aria-hidden="true"></i> {{ __('admin.fee_policy.no_policy_in_force') }}</p>
                        @endif
                        <a href="{{ route('admin.membership.types.show', $type) }}" class="btn btn-sm btn-outline-primary">
                            <i class="ti ti-cash me-1" aria-hidden="true"></i>{{ __('admin.fee_policy.manage_fees') }}
                        </a>
                    </x-admin.card>
                @endif

                <div class="d-flex flex-wrap gap-2 my-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? __('admin.actions.update') : __('admin.actions.create') }}
                    </button>
                    <a href="{{ $isEdit ? route('admin.membership.types.show', $type) : route('admin.membership.types.index') }}" class="btn btn-light">{{ __('admin.actions.cancel') }}</a>
                </div>
            </div>
        </div>
    </form>
@endsection
