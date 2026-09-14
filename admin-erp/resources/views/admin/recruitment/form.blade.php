@extends('layouts.admin')

@section('content')
    @php
        $isEdit = $jobPosting->exists;
        $linkedNotice = $isEdit ? $jobPosting->notice : null;
    @endphp

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
                    <x-admin.form-select name="employment_type" label="নিয়োগের ধরন" :options="$employmentTypes"
                        :value="$jobPosting->employment_type" placeholder="— নির্দিষ্ট নয় —" />
                    <x-admin.form-input name="salary_range" label="বেতন সীমা" :value="$jobPosting->salary_range"
                        help="নির্ধারিত না হলে খালি রাখুন। স্বেচ্ছাসেবী সুযোগে বেতন সংরক্ষিত বা প্রদর্শিত হয় না।" />
                    <x-admin.form-input name="opening_date" label="আবেদন শুরুর তারিখ" type="date"
                        :value="$jobPosting->opening_date?->format('Y-m-d')" />
                    <x-admin.form-select name="application_mode" label="আবেদনের পদ্ধতি" :options="$applicationModes"
                        :value="$jobPosting->application_mode ?? 'fixed'" :placeholder="null"
                        help="“চলমান” নির্বাচন করলে কোনো শেষ তারিখ সংরক্ষিত হয় না; সাইটে “আবেদন চলমান” দেখাবে।" />
                    <x-admin.form-input name="application_deadline" label="আবেদনের শেষ তারিখ" type="date"
                        :value="$jobPosting->application_deadline?->format('Y-m-d')"
                        help="শুধু নির্দিষ্ট সময়সীমার ক্ষেত্রে প্রযোজ্য।" />
                </x-admin.card>

                <x-admin.card title="প্রকাশনা">
                    <x-admin.form-select name="status" label="স্ট্যাটাস" :options="$statuses"
                        :value="$jobPosting->status" :placeholder="null" required />
                    @if ($jobPosting->published_at)
                        <p class="fs-12 text-muted mb-0">প্রথম প্রকাশ: {{ bn_datetime($jobPosting->published_at) }}</p>
                    @endif

                    @can('notices.create')
                        <div class="border-top pt-3 mt-3">
                            @if ($linkedNotice)
                                <p class="fs-13 mb-0">
                                    <i class="ti ti-speakerphone me-1" aria-hidden="true"></i>নোটিশ বোর্ডে যুক্ত আছে:
                                    <a href="{{ route('admin.notices.show', $linkedNotice) }}" class="fw-semibold">{{ $linkedNotice->title }}</a>
                                </p>
                            @else
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="publish_to_notice_board" name="publish_to_notice_board" value="1"
                                           aria-describedby="publish_to_notice_board-help" @checked(old('publish_to_notice_board'))>
                                    <label class="form-check-label" for="publish_to_notice_board">নোটিশ বোর্ডেও প্রকাশ করুন</label>
                                </div>
                                <div class="form-text" id="publish_to_notice_board-help">
                                    এই বিজ্ঞপ্তির সঙ্গে যুক্ত একটি নোটিশ তৈরি হবে। স্ট্যাটাস “খোলা” হলে এবং নোটিশ প্রকাশের অনুমতি থাকলে সঙ্গে সঙ্গে প্রকাশিত হবে, নইলে খসড়া হিসেবে থাকবে।
                                </div>
                            @endif
                        </div>
                    @endcan
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
