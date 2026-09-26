@extends('layouts.admin')

@section('content')
    <form method="POST" action="{{ route('admin.content.about.update') }}">
        @csrf
        @method('PUT')

        <div class="row">
            <div class="col-lg-7">
                <x-admin.card title="{{ __('admin.fields.main_info') }}">
                    <x-admin.form-textarea name="introduction" label="{{ __('admin.fields.brief_intro') }}" :value="$about->introduction" :rows="2"
                        help="{{ __('admin.fields.brief_intro_help') }}" />
                    <x-admin.form-textarea name="introduction_en" label="{{ __('admin.fields.brief_intro') }} {{ __('admin.bilingual.en_label_suffix') }}" :value="$about->introduction_en" :rows="2" />
                    <x-admin.form-textarea name="description" label="{{ __('admin.fields.org_description') }}" :value="$about->description" :rows="4" />
                    <x-admin.form-textarea name="description_en" label="{{ __('admin.fields.org_description') }} {{ __('admin.bilingual.en_label_suffix') }}" :value="$about->description_en" :rows="4" />
                    <x-admin.form-textarea name="registration_status" label="{{ __('admin.fields.registration_status') }}" :value="$about->registration_status" :rows="1"
                        help="{{ __('admin.fields.registration_status_help') }}" />
                </x-admin.card>

                <x-admin.card title="{{ __('admin.fields.background') }}" subtitle="{{ __('admin.fields.no_fabrication_help') }}">
                    <x-admin.form-textarea name="history" label="{{ __('admin.fields.history_background') }}" :value="$about->history" :rows="5" />
                    <x-admin.form-textarea name="history_en" label="{{ __('admin.fields.history_background') }} {{ __('admin.bilingual.en_label_suffix') }}" :value="$about->history_en" :rows="5" />
                    <x-admin.form-textarea name="why_exists" label="{{ __('admin.fields.why_provatferi') }}" :value="$about->why_exists" :rows="4" />
                    <x-admin.form-textarea name="why_exists_en" label="{{ __('admin.fields.why_provatferi') }} {{ __('admin.bilingual.en_label_suffix') }}" :value="$about->why_exists_en" :rows="4" />
                    <x-admin.form-textarea name="identity_explanation" label="{{ __('admin.fields.identity_explanation') }}" :value="$about->identity_explanation" :rows="3" />
                    <x-admin.form-textarea name="identity_explanation_en" label="{{ __('admin.fields.identity_explanation') }} {{ __('admin.bilingual.en_label_suffix') }}" :value="$about->identity_explanation_en" :rows="3" />
                </x-admin.card>

                <x-admin.card title="{{ __('admin.common.publication') }}">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="is_published" name="is_published" value="1"
                               @checked(old('is_published', $about->is_published)) @disabled(! auth()->user()->can('settings.update'))>
                        <label class="form-check-label" for="is_published">{{ __('admin.fields.publishable_on_site') }}</label>
                    </div>
                </x-admin.card>

                @can('settings.update')
                    <div class="d-flex flex-wrap gap-2 mb-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ __('admin.actions.save') }}
                        </button>
                    </div>
                @endcan
            </div>

            <div class="col-lg-5">
                {{-- Admin-side aid only — approximates the public About page's
                     reading order; never iframes or touches the live site. --}}
                <x-admin.card title="{{ __('admin.fields.preview') }}" subtitle="{{ __('admin.fields.preview_hint') }}">
                    <p class="text-muted fs-13 mb-2">{{ $about->introduction ?: __('admin.fields.brief_intro_not_added_yet') }}</p>
                    <p class="mb-3">{{ $about->description ?: '—' }}</p>
                    @if ($about->registration_status)
                        <p class="fs-13 text-muted mb-3"><i class="ti ti-file-certificate me-1" aria-hidden="true"></i>{{ $about->registration_status }}</p>
                    @endif
                    @if ($about->history)
                        <h3 class="fs-14 fw-semibold mt-3">{{ __('admin.fields.history') }}</h3>
                        <p class="fs-14">{{ $about->history }}</p>
                    @endif
                    @if ($about->why_exists)
                        <h3 class="fs-14 fw-semibold mt-3">{{ __('admin.fields.why_provatferi') }}</h3>
                        <p class="fs-14">{{ $about->why_exists }}</p>
                    @endif
                    @if ($about->identity_explanation)
                        <h3 class="fs-14 fw-semibold mt-3">{{ __('admin.fields.name_identity') }}</h3>
                        <p class="fs-14">{{ $about->identity_explanation }}</p>
                    @endif
                    @if (! $about->is_published)
                        <div class="alert alert-warning fs-13 mb-0 mt-3">
                            <i class="ti ti-eye-off me-1" aria-hidden="true"></i>{{ __('admin.fields.currently_marked_unpublished') }}
                        </div>
                    @endif
                </x-admin.card>
            </div>
        </div>
    </form>
@endsection
