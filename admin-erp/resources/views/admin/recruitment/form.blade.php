@extends('layouts.admin')

@section('content')
    @php $isEdit = $jobPosting->exists; @endphp

    <form method="POST" action="{{ $isEdit ? route('admin.recruitment.update', $jobPosting) : route('admin.recruitment.store') }}">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        <div class="row">
            <div class="col-lg-8">
                <x-admin.card title="বিবরণ">
                    <x-admin.form-input name="title" label="শিরোনাম" :value="$jobPosting->title" required />
                    <x-admin.form-textarea name="summary" label="সংক্ষিপ্ত বিবরণ" :value="$jobPosting->summary" :rows="2" />
                    <x-admin.form-textarea name="description" label="পূর্ণ বিবরণ" :value="$jobPosting->description" :rows="6" required />
                    <x-admin.form-textarea name="requirements" label="যোগ্যতা" :value="$jobPosting->requirements" :rows="4" />

                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-select name="organization_unit_id" label="সাংগঠনিক ইউনিট" :options="$units"
                                :value="$jobPosting->organization_unit_id" placeholder="— নির্দিষ্ট নয় —" />
                        </div>
                        <div class="col-md-6">
                            <x-admin.form-input name="department" label="বিভাগ" :value="$jobPosting->department" />
                        </div>
                    </div>
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card title="শর্তাবলী">
                    <x-admin.form-select name="employment_type" label="নিয়োগের ধরন"
                        :options="['full_time' => 'পূর্ণকালীন', 'part_time' => 'খণ্ডকালীন', 'volunteer' => 'স্বেচ্ছাসেবী', 'contract' => 'চুক্তিভিত্তিক']"
                        :value="$jobPosting->employment_type" placeholder="— নির্দিষ্ট নয় —" />
                    <x-admin.form-input name="salary_range" label="বেতন সীমা" :value="$jobPosting->salary_range"
                        help="নির্ধারিত না হলে খালি রাখুন।" />
                    <x-admin.form-input name="opening_date" label="আবেদন শুরুর তারিখ" type="date"
                        :value="$jobPosting->opening_date?->format('Y-m-d')" />
                    <x-admin.form-input name="application_deadline" label="আবেদনের শেষ তারিখ" type="date"
                        :value="$jobPosting->application_deadline?->format('Y-m-d')" />
                </x-admin.card>

                <x-admin.card title="প্রকাশনা">
                    <x-admin.form-select name="status" label="স্ট্যাটাস" :options="$statuses"
                        :value="$jobPosting->status" :placeholder="null" required />
                    @if ($jobPosting->published_at)
                        <p class="fs-12 text-muted mb-0">প্রথম প্রকাশ: {{ bn_datetime($jobPosting->published_at) }}</p>
                    @endif
                </x-admin.card>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? 'হালনাগাদ করুন' : 'তৈরি করুন' }}
                    </button>
                    <a href="{{ $isEdit ? route('admin.recruitment.show', $jobPosting) : route('admin.recruitment.index') }}"
                       class="btn btn-light">বাতিল</a>
                </div>
            </div>
        </div>
    </form>
@endsection
