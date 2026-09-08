@extends('layouts.admin')

@section('content')
    <form method="POST" action="{{ route('admin.content.about.update') }}">
        @csrf
        @method('PUT')

        <div class="row">
            <div class="col-lg-7">
                <x-admin.card title="মূল তথ্য">
                    <x-admin.form-textarea name="introduction" label="সংক্ষিপ্ত ভূমিকা" :value="$about->introduction" :rows="2"
                        help="পাতার একদম শুরুতে দেখানো এক-দুই লাইনের পরিচিতি।" />
                    <x-admin.form-textarea name="description" label="প্রতিষ্ঠানের বিবরণ" :value="$about->description" :rows="4" />
                    <x-admin.form-textarea name="registration_status" label="নিবন্ধন অবস্থা" :value="$about->registration_status" :rows="1"
                        help="যেমন: প্রতিষ্ঠাকাল ও নিবন্ধন প্রক্রিয়ার বর্তমান অবস্থা।" />
                </x-admin.card>

                <x-admin.card title="পটভূমি" subtitle="বাস্তব ও অনুমোদিত তথ্য না থাকলে খালি রাখুন — কিছু আরোপিত করবেন না।">
                    <x-admin.form-textarea name="history" label="ইতিহাস / প্রেক্ষাপট" :value="$about->history" :rows="5" />
                    <x-admin.form-textarea name="why_exists" label="কেন প্রভাতফেরী" :value="$about->why_exists" :rows="4" />
                    <x-admin.form-textarea name="identity_explanation" label="নাম/পরিচয়ের ব্যাখ্যা" :value="$about->identity_explanation" :rows="3" />
                </x-admin.card>

                <x-admin.card title="প্রকাশনা">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="is_published" name="is_published" value="1"
                               @checked(old('is_published', $about->is_published)) @disabled(! auth()->user()->can('settings.update'))>
                        <label class="form-check-label" for="is_published">সাইটে প্রকাশযোগ্য</label>
                    </div>
                </x-admin.card>

                @can('settings.update')
                    <div class="d-flex flex-wrap gap-2 mb-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>সংরক্ষণ করুন
                        </button>
                    </div>
                @endcan
            </div>

            <div class="col-lg-5">
                {{-- Admin-side aid only — approximates the public About page's
                     reading order; never iframes or touches the live site. --}}
                <x-admin.card title="প্রিভিউ" subtitle="পাবলিক সাইটে ধারণাগতভাবে যেভাবে দেখাবে।">
                    <p class="text-muted fs-13 mb-2">{{ $about->introduction ?: 'সংক্ষিপ্ত ভূমিকা এখনো যোগ করা হয়নি।' }}</p>
                    <p class="mb-3">{{ $about->description ?: '—' }}</p>
                    @if ($about->registration_status)
                        <p class="fs-13 text-muted mb-3"><i class="ti ti-file-certificate me-1" aria-hidden="true"></i>{{ $about->registration_status }}</p>
                    @endif
                    @if ($about->history)
                        <h3 class="fs-14 fw-semibold mt-3">ইতিহাস</h3>
                        <p class="fs-14">{{ $about->history }}</p>
                    @endif
                    @if ($about->why_exists)
                        <h3 class="fs-14 fw-semibold mt-3">কেন প্রভাতফেরী</h3>
                        <p class="fs-14">{{ $about->why_exists }}</p>
                    @endif
                    @if ($about->identity_explanation)
                        <h3 class="fs-14 fw-semibold mt-3">নাম/পরিচয়</h3>
                        <p class="fs-14">{{ $about->identity_explanation }}</p>
                    @endif
                    @if (! $about->is_published)
                        <div class="alert alert-warning fs-13 mb-0 mt-3">
                            <i class="ti ti-eye-off me-1" aria-hidden="true"></i>বর্তমানে অপ্রকাশিত হিসেবে চিহ্নিত।
                        </div>
                    @endif
                </x-admin.card>
            </div>
        </div>
    </form>
@endsection
