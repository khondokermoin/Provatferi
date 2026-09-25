@extends('layouts.admin')

@section('content')
    @php
        $isEdit = $jobPosting->exists;
        $linkedNotice = $isEdit ? $jobPosting->notice : null;
    @endphp

    {{-- §12 regression: the share-image file input is useless without this —
         without multipart encoding a browser submits only the filename as
         plain text, never the file itself. PHPUnit's UploadedFile::fake()
         builds the test request directly and never exercises this template,
         which is exactly why this was only caught by a real browser submit. --}}
    <form method="POST" action="{{ $isEdit ? route('admin.recruitment.update', $jobPosting) : route('admin.recruitment.store') }}" enctype="multipart/form-data">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        <div class="row">
            <div class="col-lg-8">
                <x-admin.card title="বিবরণ">
                    <x-admin.form-input name="title" label="শিরোনাম" :value="$jobPosting->title" required />
                    <x-admin.form-input name="title_en" label="শিরোনাম (English, ঐচ্ছিক)" :value="$jobPosting->title_en" />
                    <x-admin.form-input name="slug" label="ইউআরএল স্লাগ" :value="$jobPosting->slug"
                        help="{{ $isEdit ? 'পরিবর্তন করলে পুরনো লিংকটি চিরস্থায়ীভাবে নতুন লিংকে রিডাইরেক্ট হবে — আগের ভিজিটর/শেয়ার করা লিংক নষ্ট হবে না।' : 'খালি রাখলে শিরোনাম থেকে স্বয়ংক্রিয়ভাবে তৈরি হবে। শুধু ছোট হাতের ইংরেজি অক্ষর, সংখ্যা ও হাইফেন।' }}" />
                    <x-admin.form-textarea name="summary" label="সংক্ষিপ্ত বিবরণ" :value="$jobPosting->summary" :rows="2" />
                    <x-admin.form-textarea name="summary_en" label="সংক্ষিপ্ত বিবরণ (English, ঐচ্ছিক)" :value="$jobPosting->summary_en" :rows="2" />
                    <x-admin.form-textarea name="description" label="পূর্ণ বিবরণ" :value="$jobPosting->description" :rows="6" required />
                    <x-admin.form-textarea name="description_en" label="পূর্ণ বিবরণ (English, ঐচ্ছিক)" :value="$jobPosting->description_en" :rows="6" />
                    <x-admin.form-textarea name="requirements" label="যোগ্যতা" :value="$jobPosting->requirements" :rows="4" />
                    <x-admin.form-textarea name="requirements_en" label="যোগ্যতা (English, ঐচ্ছিক)" :value="$jobPosting->requirements_en" :rows="4" />

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

                    <div class="form-check mt-3">
                        <input type="checkbox" class="form-check-input" id="accepts_applications" name="accepts_applications" value="1"
                               aria-describedby="accepts_applications-help"
                               @checked(old('accepts_applications', $jobPosting->accepts_applications))>
                        <label class="form-check-label" for="accepts_applications">আবেদন গ্রহণ করা হবে</label>
                    </div>
                    <div class="form-text" id="accepts_applications-help">
                        চালু করলে ওয়েবসাইটে আবেদন ফরম খুলবে এবং যুক্ত নোটিশেও “আবেদন করুন” বোতাম দেখাবে। আবেদনসমূহ ERP-তে জমা হবে।
                    </div>
                </x-admin.card>

                {{-- Application Form Settings: per-posting Required/Optional for the
                     form's non-core fields. field_requirements[<key>] posts as a flat
                     array; RecruitmentController::validated() keeps only known keys. --}}
                <x-admin.card title="আবেদন ফরমের ফিল্ড সেটিংস"
                    subtitle="প্রতিটি ঘর এই বিজ্ঞপ্তির আবেদন ফরমে আবশ্যক না ঐচ্ছিক থাকবে তা ঠিক করুন। নাম, মোবাইল, ই-মেইল ও সম্মতিসমূহ সবসময় আবশ্যক থাকে — এখানে পরিবর্তনযোগ্য নয়।">
                    @php $resolved = $jobPosting->resolvedFieldRequirements(); @endphp
                    <div class="row">
                        @foreach ($configurableFields as $key => $label)
                            <div class="col-md-6">
                                {{-- old() only understands dot notation for nested input, but an HTML
                                     name must use bracket syntax for PHP to parse it into an array —
                                     so the redisplay-safe value is resolved here (dot notation) and
                                     handed in as :value; the component's own old($name,...) call can't
                                     find a bracketed key and falls through to exactly this. --}}
                                <x-admin.form-select :name="'field_requirements['.$key.']'" :label="$label"
                                    :options="$fieldRequirementOptions" :value="old('field_requirements.'.$key, $resolved[$key])" :placeholder="null" />
                            </div>
                        @endforeach
                    </div>
                </x-admin.card>

                {{-- §12: a dedicated Open Graph / social-share image — sized for how
                     link previews render, separate from any in-page content. --}}
                <x-admin.card title="সামাজিক শেয়ার ছবি">
                    @if ($jobPosting->share_image_path)
                        <img src="{{ route('admin.recruitment.files.share', $jobPosting) }}" alt=""
                             class="rounded border mb-2" style="max-width: 240px; max-height: 126px; object-fit: cover;">
                    @endif
                    <x-admin.form-input name="share_image" label="শেয়ার ছবি (ঐচ্ছিক)" type="file" accept="image/jpeg,image/png,image/webp"
                        help="সেরা ফলাফলের জন্য 1200 × 630 পিক্সেল (1.91:1) ছবি ব্যবহার করুন। JPG, PNG বা WEBP, সর্বোচ্চ ৫ MB। ফেসবুক, WhatsApp ও Twitter-এ লিংক শেয়ার করলে এই ছবিটি দেখানো হবে।" />
                    @if ($jobPosting->share_image_path)
                        <div class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" id="remove_share_image" name="remove_share_image" value="1">
                            <label class="form-check-label" for="remove_share_image">
                                বর্তমান শেয়ার ছবি সরিয়ে দিন
                                (<a href="{{ route('admin.recruitment.files.share', $jobPosting) }}" target="_blank" rel="noopener noreferrer">দেখুন</a>)
                            </label>
                        </div>
                    @endif
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
