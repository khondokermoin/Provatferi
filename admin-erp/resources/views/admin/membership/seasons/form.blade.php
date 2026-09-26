@extends('layouts.admin')

@section('content')
    @php $isEdit = $season->exists; @endphp

    <form method="POST" action="{{ $isEdit ? route('admin.membership.seasons.update', $season) : route('admin.membership.seasons.store') }}">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        <div class="row">
            <div class="col-lg-8">
                <x-admin.card title="{{ __('admin.common.description') }}">
                    <x-admin.form-input name="name" label="নাম (বাংলা)" :value="$season->name" required
                        help="যেমন: ২০২৬ প্রথম সিজন, ২০২৬–২৭ সদস্য নিবন্ধন" />
                    <x-admin.form-input name="name_en" label="নাম (English, ঐচ্ছিক)" :value="$season->name_en" />
                    <x-admin.form-textarea name="description" label="{{ __('admin.common.description') }}" :value="$season->description" :rows="3" />
                    <x-admin.form-textarea name="cash_payment_instructions" label="নগদ পরিশোধের নির্দেশনা"
                        :value="$season->cash_payment_instructions" :rows="3"
                        help="আবেদনকারীকে দেখানো হবে — কোথায়/কীভাবে ফি জমা দিতে হবে।" />
                </x-admin.card>

                <x-admin.card title="সময়সীমা ও মেয়াদ">
                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-input name="opens_at" label="শুরুর তারিখ ও সময়" type="datetime-local"
                                :value="$season->opens_at?->format('Y-m-d\TH:i')" />
                        </div>
                        <div class="col-md-6">
                            <x-admin.form-input name="closes_at" label="শেষের তারিখ ও সময়" type="datetime-local"
                                :value="$season->closes_at?->format('Y-m-d\TH:i')" />
                        </div>
                    </div>
                    <x-admin.form-input name="membership_period_months" label="সদস্যপদের মেয়াদ (মাস, ঐচ্ছিক)" type="number"
                        :value="$season->membership_period_months" min="1"
                        help="এই সিজনে অনুমোদিত সদস্যপদ কত মাসের জন্য কার্যকর থাকবে। খালি রাখলে সদস্যপদের ধরন অনুযায়ী নির্ধারিত হবে।" />
                </x-admin.card>

                <x-admin.card title="অনুমোদিত সদস্যপদের ধরন">
                    @foreach ($membershipTypes as $type)
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="type-{{ $type->id }}"
                                   name="membership_type_ids[]" value="{{ $type->id }}"
                                   @checked(in_array($type->id, old('membership_type_ids', $selectedTypeIds), false))>
                            <label class="form-check-label" for="type-{{ $type->id }}">{{ $type->name }}</label>
                        </div>
                    @endforeach
                    @error('membership_type_ids')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card title="{{ __('admin.common.publication') }}">
                    <x-admin.form-select name="campaign_type" label="সিজনের ধরন" :options="$campaignTypes"
                        :value="$season->campaign_type" :placeholder="null" required />
                    <x-admin.form-select name="status" label="{{ __('admin.common.status') }}" :options="$statuses"
                        :value="$season->status" :placeholder="null" required />
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="public_profile_opt_in" name="public_profile_opt_in" value="1"
                               @checked(old('public_profile_opt_in', $season->public_profile_opt_in ?? true))>
                        <label class="form-check-label" for="public_profile_opt_in">পাবলিক প্রোফাইল অপশন চালু</label>
                    </div>
                    <x-admin.form-input name="display_order" label="{{ __('admin.common.order') }}" type="number" :value="$season->display_order ?? 0" required min="0" />
                </x-admin.card>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? __('admin.actions.update') : __('admin.actions.create') }}
                    </button>
                    <a href="{{ route('admin.membership.seasons.index') }}" class="btn btn-light">{{ __('admin.actions.cancel') }}</a>
                </div>
            </div>
        </div>
    </form>
@endsection
