@extends('layouts.admin')

@section('content')
    @php $isEdit = $committee->exists; @endphp

    <form method="POST" action="{{ $isEdit ? route('admin.committees.update', $committee) : route('admin.committees.store') }}">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        <div class="row">
            <div class="col-lg-8">
                <x-admin.card title="কমিটির তথ্য">
                    <x-admin.form-input name="name" label="কমিটির নাম" :value="$committee->name" required />
                    <x-admin.form-input name="name_en" label="কমিটির নাম (English, ঐচ্ছিক)" :value="$committee->name_en" />

                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-select name="organization_unit_id" label="সাংগঠনিক ইউনিট"
                                :options="$units" :value="$committee->organization_unit_id" required />
                        </div>
                        <div class="col-md-6">
                            <x-admin.form-select name="committee_type" label="ধরন"
                                :options="$types" :value="$committee->committee_type" />
                        </div>
                    </div>

                    <x-admin.form-textarea name="description" label="নোট / বিবরণ"
                        :value="$committee->description" :rows="4" />
                    <x-admin.form-textarea name="description_en" label="নোট / বিবরণ (English, ঐচ্ছিক)"
                        :value="$committee->description_en" :rows="4" />
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card title="মেয়াদ ও স্ট্যাটাস">
                    <x-admin.form-input name="term_start" label="মেয়াদ শুরু" type="date"
                        :value="$committee->term_start?->format('Y-m-d')" />
                    <x-admin.form-input name="term_end" label="মেয়াদ শেষ" type="date"
                        :value="$committee->term_end?->format('Y-m-d')"
                        help="খালি রাখলে চলমান ধরা হবে।" />

                    @if ($isEdit)
                        <div class="mb-3">
                            <label class="form-label fs-13">বর্তমান স্ট্যাটাস</label>
                            <div><x-admin.status-badge :status="$committee->status" /></div>
                            <p class="fs-12 text-muted mb-0 mt-1">স্ট্যাটাস পরিবর্তন করতে কমিটির পাতায় যান।</p>
                        </div>
                    @endif
                </x-admin.card>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? 'হালনাগাদ করুন' : 'তৈরি করুন' }}
                    </button>
                    <a href="{{ $isEdit ? route('admin.committees.show', $committee) : route('admin.committees.index') }}"
                       class="btn btn-light">বাতিল</a>
                </div>
            </div>
        </div>
    </form>
@endsection
