@extends('layouts.admin')

@section('content')
    @php $isEdit = $unit->exists; @endphp

    <form method="POST"
          action="{{ $isEdit ? route('admin.organization.units.update', $unit) : route('admin.organization.units.store') }}">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="row">
            <div class="col-lg-8">
                <x-admin.card title="{{ __('admin.fields.main_info') }}" subtitle="ইউনিটের পরিচয় ও কাঠামোগত অবস্থান।">
                    <x-admin.form-input
                        name="name"
                        label="ইউনিটের নাম"
                        :value="$unit->name"
                        required
                        help="যেমন: চান্দিনা উপজেলা শাখা" />

                    <x-admin.form-input
                        name="name_en"
                        label="ইউনিটের নাম (English, ঐচ্ছিক)"
                        :value="$unit->name_en" />

                    {{-- Two columns only where the fields are genuinely short;
                         Bootstrap stacks them below 768px automatically. --}}
                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-select
                                name="unit_type"
                                label="ইউনিটের ধরন"
                                :options="$unitTypes"
                                :value="$unit->unit_type"
                                required />
                        </div>
                        <div class="col-md-6">
                            <x-admin.form-select
                                name="parent_id"
                                label="প্যারেন্ট ইউনিট"
                                :options="$parentOptions"
                                :value="$unit->parent_id"
                                placeholder="— শীর্ষ পর্যায় (কোনো প্যারেন্ট নেই) —"
                                help="নিজের অধীনস্থ ইউনিট প্যারেন্ট হিসেবে বাছা যাবে না।" />
                        </div>
                    </div>

                    <x-admin.form-textarea
                        name="description"
                        label="{{ __('admin.common.description') }}"
                        :value="$unit->description"
                        :rows="3" />
                </x-admin.card>

                <x-admin.card title="{{ __('admin.fields.contact') }}" subtitle="ঐচ্ছিক — জানা থাকলে পূরণ করুন।">
                    <x-admin.form-textarea name="address" label="{{ __('admin.common.address') }}" :value="$unit->address" :rows="2" />
                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-input name="phone" label="{{ __('admin.fields.phone_short') }}" :value="$unit->phone" />
                        </div>
                        <div class="col-md-6">
                            <x-admin.form-input name="email" label="{{ __('admin.common.email') }}" type="email" :value="$unit->email" />
                        </div>
                    </div>
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card title="{{ __('admin.common.publication') }}">
                    <x-admin.form-select
                        name="status"
                        label="{{ __('admin.common.status') }}"
                        :options="$statuses"
                        :value="$unit->status"
                        :placeholder="null"
                        required />

                    <x-admin.form-input
                        name="sort_order"
                        label="{{ __('admin.common.order') }}"
                        type="number"
                        :value="$unit->sort_order ?? 0"
                        required
                        min="0"
                        help="ছোট সংখ্যা আগে দেখাবে।" />

                    <x-admin.form-input
                        name="established_date"
                        label="প্রতিষ্ঠার তারিখ"
                        type="date"
                        :value="$unit->established_date?->format('Y-m-d') ?? $unit->established_date" />

                    <x-admin.form-input
                        name="code"
                        label="{{ __('admin.fields.code') }}"
                        :value="$unit->code"
                        help="অভ্যন্তরীণ শনাক্তকরণ কোড (ঐচ্ছিক)।" />
                </x-admin.card>

                {{-- Primary action first, cancel as a low-emphasis link. --}}
                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? __('admin.actions.update') : __('admin.actions.create') }}
                    </button>
                    <a href="{{ $isEdit ? route('admin.organization.units.show', $unit) : route('admin.organization.units.index') }}"
                       class="btn btn-light">{{ __('admin.actions.cancel') }}</a>
                </div>
            </div>
        </div>
    </form>
@endsection
