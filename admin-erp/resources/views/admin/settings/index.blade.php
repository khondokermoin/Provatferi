@extends('layouts.admin')

@section('content')
    @can('settings.update')
        <form method="POST" action="{{ route('admin.settings.update') }}">
            @csrf
            @method('PUT')
    @endcan

    <div class="row">
        <div class="col-lg-6">
            <x-admin.card title="{{ __('admin.fields.identity') }}" subtitle="{{ __('admin.fields.org_identity_subtitle') }}">
                <x-admin.form-input name="site.name_bn" label="{{ __('admin.fields.name_bangla_label') }}" :value="$settings['site.name_bn'] ?? ''" required
                    :disabled="! auth()->user()->can('settings.update')" />
                <x-admin.form-input name="site.name_en" label="{{ __('admin.fields.name_english_label') }}" :value="$settings['site.name_en'] ?? ''" required
                    :disabled="! auth()->user()->can('settings.update')" />
                <div class="row">
                    <div class="col-md-6">
                        <x-admin.form-input name="site.short_name" label="{{ __('admin.fields.short_name') }}" :value="$settings['site.short_name'] ?? ''" required
                            :disabled="! auth()->user()->can('settings.update')" />
                    </div>
                    <div class="col-md-6">
                        <x-admin.form-input name="site.acronym" label="{{ __('admin.fields.acronym_label') }}" :value="$settings['site.acronym'] ?? ''"
                            help="{{ __('admin.fields.acronym_example_help') }}" :disabled="! auth()->user()->can('settings.update')" />
                    </div>
                </div>
                <x-admin.form-input name="site.tagline" label="{{ __('admin.fields.tagline') }}" :value="$settings['site.tagline'] ?? ''"
                    :disabled="! auth()->user()->can('settings.update')" />
            </x-admin.card>

            <x-admin.card title="{{ __('admin.fields.contact') }}" subtitle="{{ __('admin.fields.official_contact_subtitle') }}">
                <div class="row">
                    <div class="col-md-6">
                        <x-admin.form-input name="site.email" label="{{ __('admin.common.email') }}" type="email" :value="$settings['site.email'] ?? ''" required
                            :disabled="! auth()->user()->can('settings.update')" />
                    </div>
                    <div class="col-md-6">
                        <x-admin.form-input name="site.phone" label="{{ __('admin.fields.phone_short') }}" :value="$settings['site.phone'] ?? ''"
                            :disabled="! auth()->user()->can('settings.update')" />
                    </div>
                </div>
                <x-admin.form-textarea name="site.address" label="{{ __('admin.common.address') }}" :value="$settings['site.address'] ?? ''" :rows="2"
                    :disabled="! auth()->user()->can('settings.update')" />
                <x-admin.form-input name="site.facebook_url" label="{{ __('admin.fields.facebook_link') }}" type="url" :value="$settings['site.facebook_url'] ?? ''"
                    :disabled="! auth()->user()->can('settings.update')" />
            </x-admin.card>
        </div>

        <div class="col-lg-6">
            <x-admin.card title="{{ __('admin.fields.search_engine_info') }}" subtitle="{{ __('admin.fields.google_display_control_subtitle') }}">
                <x-admin.form-input name="site.seo_title" label="{{ __('admin.fields.default_site_title') }}" :value="$settings['site.seo_title'] ?? ''"
                    :disabled="! auth()->user()->can('settings.update')" />
                <x-admin.form-textarea name="site.seo_description" label="{{ __('admin.fields.default_meta_description') }}" :value="$settings['site.seo_description'] ?? ''" :rows="2"
                    help="{{ __('admin.fields.meta_description_help') }}" :disabled="! auth()->user()->can('settings.update')" />
                <x-admin.form-input name="site.website_url" label="{{ __('admin.fields.website_main_url') }}" type="url" :value="$settings['site.website_url'] ?? ''"
                    help="{{ __('admin.fields.canonical_url_help') }}" :disabled="! auth()->user()->can('settings.update')" />

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

            <x-admin.card title="{{ __('admin.fields.related_sites') }}" subtitle="{{ __('admin.fields.provatferi_affiliate_site_subtitle') }}">
                <x-admin.form-input name="site.literature_url" label="{{ __('admin.fields.literature_site_url') }}" type="url" :value="$settings['site.literature_url'] ?? ''"
                    :disabled="! auth()->user()->can('settings.update')" />
            </x-admin.card>

            @can('settings.update')
                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ __('admin.actions.save') }}
                    </button>
                </div>
            @endcan
        </div>
    </div>

    @can('settings.update')
        </form>
    @endcan
@endsection
