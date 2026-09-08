@extends('layouts.admin')

@section('content')
    @can('settings.update')
        <form method="POST" action="{{ route('admin.settings.update') }}">
            @csrf
            @method('PUT')
    @endcan

    <div class="row">
        <div class="col-lg-6">
            <x-admin.card title="Identity" subtitle="প্রতিষ্ঠানের পরিচয়।">
                <x-admin.form-input name="site.name_bn" label="বাংলা নাম" :value="$settings['site.name_bn'] ?? ''" required
                    :disabled="! auth()->user()->can('settings.update')" />
                <x-admin.form-input name="site.name_en" label="ইংরেজি নাম" :value="$settings['site.name_en'] ?? ''" required
                    :disabled="! auth()->user()->can('settings.update')" />
                <div class="row">
                    <div class="col-md-6">
                        <x-admin.form-input name="site.short_name" label="সংক্ষিপ্ত নাম" :value="$settings['site.short_name'] ?? ''" required
                            :disabled="! auth()->user()->can('settings.update')" />
                    </div>
                    <div class="col-md-6">
                        <x-admin.form-input name="site.acronym" label="আদ্যক্ষর (Acronym)" :value="$settings['site.acronym'] ?? ''"
                            help="যেমন: PLCC" :disabled="! auth()->user()->can('settings.update')" />
                    </div>
                </div>
                <x-admin.form-input name="site.tagline" label="ট্যাগলাইন / স্লোগান" :value="$settings['site.tagline'] ?? ''"
                    :disabled="! auth()->user()->can('settings.update')" />
            </x-admin.card>

            <x-admin.card title="Contact" subtitle="সরকারি যোগাযোগের তথ্য — শুধু অনুমোদিত পাবলিক তথ্য এখানে রাখুন।">
                <div class="row">
                    <div class="col-md-6">
                        <x-admin.form-input name="site.email" label="ই-মেইল" type="email" :value="$settings['site.email'] ?? ''" required
                            :disabled="! auth()->user()->can('settings.update')" />
                    </div>
                    <div class="col-md-6">
                        <x-admin.form-input name="site.phone" label="ফোন" :value="$settings['site.phone'] ?? ''"
                            :disabled="! auth()->user()->can('settings.update')" />
                    </div>
                </div>
                <x-admin.form-textarea name="site.address" label="ঠিকানা" :value="$settings['site.address'] ?? ''" :rows="2"
                    :disabled="! auth()->user()->can('settings.update')" />
                <x-admin.form-input name="site.facebook_url" label="ফেসবুক লিঙ্ক" type="url" :value="$settings['site.facebook_url'] ?? ''"
                    :disabled="! auth()->user()->can('settings.update')" />
            </x-admin.card>
        </div>

        <div class="col-lg-6">
            <x-admin.card title="SEO / Entity" subtitle="সার্চ ইঞ্জিন ও স্ট্রাকচার্ড ডেটার জন্য ব্যবহৃত হয়।">
                <x-admin.form-input name="site.seo_title" label="ডিফল্ট সাইট টাইটেল" :value="$settings['site.seo_title'] ?? ''"
                    :disabled="! auth()->user()->can('settings.update')" />
                <x-admin.form-textarea name="site.seo_description" label="ডিফল্ট মেটা বিবরণ" :value="$settings['site.seo_description'] ?? ''" :rows="2"
                    help="সার্চ ফলাফলে দেখা যাওয়া সংক্ষিপ্ত বিবরণ (সর্বোচ্চ ৫০০ অক্ষর)।" :disabled="! auth()->user()->can('settings.update')" />
                <x-admin.form-input name="site.website_url" label="ক্যানোনিক্যাল ওয়েবসাইট URL" type="url" :value="$settings['site.website_url'] ?? ''"
                    :disabled="! auth()->user()->can('settings.update')" />

                @if (! empty($settings['site.alternate_names']))
                    <div class="mb-3">
                        <p class="form-label mb-1">বিকল্প নাম (Alternate names)</p>
                        <div class="d-flex flex-wrap gap-2">
                            @foreach (json_decode($settings['site.alternate_names'], true) ?? [] as $name)
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ $name }}</span>
                            @endforeach
                        </div>
                        <div class="form-text">স্ট্রাকচার্ড ডেটা থেকে স্বয়ংক্রিয়ভাবে সেট — এই স্ক্রিন থেকে সম্পাদনাযোগ্য নয়।</div>
                    </div>
                @endif
            </x-admin.card>

            <x-admin.card title="Related sites" subtitle="প্রভাতফেরীর সহযোগী সাইট।">
                <x-admin.form-input name="site.literature_url" label="সাহিত্যপাতা URL" type="url" :value="$settings['site.literature_url'] ?? ''"
                    :disabled="! auth()->user()->can('settings.update')" />
            </x-admin.card>

            @can('settings.update')
                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>সংরক্ষণ করুন
                    </button>
                </div>
            @endcan
        </div>
    </div>

    @can('settings.update')
        </form>
    @endcan
@endsection
