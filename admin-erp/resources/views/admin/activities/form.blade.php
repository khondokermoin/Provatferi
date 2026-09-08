@extends('layouts.admin')

@section('content')
    @php $isEdit = $activity->exists; @endphp

    <form method="POST" action="{{ $isEdit ? route('admin.activities.update', $activity) : route('admin.activities.store') }}">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        @if ($errors->has('activity_type_id') || $errors->has('summary') || $errors->has('start_datetime'))
            <div class="alert alert-warning fs-13" role="alert">
                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>
                প্রকাশ (Published) করতে হলে ধরন, সংক্ষিপ্ত বিবরণ ও শুরুর তারিখ আবশ্যক।
            </div>
        @endif

        <div class="row">
            <div class="col-lg-8">
                <x-admin.card title="মূল তথ্য">
                    <x-admin.form-input name="title" label="শিরোনাম" :value="$activity->title" required />
                    <x-admin.form-textarea name="summary" label="সংক্ষিপ্ত বিবরণ" :value="$activity->summary" :rows="2"
                        help="তালিকা ও কার্ডে দেখানো হবে।" />
                    <x-admin.form-textarea name="description" label="বিস্তারিত বিবরণ" :value="$activity->description" :rows="4" />

                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-select name="activity_type_id" label="ধরন" :options="$types"
                                :value="$activity->activity_type_id" required />
                        </div>
                        <div class="col-md-6">
                            <x-admin.form-select name="organization_unit_id" label="সাংগঠনিক ইউনিট" :options="$units"
                                :value="$activity->organization_unit_id" placeholder="— নির্দিষ্ট নয় —" />
                        </div>
                    </div>
                </x-admin.card>

                <x-admin.card title="সময় ও স্থান">
                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-input name="start_datetime" label="শুরুর তারিখ ও সময়" type="datetime-local"
                                :value="$activity->start_datetime?->format('Y-m-d\TH:i')" />
                        </div>
                        <div class="col-md-6">
                            <x-admin.form-input name="end_datetime" label="শেষের তারিখ ও সময়" type="datetime-local"
                                :value="$activity->end_datetime?->format('Y-m-d\TH:i')" />
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-input name="venue" label="ভেন্যু" :value="$activity->venue" />
                        </div>
                        <div class="col-md-6">
                            <x-admin.form-input name="participant_count" label="অংশগ্রহণকারীর সংখ্যা" type="number"
                                :value="$activity->participant_count ?? 0" required min="0"
                                help="প্রকৃত সংখ্যা — অনুমান বা কাল্পনিক সংখ্যা নয়।" />
                        </div>
                    </div>
                    <x-admin.form-textarea name="address" label="ঠিকানা" :value="$activity->address" :rows="2" />
                </x-admin.card>

                <x-admin.card title="বিবরণ ও ফলাফল" subtitle="বাস্তব তথ্য না থাকলে খালি রাখুন।">
                    <x-admin.form-textarea name="objective" label="উদ্দেশ্য" :value="$activity->objective" :rows="2" />
                    <x-admin.form-textarea name="what_happened" label="কী ঘটেছে" :value="$activity->what_happened" :rows="4" />
                    <x-admin.form-textarea name="outcomes" label="ফলাফল ও প্রভাব" :value="$activity->outcomes" :rows="3" />
                    <x-admin.form-input name="facebook_post_url" label="ফেসবুক পোস্ট লিঙ্ক" type="url" :value="$activity->facebook_post_url" />
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card title="প্রকাশনা">
                    <x-admin.form-select name="status" label="স্ট্যাটাস" :options="$statuses"
                        :value="$activity->status" :placeholder="null" required
                        help="Published করতে ধরন, সংক্ষিপ্ত বিবরণ ও শুরুর তারিখ লাগবে।" />

                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="featured" name="featured" value="1"
                               @checked(old('featured', $activity->featured ?? false))>
                        <label class="form-check-label" for="featured">ফিচার্ড কার্যক্রম হিসেবে দেখান</label>
                    </div>

                    @if ($activity->published_at)
                        <p class="fs-12 text-muted mt-3 mb-0">প্রথম প্রকাশ: {{ $activity->published_at->format('d M Y, H:i') }}</p>
                    @endif
                </x-admin.card>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? 'হালনাগাদ করুন' : 'তৈরি করুন' }}
                    </button>
                    <a href="{{ $isEdit ? route('admin.activities.show', $activity) : route('admin.activities.index') }}"
                       class="btn btn-light">বাতিল</a>
                </div>
            </div>
        </div>
    </form>
@endsection
