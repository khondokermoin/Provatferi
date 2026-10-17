@extends('layouts.admin')

@section('content')
    {{-- Text attributes of <x-admin.*> tags are BOUND (:label="__('…')"), never interpolated (an interpolated attribute is
         escaped twice and an English apostrophe shows as &#039;; docs/PUBLIC_CONTACT_SETTINGS.md). Field names use
         underscores only (PHP rewrites a dot in a field name to an underscore). --}}
    @php
        $isEdit = $profile->exists;
        $archived = $profile->isArchived();
        $socialExamples = [
            'facebook_url' => ['label' => __('admin.contact_settings.attr_facebook'), 'example' => 'https://www.facebook.com/yourpage'],
            'linkedin_url' => ['label' => __('admin.contact_settings.attr_linkedin'), 'example' => 'https://www.linkedin.com/in/yourname'],
            'youtube_url' => ['label' => __('admin.contact_settings.attr_youtube'), 'example' => 'https://www.youtube.com/@yourchannel'],
        ];
    @endphp

    @if ($archived)
        <div class="alert alert-secondary d-flex align-items-start gap-2" role="status">
            <i class="ti ti-archive fs-18 mt-1" aria-hidden="true"></i>
            <div class="fs-13">{{ __('admin.leadership.archived_banner') }}</div>
        </div>
    @endif

    <form method="POST" enctype="multipart/form-data" id="leadership-form"
          action="{{ $isEdit ? route('admin.leadership.update', $profile) : route('admin.leadership.store') }}"
          data-form-guard
          data-guard-message="{{ __('admin.contact_settings.unsaved_warning') }}"
          data-saving-label="{{ __('admin.contact_settings.saving') }}">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        <div class="row">
            <div class="col-lg-8">
                <x-admin.card :title="__('admin.leadership.identity_title')">
                    <x-admin.bilingual-field name="name" bn-name="name_bn" en-name="name_en" :label="__('admin.leadership.name')"
                        :bn-value="$profile->name_bn" :en-value="$profile->name_en" required maxlength="{{ $max['name_bn'] }}" />

                    <x-admin.bilingual-field name="designation" bn-name="designation_bn" en-name="designation_en" :label="__('admin.leadership.designation')"
                        :bn-value="$profile->designation_bn" :en-value="$profile->designation_en" required maxlength="{{ $max['designation_bn'] }}"
                        :help="__('admin.leadership.designation_help')" />

                    <x-admin.form-input name="slug" :label="__('admin.leadership.slug')" :value="$profile->slug"
                        maxlength="80" autocomplete="off" spellcheck="false" inputmode="url"
                        :help="$isEdit ? __('admin.leadership.slug_help_edit') : __('admin.leadership.slug_help_new')" />
                </x-admin.card>

                <x-admin.card :title="__('admin.leadership.profile_title')" class="mt-3">
                    <x-admin.bilingual-field as="textarea" name="short_bio" bn-name="short_bio_bn" en-name="short_bio_en" :label="__('admin.leadership.short_bio')"
                        :bn-value="$profile->short_bio_bn" :en-value="$profile->short_bio_en" :rows="3" maxlength="{{ $max['short_bio_bn'] }}"
                        :help="__('admin.leadership.short_bio_help', ['max' => $max['short_bio_bn']])" />

                    <x-admin.bilingual-field as="textarea" name="full_bio" bn-name="full_bio_bn" en-name="full_bio_en" :label="__('admin.leadership.full_bio')"
                        :bn-value="$profile->full_bio_bn" :en-value="$profile->full_bio_en" :rows="10" maxlength="{{ $max['full_bio_bn'] }}"
                        :help="__('admin.leadership.full_bio_help')" />

                    <x-admin.bilingual-field as="textarea" name="quote" bn-name="quote_bn" en-name="quote_en" :label="__('admin.leadership.quote')"
                        :bn-value="$profile->quote_bn" :en-value="$profile->quote_en" :rows="2" maxlength="{{ $max['quote_bn'] }}" />

                    <x-admin.bilingual-field name="expertise" bn-name="expertise_bn" en-name="expertise_en" :label="__('admin.leadership.expertise')"
                        :bn-value="$profile->expertise_bn" :en-value="$profile->expertise_en" maxlength="{{ $max['expertise_bn'] }}" />

                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-input name="associated_since_year" :label="__('admin.leadership.associated_since')" type="number"
                                :value="$profile->associated_since_year" min="1900" :max="\App\Support\AdminTime::year()" inputmode="numeric"
                                :help="__('admin.leadership.associated_since_help')" />
                        </div>
                    </div>
                </x-admin.card>

                <x-admin.card :title="__('admin.leadership.contact_title')" class="mt-3">
                    <div class="alert alert-warning d-flex align-items-start gap-2 fs-13" role="note">
                        <i class="ti ti-eye fs-18 mt-1" aria-hidden="true"></i>
                        <div>{{ __('admin.leadership.contact_notice') }}</div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-input name="public_email" :label="__('admin.leadership.public_email')" type="email"
                                :value="$profile->public_email" maxlength="255" autocomplete="off"
                                :help="__('admin.leadership.public_email_help')" />
                        </div>
                        <div class="col-md-6">
                            <x-admin.form-input name="public_phone" :label="__('admin.leadership.public_phone')" type="tel"
                                :value="$profile->public_phone" maxlength="40" inputmode="tel" autocomplete="off"
                                :help="__('admin.leadership.public_phone_help')" />
                        </div>
                    </div>
                    @foreach ($socialExamples as $field => $meta)
                        <x-admin.form-input :name="$field" :label="$meta['label']" type="url"
                            :value="$profile->{$field}" maxlength="500" inputmode="url" autocomplete="off" spellcheck="false"
                            pattern="https://\S+" :title="__('admin.contact_settings.https_only')"
                            :placeholder="$meta['example']"
                            :help="__('admin.leadership.links_help', ['example' => $meta['example']])" />
                    @endforeach
                    <x-admin.form-input name="website_url" :label="__('admin.leadership.website')" type="url"
                        :value="$profile->website_url" maxlength="500" inputmode="url" autocomplete="off" spellcheck="false"
                        pattern="https://\S+" :title="__('admin.contact_settings.https_only')"
                        placeholder="https://example.org"
                        :help="__('admin.leadership.links_help', ['example' => 'https://example.org'])" />
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card :title="__('admin.leadership.media_title')">
                    <div class="mb-3">
                        <span class="form-label d-block">{{ __('admin.leadership.photo_current') }}</span>
                        @if ($profile->hasPhoto())
                            <img src="{{ $profile->photoSmUrl() }}" alt="" class="rounded border mb-2" style="max-width: 100%; width: 240px; height: auto;">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="field-remove_photo" name="remove_photo" value="1" @checked(old('remove_photo'))>
                                <label class="form-check-label" for="field-remove_photo">{{ __('admin.leadership.photo_remove') }}</label>
                            </div>
                        @else
                            <p class="text-muted fs-13 mb-0">{{ __('admin.leadership.photo_none') }}</p>
                        @endif
                    </div>
                    <x-admin.form-input name="photo" :label="__('admin.leadership.photo')" type="file"
                        accept="image/jpeg,image/png,image/webp" :help="__('admin.leadership.photo_help')" />
                </x-admin.card>

                <x-admin.card :title="__('admin.leadership.publishing_title')" class="mt-3">
                    @if ($archived)
                        <p class="text-muted fs-13 mb-0"><i class="ti ti-archive" aria-hidden="true"></i> {{ __('admin.leadership.archived_form_notice') }}</p>
                    @elseif ($canPublish)
                        <x-admin.form-select name="status" :label="__('admin.leadership.status')" :options="$statuses"
                            :value="$profile->status" :placeholder="null" required :help="__('admin.leadership.status_help')" />

                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input type="hidden" name="featured" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" id="field-featured" name="featured" value="1"
                                       @checked((bool) old('featured', $profile->featured)) aria-describedby="field-featured-help">
                                <label class="form-check-label" for="field-featured">{{ __('admin.leadership.featured') }}</label>
                            </div>
                            <div class="form-text" id="field-featured-help">{{ __('admin.leadership.featured_help') }}</div>
                            @error('featured')
                                <div class="invalid-feedback d-block"><i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}</div>
                            @enderror
                        </div>

                        <x-admin.form-input name="display_order" :label="__('admin.leadership.display_order')" type="number"
                            :value="$profile->display_order" min="0" max="100000" inputmode="numeric"
                            :help="__('admin.leadership.display_order_help')" />
                    @else
                        <p class="mb-2 fs-13">
                            <span class="text-muted">{{ __('admin.leadership.current_status') }}:</span>
                            <x-admin.status-badge :status="$profile->status ?: 'inactive'" />
                        </p>
                        <p class="text-muted fs-13 mb-0">
                            <i class="ti ti-lock" aria-hidden="true"></i>
                            {{ $isEdit ? __('admin.leadership.publish_locked_notice') : __('admin.leadership.new_profile_hidden_notice') }}
                        </p>
                    @endif
                </x-admin.card>

                <div class="d-flex flex-wrap gap-2 my-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? __('admin.actions.update') : __('admin.actions.create') }}
                    </button>
                    <a href="{{ route('admin.leadership.index') }}" class="btn btn-light">{{ __('admin.actions.cancel') }}</a>
                    @if ($isEdit)
                        <a href="{{ route('admin.leadership.preview', $profile) }}" class="btn btn-light">
                            <i class="ti ti-eye me-1" aria-hidden="true"></i>{{ __('admin.leadership.preview') }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </form>
@endsection

{{-- The unsaved-changes and double-submit guard of the Settings screen (public/js/provatferi-form-guard.js). --}}
@push('scripts')
    <script src="{{ asset('js/provatferi-form-guard.js') }}"></script>
@endpush
