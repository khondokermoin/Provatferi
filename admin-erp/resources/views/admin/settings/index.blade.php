@extends('layouts.admin')

@section('content')
    @php
        $canEdit = auth()->user()->can('settings.update');
        $disabled = ! $canEdit;
        // Form fields use underscores (site_phone), never the dotted setting keys: PHP rewrites a dot in a field NAME to an
        // underscore, which made every save of this screen fail (App\Support\SiteSettings explains).
        $value = fn (string $key, mixed $default = '') => $settings[$key] ?? $default;
        $socialExamples = [
            'facebook' => 'https://www.facebook.com/yourpage',
            'linkedin' => 'https://www.linkedin.com/company/yourpage',
            'youtube' => 'https://www.youtube.com/@yourchannel',
        ];
    @endphp

    {{--
        Every text attribute of an <x-admin.*> tag below is BOUND (:label="__('…')"), never interpolated (label="{{ __('…') }}").
        Blade escapes an interpolated attribute and the component escapes it again on output, so an English string with an
        apostrophe or an ampersand ("Website's main address") showed as "Website&#039;s main address". A bound attribute is
        passed through untouched and escaped exactly once, by the component (tests/Feature/Admin/PublicContactSettingsTest).
    --}}
    @if ($canEdit)
        <form method="POST" action="{{ route('admin.settings.update') }}" id="site-settings-form"
              data-form-guard
              data-guard-message="{{ __('admin.contact_settings.unsaved_warning') }}"
              data-saving-label="{{ __('admin.contact_settings.saving') }}">
            @csrf
            @method('PUT')
    @endif

    <div class="row">
        <div class="col-lg-6">
            <x-admin.card :title="__('admin.contact_settings.contact_title')" :subtitle="__('admin.contact_settings.contact_subtitle')">
                <div class="row">
                    <div class="col-md-6">
                        <x-admin.form-input name="site_email" :label="__('admin.contact_settings.public_email')" type="email"
                            :value="$value('site.email')" required maxlength="255" autocomplete="off"
                            :help="__('admin.contact_settings.public_email_help')" :disabled="$disabled" />
                    </div>
                    <div class="col-md-6">
                        <x-admin.form-input name="site_support_email" :label="__('admin.contact_settings.support_email')" type="email"
                            :value="$value('site.support_email')" required maxlength="255" autocomplete="off"
                            :help="__('admin.contact_settings.support_email_help')" :disabled="$disabled" />
                    </div>
                </div>
                <x-admin.form-input name="site_phone" :label="__('admin.fields.phone_short')" type="tel"
                    :value="$value('site.phone')" maxlength="40" inputmode="tel" autocomplete="off"
                    :help="__('admin.contact_settings.phone_help')" :disabled="$disabled" />
                <x-admin.bilingual-field as="textarea" name="site_address" :label="__('admin.contact_settings.address')"
                    :bn-value="$value('site.address')" :en-value="$value('site.address_en')" :rows="3" maxlength="1000"
                    :help="__('admin.contact_settings.address_help')" :disabled="$disabled" />
            </x-admin.card>

            <x-admin.card :title="__('admin.contact_settings.social_title')" :subtitle="__('admin.contact_settings.social_subtitle')" class="mt-3">
                @foreach ($socialExamples as $platform => $example)
                    @php
                        $switch = 'site_'.$platform.'_enabled';
                        $isOn = filter_var(old($switch, $value('site.'.$platform.'_enabled', $platform === 'facebook' ? 'true' : 'false')), FILTER_VALIDATE_BOOLEAN);
                    @endphp
                    <div class="mb-4">
                        <x-admin.form-input :name="'site_'.$platform.'_url'" :label="__('admin.contact_settings.attr_'.$platform)" type="url"
                            :value="$value('site.'.$platform.'_url')" maxlength="500" inputmode="url" autocomplete="off" spellcheck="false"
                            pattern="https://\S+" :title="__('admin.contact_settings.https_only')"
                            :placeholder="$example"
                            :help="__('admin.contact_settings.social_url_help', ['example' => $example])" :disabled="$disabled" />
                        <div class="form-check form-switch">
                            <input type="hidden" name="{{ $switch }}" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="field-{{ $switch }}" name="{{ $switch }}" value="1"
                                   @checked($isOn) @disabled($disabled)>
                            <label class="form-check-label" for="field-{{ $switch }}">{{ __('admin.contact_settings.show_on_website') }}</label>
                        </div>
                        @error($switch)
                            <div class="invalid-feedback d-block"><i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}</div>
                        @enderror
                    </div>
                @endforeach
            </x-admin.card>

            <x-admin.card :title="__('admin.contact_settings.location_title')" :subtitle="__('admin.contact_settings.location_subtitle')" class="mt-3">
                <x-admin.form-input name="site_map_url" :label="__('admin.contact_settings.map_url')" type="url"
                    :value="$value('site.map_url')" maxlength="1000" inputmode="url" autocomplete="off" spellcheck="false"
                    pattern="https://\S+" :title="__('admin.contact_settings.https_only')"
                    :help="__('admin.contact_settings.map_url_help')" :disabled="$disabled" />
                @php($savedMapLink = \App\Support\PublicLinks::map((string) $value('site.map_url'))['url'])
                @if ($savedMapLink)
                    <p class="mb-3">
                        <a href="{{ $savedMapLink }}" target="_blank" rel="noopener noreferrer">{{ __('admin.contact_settings.map_test') }} <i class="ti ti-external-link" aria-hidden="true"></i></a>
                    </p>
                @endif
                <x-admin.bilingual-field name="site_map_label" :label="__('admin.contact_settings.map_label')"
                    :bn-value="$value('site.map_label')" :en-value="$value('site.map_label_en')" maxlength="120" :disabled="$disabled" />
            </x-admin.card>
        </div>

        <div class="col-lg-6">
            <x-admin.card :title="__('admin.fields.identity')" :subtitle="__('admin.fields.org_identity_subtitle')">
                <x-admin.form-input name="site_name_bn" :label="__('admin.fields.name_bangla_label')" :value="$value('site.name_bn')" required maxlength="255" :disabled="$disabled" />
                <x-admin.form-input name="site_name_en" :label="__('admin.fields.name_english_label')" :value="$value('site.name_en')" required maxlength="255" :disabled="$disabled" />
                <div class="row">
                    <div class="col-md-6">
                        <x-admin.form-input name="site_short_name" :label="__('admin.fields.short_name')" :value="$value('site.short_name')" required maxlength="100" :disabled="$disabled" />
                    </div>
                    <div class="col-md-6">
                        <x-admin.form-input name="site_acronym" :label="__('admin.fields.acronym_label')" :value="$value('site.acronym')" maxlength="50"
                            :help="__('admin.fields.acronym_example_help')" :disabled="$disabled" />
                    </div>
                </div>
                <x-admin.form-input name="site_tagline" :label="__('admin.fields.tagline')" :value="$value('site.tagline')" maxlength="255" :disabled="$disabled" />
            </x-admin.card>

            <x-admin.card :title="__('admin.fields.search_engine_info')" :subtitle="__('admin.fields.google_display_control_subtitle')" class="mt-3">
                <x-admin.form-input name="site_seo_title" :label="__('admin.fields.default_site_title')" :value="$value('site.seo_title')" maxlength="255" :disabled="$disabled" />
                <x-admin.form-textarea name="site_seo_description" :label="__('admin.fields.default_meta_description')" :value="$value('site.seo_description')" :rows="2" maxlength="500"
                    :help="__('admin.fields.meta_description_help')" :disabled="$disabled" />
                <x-admin.form-input name="site_website_url" :label="__('admin.fields.website_main_url')" type="url" :value="$value('site.website_url')" maxlength="255"
                    :help="__('admin.fields.canonical_url_help')" :disabled="$disabled" />

                @if (! empty($settings['site.alternate_names']))
                    <div class="mb-3">
                        <p class="form-label mb-1">{{ __('admin.fields.alternate_names_label') }}</p>
                        <div class="d-flex flex-wrap gap-2">
                            @foreach (json_decode($settings['site.alternate_names'], true) ?? [] as $name)
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ $name }}</span>
                            @endforeach
                        </div>
                        <div class="form-text">{{ __('admin.fields.auto_set_from_structured_data_help') }}</div>
                    </div>
                @endif
            </x-admin.card>

            <x-admin.card :title="__('admin.fields.related_sites')" :subtitle="__('admin.fields.provatferi_affiliate_site_subtitle')" class="mt-3">
                <x-admin.form-input name="site_literature_url" :label="__('admin.fields.literature_site_url')" type="url" :value="$value('site.literature_url')" maxlength="255"
                    :disabled="$disabled" />
            </x-admin.card>
        </div>
    </div>

    @if ($canEdit)
            <div class="d-flex flex-wrap gap-2 my-4">
                <button type="submit" class="btn btn-primary">
                    <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ __('admin.actions.save') }}
                </button>
            </div>
        </form>
    @endif
@endsection

{{-- Harmless on a read-only view: the script only arms forms marked data-form-guard. --}}
@push('scripts')
    <script src="{{ asset('js/provatferi-form-guard.js') }}"></script>
@endpush
