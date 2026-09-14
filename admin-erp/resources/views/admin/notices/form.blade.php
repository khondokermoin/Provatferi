@extends('layouts.admin')

@section('content')
    @php
        $isEdit = $notice->exists;
        $slugLocked = $isEdit && $notice->wasEverPublic();
    @endphp

    <form method="POST" enctype="multipart/form-data"
          action="{{ $isEdit ? route('admin.notices.update', $notice) : route('admin.notices.store') }}">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        <div class="row">
            <div class="col-lg-8">
                <x-admin.card title="নোটিশের বিষয়বস্তু">
                    <x-admin.form-input name="title" label="বিষয়" :value="$notice->title" required maxlength="255" />

                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-select name="notice_type" label="নোটিশের ধরন" :options="$types"
                                :value="$notice->notice_type" :placeholder="null" required />
                        </div>
                        <div class="col-md-6">
                            @if ($slugLocked)
                                <x-admin.form-input name="slug" label="URL স্লাগ" :value="$notice->slug" readonly
                                    help="প্রকাশিত নোটিশের URL স্থায়ী — আগে শেয়ার করা লিংক যাতে না ভাঙে।" />
                            @else
                                <x-admin.form-input name="slug" label="URL স্লাগ (ঐচ্ছিক)" :value="$notice->slug" maxlength="120"
                                    placeholder="যেমন: volunteer-team-call"
                                    help="ইংরেজি ছোট হাতের অক্ষর, সংখ্যা ও হাইফেন। খালি রাখলে বিষয় থেকে তৈরি হবে।" />
                            @endif
                        </div>
                    </div>

                    <x-admin.form-textarea name="summary" label="সংক্ষিপ্ত বিবরণ" :value="$notice->summary" :rows="3" maxlength="500"
                        help="নোটিশ বোর্ডের তালিকায় ও লিংক শেয়ারের প্রিভিউতে দেখায়। সর্বোচ্চ ৫০০ অক্ষর।" />
                    <x-admin.form-textarea name="body" label="পূর্ণ বিবরণ" :value="$notice->body" :rows="18" required
                        help="সাধারণ লেখা হিসেবে সংরক্ষিত হয়। খালি লাইন দিয়ে অনুচ্ছেদ আলাদা করুন; “• ” দিয়ে শুরু হওয়া লাইন সাইটে তালিকা হিসেবে দেখাবে। লিংক নিজে থেকেই ক্লিকযোগ্য হবে।" />
                </x-admin.card>

                <x-admin.card title="ছবি ও সংযুক্তি">
                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-input name="cover_image" label="ছবি (ঐচ্ছিক)" type="file" accept="image/jpeg,image/png,image/webp"
                                help="JPG, PNG বা WEBP, সর্বোচ্চ ৫ MB।" />
                            @if ($notice->cover_image_path)
                                <div class="form-check mb-3">
                                    <input type="checkbox" class="form-check-input" id="remove_cover_image" name="remove_cover_image" value="1">
                                    <label class="form-check-label" for="remove_cover_image">
                                        বর্তমান ছবি সরিয়ে দিন
                                        (<a href="{{ route('admin.notices.file', [$notice, 'cover']) }}" target="_blank" rel="noopener noreferrer">দেখুন</a>)
                                    </label>
                                </div>
                            @endif
                        </div>
                        <div class="col-md-6">
                            <x-admin.form-input name="attachment" label="সংযুক্তি / PDF (ঐচ্ছিক)" type="file" accept="application/pdf"
                                help="শুধু PDF, সর্বোচ্চ ১০ MB। নোটিশ প্রকাশিত থাকলেই কেবল সাইট থেকে ডাউনলোড করা যাবে।" />
                            @if ($notice->attachment_path)
                                <div class="form-check mb-3">
                                    <input type="checkbox" class="form-check-input" id="remove_attachment" name="remove_attachment" value="1">
                                    <label class="form-check-label" for="remove_attachment">
                                        বর্তমান সংযুক্তি সরিয়ে দিন
                                        (<a href="{{ route('admin.notices.file', [$notice, 'attachment']) }}">ডাউনলোড</a>)
                                    </label>
                                </div>
                            @endif
                        </div>
                    </div>
                </x-admin.card>

                <x-admin.card title="অ্যাকশন বাটন (ঐচ্ছিক)">
                    <div class="row">
                        <div class="col-md-8">
                            <x-admin.form-input name="action_url" label="অ্যাকশন লিংক" type="url" :value="$notice->action_url" maxlength="500"
                                placeholder="https://" help="যেমন আবেদন ফর্ম বা যোগদানের লিংক। সাইটে বাটন হিসেবে নতুন ট্যাবে খুলবে।" />
                        </div>
                        <div class="col-md-4">
                            <x-admin.form-input name="action_label" label="বাটনের লেখা" :value="$notice->action_label" maxlength="100"
                                placeholder="যেমন: আবেদন করুন" />
                        </div>
                    </div>
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card title="প্রকাশনা">
                    <x-admin.form-select name="status" label="স্ট্যাটাস" :options="$statuses"
                        :value="$isEdit ? $notice->effectiveStatus() : $notice->status" :placeholder="null" required
                        help="“প্রকাশিত” ও “নির্ধারিত” করতে প্রকাশের অনুমতি এবং “সংরক্ষিত” (আর্কাইভ) করতে আর্কাইভের অনুমতি প্রয়োজন।" />
                    <x-admin.form-input name="published_at" label="প্রকাশের তারিখ ও সময়" type="datetime-local"
                        :value="\App\Models\Notice::toLocalInput($notice->published_at)"
                        help="বাংলাদেশ সময়। “প্রকাশিত” হলে খালি রাখলে এখনকার সময় ধরা হবে; “নির্ধারিত” হলে ভবিষ্যতের সময় দিন।" />
                    <x-admin.form-input name="expires_at" label="মেয়াদ শেষের তারিখ (ঐচ্ছিক)" type="datetime-local"
                        :value="\App\Models\Notice::toLocalInput($notice->expires_at)"
                        help="মেয়াদ শেষ হলেও নোটিশ মুছে যাবে না — সাইটে থেকে যাবে, “মেয়াদোত্তীর্ণ” চিহ্নিত হবে এবং পিন সরে যাবে।" />
                    <div class="form-check mb-0">
                        <input type="checkbox" class="form-check-input" id="is_pinned" name="is_pinned" value="1"
                               @checked(old('is_pinned', $notice->is_pinned))>
                        <label class="form-check-label" for="is_pinned">গুরুত্বপূর্ণ হিসেবে তালিকার শীর্ষে রাখুন</label>
                    </div>
                </x-admin.card>

                <x-admin.card title="সাংগঠনিক তথ্য">
                    <x-admin.form-select name="organization_unit_id" label="সাংগঠনিক ইউনিট" :options="$units"
                        :value="$notice->organization_unit_id" placeholder="— নির্দিষ্ট নয় —" />
                </x-admin.card>

                @if ($isEdit && $notice->jobPosting)
                    <x-admin.card title="যুক্ত নিয়োগ বিজ্ঞপ্তি">
                        <p class="fs-13 mb-2">
                            <a href="{{ route('admin.recruitment.show', $notice->jobPosting) }}" class="fw-semibold">{{ $notice->jobPosting->title }}</a>
                        </p>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="syncs_from_job_posting" name="syncs_from_job_posting" value="1"
                                   aria-describedby="syncs-help" @checked(old('syncs_from_job_posting', $notice->syncs_from_job_posting))>
                            <label class="form-check-label" for="syncs_from_job_posting">নিয়োগ বিজ্ঞপ্তি হালনাগাদ হলে শিরোনাম ও বিবরণও হালনাগাদ হবে</label>
                        </div>
                        <div class="form-text" id="syncs-help">
                            এখানে বিষয়, সংক্ষিপ্ত বা পূর্ণ বিবরণ হাতে বদলালে স্বয়ংক্রিয় হালনাগাদ নিজে থেকেই বন্ধ হবে, যাতে আপনার লেখা পরে মুছে না যায়।
                            আবার চালু করলে নিয়োগ বিজ্ঞপ্তির লেখা দিয়ে প্রতিস্থাপিত হবে। আবেদনের শর্তাবলী (ধরন, সময়সীমা, পারিশ্রমিক) সবসময় নিয়োগ বিজ্ঞপ্তি থেকেই দেখানো হয়।
                        </div>
                    </x-admin.card>
                @endif

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? 'হালনাগাদ করুন' : 'সংরক্ষণ করুন' }}
                    </button>
                    <a href="{{ $isEdit ? route('admin.notices.show', $notice) : route('admin.notices.index') }}" class="btn btn-light">বাতিল</a>
                </div>
            </div>
        </div>
    </form>
@endsection
