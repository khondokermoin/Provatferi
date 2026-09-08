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
                <x-admin.card title="মূল তথ্য" subtitle="ইউনিটের পরিচয় ও কাঠামোগত অবস্থান।">
                    <x-admin.form-input
                        name="name"
                        label="ইউনিটের নাম"
                        :value="$unit->name"
                        required
                        help="যেমন: চান্দিনা উপজেলা শাখা" />

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
                        label="বিবরণ"
                        :value="$unit->description"
                        :rows="3" />
                </x-admin.card>

                <x-admin.card title="যোগাযোগ" subtitle="ঐচ্ছিক — জানা থাকলে পূরণ করুন।">
                    <x-admin.form-textarea name="address" label="ঠিকানা" :value="$unit->address" :rows="2" />
                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-input name="phone" label="ফোন" :value="$unit->phone" />
                        </div>
                        <div class="col-md-6">
                            <x-admin.form-input name="email" label="ই-মেইল" type="email" :value="$unit->email" />
                        </div>
                    </div>
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card title="প্রকাশনা">
                    <x-admin.form-select
                        name="status"
                        label="স্ট্যাটাস"
                        :options="$statuses"
                        :value="$unit->status"
                        :placeholder="null"
                        required />

                    <x-admin.form-input
                        name="sort_order"
                        label="ক্রম"
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
                        label="কোড"
                        :value="$unit->code"
                        help="অভ্যন্তরীণ শনাক্তকরণ কোড (ঐচ্ছিক)।" />
                </x-admin.card>

                {{-- Primary action first, cancel as a low-emphasis link. --}}
                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? 'হালনাগাদ করুন' : 'তৈরি করুন' }}
                    </button>
                    <a href="{{ $isEdit ? route('admin.organization.units.show', $unit) : route('admin.organization.units.index') }}"
                       class="btn btn-light">বাতিল</a>
                </div>
            </div>
        </div>
    </form>
@endsection
