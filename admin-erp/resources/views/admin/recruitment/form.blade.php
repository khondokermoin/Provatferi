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
                <x-admin.card title="{{ __('admin.common.description') }}">
                    <x-admin.form-input name="title" label="{{ __('admin.common.title') }}" :value="$jobPosting->title" required />
                    <x-admin.form-input name="title_en" label="{{ __('admin.fields.title') }} {{ __('admin.bilingual.en_label_suffix') }}" :value="$jobPosting->title_en" />
                    <x-admin.form-input name="slug" label="{{ __('admin.common.slug') }}" :value="$jobPosting->slug"
                        help="{{ $isEdit ? __('admin.fields.slug_change_redirect_help') : __('admin.fields.slug_auto_generate_help') }}" />
                    <x-admin.form-textarea name="summary" label="{{ __('admin.common.summary') }}" :value="$jobPosting->summary" :rows="2" />
                    <x-admin.form-textarea name="summary_en" label="{{ __('admin.fields.summary') }} {{ __('admin.bilingual.en_label_suffix') }}" :value="$jobPosting->summary_en" :rows="2" />
                    <x-admin.form-textarea name="description" label="{{ __('admin.fields.full_body') }}" :value="$jobPosting->description" :rows="6" required />
                    <x-admin.form-textarea name="description_en" label="{{ __('admin.fields.full_body') }} {{ __('admin.bilingual.en_label_suffix') }}" :value="$jobPosting->description_en" :rows="6" />
                    <x-admin.form-textarea name="requirements" label="{{ __('admin.fields.requirements') }}" :value="$jobPosting->requirements" :rows="4" />
                    <x-admin.form-textarea name="requirements_en" label="{{ __('admin.fields.requirements') }} {{ __('admin.bilingual.en_label_suffix') }}" :value="$jobPosting->requirements_en" :rows="4" />

                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-select name="organization_unit_id" label="{{ __('admin.fields.unit') }}" :options="$units"
                                :value="$jobPosting->organization_unit_id" placeholder="{{ __('admin.filters.none_specific') }}" />
                        </div>
                        <div class="col-md-6">
                            <x-admin.form-input name="department" label="{{ __('admin.fields.department') }}" :value="$jobPosting->department" />
                        </div>
                    </div>
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card title="{{ __('admin.fields.terms_and_conditions') }}">
                    <x-admin.form-select name="employment_type" label="{{ __('admin.fields.employment_type') }}" :options="$employmentTypes"
                        :value="$jobPosting->employment_type" placeholder="{{ __('admin.filters.none_specific') }}" />
                    <x-admin.form-input name="salary_range" label="{{ __('admin.fields.salary_range') }}" :value="$jobPosting->salary_range"
                        help="{{ __('admin.fields.salary_help') }}" />
                    <x-admin.form-input name="opening_date" label="{{ __('admin.fields.application_start_date') }}" type="date"
                        :value="$jobPosting->opening_date?->format('Y-m-d')" />
                    <x-admin.form-select name="application_mode" label="{{ __('admin.fields.application_mode') }}" :options="$applicationModes"
                        :value="$jobPosting->application_mode ?? 'fixed'" :placeholder="null"
                        help="{{ __('admin.fields.rolling_mode_help') }}" />
                    <x-admin.form-input name="application_deadline" label="{{ __('admin.fields.application_deadline_field') }}" type="date"
                        :value="$jobPosting->application_deadline?->format('Y-m-d')"
                        help="{{ __('admin.fields.applicable_fixed_deadline_only_help') }}" />

                    <div class="form-check mt-3">
                        <input type="checkbox" class="form-check-input" id="accepts_applications" name="accepts_applications" value="1"
                               aria-describedby="accepts_applications-help"
                               @checked(old('accepts_applications', $jobPosting->accepts_applications))>
                        <label class="form-check-label" for="accepts_applications">{{ __('admin.fields.accepts_applications_label') }}</label>
                    </div>
                    <div class="form-text" id="accepts_applications-help">
                        {{ __('admin.fields.accepts_applications_help') }}
                    </div>
                </x-admin.card>

                {{-- Application Form Settings: per-posting Required/Optional for the
                     form's non-core fields. field_requirements[<key>] posts as a flat
                     array; RecruitmentController::validated() keeps only known keys. --}}
                <x-admin.card title="{{ __('admin.fields.application_form_field_settings') }}"
                    subtitle="{{ __('admin.fields.field_settings_subtitle') }}">
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
                <x-admin.card title="{{ __('admin.fields.social_share_image') }}">
                    @if ($jobPosting->share_image_path)
                        <img src="{{ route('admin.recruitment.files.share', $jobPosting) }}" alt=""
                             class="rounded border mb-2" style="max-width: 240px; max-height: 126px; object-fit: cover;">
                    @endif
                    <x-admin.form-input name="share_image" label="{{ __('admin.fields.share_image_label') }} ({{ __('admin.common.optional') }})" type="file" accept="image/jpeg,image/png,image/webp"
                        help="{{ __('admin.fields.share_image_help') }}" />
                    @if ($jobPosting->share_image_path)
                        <div class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" id="remove_share_image" name="remove_share_image" value="1">
                            <label class="form-check-label" for="remove_share_image">
                                {{ __('admin.fields.remove_current_share_image') }}
                                (<a href="{{ route('admin.recruitment.files.share', $jobPosting) }}" target="_blank" rel="noopener noreferrer">{{ __('admin.actions.view') }}</a>)
                            </label>
                        </div>
                    @endif
                </x-admin.card>

                <x-admin.card title="{{ __('admin.common.publication') }}">
                    <x-admin.form-select name="status" label="{{ __('admin.common.status') }}" :options="$statuses"
                        :value="$jobPosting->status" :placeholder="null" required />
                    @if ($jobPosting->published_at)
                        <p class="fs-12 text-muted mb-0">{{ __('admin.fields.first_published_label') }}: {{ bn_datetime($jobPosting->published_at) }}</p>
                    @endif

                    @can('notices.create')
                        <div class="border-top pt-3 mt-3">
                            @if ($linkedNotice)
                                <p class="fs-13 mb-0">
                                    <i class="ti ti-speakerphone me-1" aria-hidden="true"></i>{{ __('admin.fields.linked_to_notice_board_label') }}:
                                    <a href="{{ route('admin.notices.show', $linkedNotice) }}" class="fw-semibold">{{ $linkedNotice->title }}</a>
                                </p>
                            @else
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="publish_to_notice_board" name="publish_to_notice_board" value="1"
                                           aria-describedby="publish_to_notice_board-help" @checked(old('publish_to_notice_board'))>
                                    <label class="form-check-label" for="publish_to_notice_board">{{ __('admin.fields.publish_to_notice_board') }}</label>
                                </div>
                                <div class="form-text" id="publish_to_notice_board-help">
                                    {{ __('admin.fields.notice_will_be_created_help') }}
                                </div>
                            @endif
                        </div>
                    @endcan
                </x-admin.card>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? __('admin.actions.update') : __('admin.actions.create') }}
                    </button>
                    <a href="{{ $isEdit ? route('admin.recruitment.show', $jobPosting) : route('admin.recruitment.index') }}"
                       class="btn btn-light">{{ __('admin.actions.cancel') }}</a>
                </div>
            </div>
        </div>
    </form>
@endsection
