@extends('layouts.admin')

@section('content')
    @php $isEdit = $activity->exists; @endphp

    <form method="POST" action="{{ $isEdit ? route('admin.activities.update', $activity) : route('admin.activities.store') }}">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        @if ($errors->has('activity_type_id') || $errors->has('summary') || $errors->has('start_datetime'))
            <div class="alert alert-warning fs-13" role="alert">
                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>
                {{ __('admin.fields.activity_publish_requirements_alert') }}
            </div>
        @endif

        <div class="row">
            <div class="col-lg-8">
                <x-admin.card title="{{ __('admin.fields.main_info') }}">
                    <x-admin.form-input name="title" label="{{ __('admin.common.title') }}" :value="$activity->title" required />
                    <x-admin.form-input name="title_en" label="{{ __('admin.fields.title') }} {{ __('admin.bilingual.en_label_suffix') }}" :value="$activity->title_en" />
                    <x-admin.form-textarea name="summary" label="{{ __('admin.common.summary') }}" :value="$activity->summary" :rows="2"
                        help="{{ __('admin.fields.shown_in_list_and_cards_help') }}" />
                    <x-admin.form-textarea name="summary_en" label="{{ __('admin.fields.summary') }} {{ __('admin.bilingual.en_label_suffix') }}" :value="$activity->summary_en" :rows="2" />
                    <x-admin.form-textarea name="description" label="{{ __('admin.fields.detailed_description') }}" :value="$activity->description" :rows="4" />
                    <x-admin.form-textarea name="description_en" label="{{ __('admin.fields.detailed_description') }} {{ __('admin.bilingual.en_label_suffix') }}" :value="$activity->description_en" :rows="4" />

                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-select name="activity_type_id" label="{{ __('admin.common.type') }}" :options="$types"
                                :value="$activity->activity_type_id" required />
                        </div>
                        <div class="col-md-6">
                            <x-admin.form-select name="organization_unit_id" label="{{ __('admin.fields.unit') }}" :options="$units"
                                :value="$activity->organization_unit_id" placeholder="{{ __('admin.filters.none_specific') }}" />
                        </div>
                    </div>
                </x-admin.card>

                <x-admin.card title="{{ __('admin.fields.time_and_venue') }}">
                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-input name="start_datetime" label="{{ __('admin.fields.opens_at_label') }}" type="datetime-local"
                                :value="$activity->start_datetime?->format('Y-m-d\TH:i')" />
                        </div>
                        <div class="col-md-6">
                            <x-admin.form-input name="end_datetime" label="{{ __('admin.fields.closes_at_label') }}" type="datetime-local"
                                :value="$activity->end_datetime?->format('Y-m-d\TH:i')" />
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-input name="venue" label="{{ __('admin.fields.venue') }}" :value="$activity->venue" />
                        </div>
                        <div class="col-md-6">
                            <x-admin.form-input name="participant_count" label="{{ __('admin.fields.participant_count') }}" type="number"
                                :value="$activity->participant_count ?? 0" required min="0"
                                help="{{ __('admin.fields.actual_count_not_estimate_help') }}" />
                        </div>
                    </div>
                    <x-admin.form-textarea name="address" label="{{ __('admin.common.address') }}" :value="$activity->address" :rows="2" />
                </x-admin.card>

                <x-admin.card title="{{ __('admin.fields.details_and_results') }}" subtitle="{{ __('admin.fields.leave_empty_if_no_real_info_help') }}">
                    <x-admin.form-textarea name="objective" label="{{ __('admin.fields.objective') }}" :value="$activity->objective" :rows="2" />
                    <x-admin.form-textarea name="objective_en" label="{{ __('admin.fields.objective') }} {{ __('admin.bilingual.en_label_suffix') }}" :value="$activity->objective_en" :rows="2" />
                    <x-admin.form-textarea name="what_happened" label="{{ __('admin.fields.what_happened') }}" :value="$activity->what_happened" :rows="4" />
                    <x-admin.form-textarea name="what_happened_en" label="{{ __('admin.fields.what_happened') }} {{ __('admin.bilingual.en_label_suffix') }}" :value="$activity->what_happened_en" :rows="4" />
                    <x-admin.form-textarea name="outcomes" label="{{ __('admin.fields.outcomes_impact') }}" :value="$activity->outcomes" :rows="3" />
                    <x-admin.form-textarea name="outcomes_en" label="{{ __('admin.fields.outcomes_impact') }} {{ __('admin.bilingual.en_label_suffix') }}" :value="$activity->outcomes_en" :rows="3" />
                    <x-admin.form-input name="facebook_post_url" label="{{ __('admin.fields.facebook_post_link') }}" type="url" :value="$activity->facebook_post_url" />
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card title="{{ __('admin.common.publication') }}">
                    <x-admin.form-select name="status" label="{{ __('admin.common.status') }}" :options="$statuses"
                        :value="$activity->status" :placeholder="null" required
                        help="{{ __('admin.fields.publish_requirements_help') }}" />

                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="featured" name="featured" value="1"
                               @checked(old('featured', $activity->featured ?? false))>
                        <label class="form-check-label" for="featured">{{ __('admin.fields.show_as_featured_activity') }}</label>
                    </div>

                    @if ($activity->published_at)
                        <p class="fs-12 text-muted mt-3 mb-0">{{ __('admin.fields.first_published_label') }}: {{ bn_datetime($activity->published_at) }}</p>
                    @endif
                </x-admin.card>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? __('admin.actions.update') : __('admin.actions.create') }}
                    </button>
                    <a href="{{ $isEdit ? route('admin.activities.show', $activity) : route('admin.activities.index') }}"
                       class="btn btn-light">{{ __('admin.actions.cancel') }}</a>
                </div>
            </div>
        </div>
    </form>
@endsection
