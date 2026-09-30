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
                <x-admin.card title="{{ __('admin.fields.notice_content') }}">
                    <x-admin.bilingual-field name="title" label="{{ __('admin.fields.subject') }}"
                        :bn-value="$notice->title" :en-value="$notice->title_en" required maxlength="255" />

                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-select name="notice_type" label="{{ __('admin.fields.notice_type') }}" :options="$types"
                                :value="$notice->notice_type" :placeholder="null" required />
                        </div>
                        <div class="col-md-6">
                            @if ($slugLocked)
                                <x-admin.form-input name="slug" label="{{ __('admin.fields.url_slug') }}" :value="$notice->slug" readonly
                                    help="{{ __('admin.fields.slug_locked_help') }}" />
                            @else
                                <x-admin.form-input name="slug" label="{{ __('admin.fields.url_slug') }} ({{ __('admin.common.optional') }})" :value="$notice->slug" maxlength="120"
                                    placeholder="{{ __('admin.fields.slug_example_placeholder') }}"
                                    help="{{ __('admin.fields.slug_format_help') }}" />
                            @endif
                        </div>
                    </div>

                    <x-admin.bilingual-field as="textarea" name="summary" label="{{ __('admin.common.summary') }}"
                        :bn-value="$notice->summary" :en-value="$notice->summary_en" :rows="3" maxlength="500"
                        help="{{ __('admin.fields.summary_shown_in_list_help') }}" />
                    <x-admin.bilingual-field as="textarea" name="body" label="{{ __('admin.fields.full_body') }}"
                        :bn-value="$notice->body" :en-value="$notice->body_en" :rows="18" required
                        help="{{ __('admin.fields.body_formatting_help') }}" />
                </x-admin.card>

                <x-admin.card title="{{ __('admin.fields.images_and_attachments') }}">
                    <div class="row">
                        <div class="col-md-6">
                            <x-admin.form-input name="cover_image" label="{{ __('admin.fields.image_label') }} ({{ __('admin.common.optional') }})" type="file" accept="image/jpeg,image/png,image/webp"
                                help="{{ __('admin.fields.image_format_size_help') }}" />
                            @if ($notice->cover_image_path)
                                <div class="form-check mb-3">
                                    <input type="checkbox" class="form-check-input" id="remove_cover_image" name="remove_cover_image" value="1">
                                    <label class="form-check-label" for="remove_cover_image">
                                        {{ __('admin.fields.remove_current_image') }}
                                        (<a href="{{ route('admin.notices.file', [$notice, 'cover']) }}" target="_blank" rel="noopener noreferrer">{{ __('admin.actions.view') }}</a>)
                                    </label>
                                </div>
                            @endif
                        </div>
                        <div class="col-md-6">
                            <x-admin.form-input name="attachment" label="{{ __('admin.fields.attachment_pdf_label') }} ({{ __('admin.common.optional') }})" type="file" accept="application/pdf"
                                help="{{ __('admin.fields.attachment_help') }}" />
                            @if ($notice->attachment_path)
                                <div class="form-check mb-3">
                                    <input type="checkbox" class="form-check-input" id="remove_attachment" name="remove_attachment" value="1">
                                    <label class="form-check-label" for="remove_attachment">
                                        {{ __('admin.fields.remove_current_attachment') }}
                                        (<a href="{{ route('admin.notices.file', [$notice, 'attachment']) }}">{{ __('admin.fields.download_short') }}</a>)
                                    </label>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- §12: a dedicated Open Graph image, separate from the in-page cover
                         image above — sized for how link previews render, not for the page. --}}
                    <hr class="my-3">
                    <div class="row">
                        <div class="col-md-6">
                            @if ($notice->share_image_path)
                                <img src="{{ route('admin.notices.file', [$notice, 'share']) }}" alt=""
                                     class="rounded border mb-2" style="max-width: 240px; max-height: 126px; object-fit: cover;">
                            @endif
                            <x-admin.form-input name="share_image" label="{{ __('admin.fields.social_share_image') }} ({{ __('admin.common.optional') }})" type="file" accept="image/jpeg,image/png,image/webp"
                                help="{{ __('admin.fields.share_image_help') }}" />
                            @if ($notice->share_image_path)
                                <div class="form-check mb-3">
                                    <input type="checkbox" class="form-check-input" id="remove_share_image" name="remove_share_image" value="1">
                                    <label class="form-check-label" for="remove_share_image">
                                        {{ __('admin.fields.remove_current_share_image') }}
                                        (<a href="{{ route('admin.notices.file', [$notice, 'share']) }}" target="_blank" rel="noopener noreferrer">{{ __('admin.actions.view') }}</a>)
                                    </label>
                                </div>
                            @endif
                        </div>
                    </div>
                </x-admin.card>

                <x-admin.card title="{{ __('admin.fields.action_button') }} ({{ __('admin.common.optional') }})">
                    <div class="row">
                        <div class="col-md-8">
                            <x-admin.form-input name="action_url" label="{{ __('admin.fields.action_link_label') }}" type="url" :value="$notice->action_url" maxlength="500"
                                placeholder="https://" help="{{ __('admin.fields.action_link_help') }}" />
                        </div>
                        <div class="col-md-4">
                            <x-admin.bilingual-field name="action_label" label="{{ __('admin.fields.button_text') }}"
                                :bn-value="$notice->action_label" :en-value="$notice->action_label_en" maxlength="100"
                                placeholder="{{ __('admin.fields.button_text_example_placeholder') }}" />
                        </div>
                    </div>
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card title="{{ __('admin.common.publication') }}">
                    <x-admin.form-select name="status" label="{{ __('admin.common.status') }}" :options="$statuses"
                        :value="$isEdit ? $notice->effectiveStatus() : $notice->status" :placeholder="null" required
                        help="{{ __('admin.fields.publish_permission_help') }}" />
                    <x-admin.form-input name="published_at" label="{{ __('admin.fields.published_at_datetime_label') }}" type="datetime-local"
                        :value="\App\Models\Notice::toLocalInput($notice->published_at)"
                        help="{{ __('admin.fields.published_at_help') }}" />
                    <x-admin.form-input name="expires_at" label="{{ __('admin.fields.expiry_date_label') }} ({{ __('admin.common.optional') }})" type="datetime-local"
                        :value="\App\Models\Notice::toLocalInput($notice->expires_at)"
                        help="{{ __('admin.fields.expiry_help') }}" />
                    <div class="form-check mb-0">
                        <input type="checkbox" class="form-check-input" id="is_pinned" name="is_pinned" value="1"
                               @checked(old('is_pinned', $notice->is_pinned))>
                        <label class="form-check-label" for="is_pinned">{{ __('admin.fields.pin_to_top_label') }}</label>
                    </div>
                </x-admin.card>

                <x-admin.card title="{{ __('admin.fields.organizational_info') }}">
                    <x-admin.form-select name="organization_unit_id" label="{{ __('admin.fields.unit') }}" :options="$units"
                        :value="$notice->organization_unit_id" placeholder="{{ __('admin.filters.none_specific') }}" />
                </x-admin.card>

                @if ($isEdit && $notice->jobPosting)
                    <x-admin.card title="{{ __('admin.fields.linked_job_posting') }}">
                        <p class="fs-13 mb-2">
                            <a href="{{ route('admin.recruitment.show', $notice->jobPosting) }}" class="fw-semibold">{{ $notice->jobPosting->title }}</a>
                        </p>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="syncs_from_job_posting" name="syncs_from_job_posting" value="1"
                                   aria-describedby="syncs-help" @checked(old('syncs_from_job_posting', $notice->syncs_from_job_posting))>
                            <label class="form-check-label" for="syncs_from_job_posting">{{ __('admin.fields.sync_checkbox_label') }}</label>
                        </div>
                        <div class="form-text" id="syncs-help">
                            {{ __('admin.fields.sync_detailed_help') }}
                        </div>
                    </x-admin.card>
                @endif

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? __('admin.actions.update') : __('admin.actions.save') }}
                    </button>
                    <a href="{{ $isEdit ? route('admin.notices.show', $notice) : route('admin.notices.index') }}" class="btn btn-light">{{ __('admin.actions.cancel') }}</a>
                </div>
            </div>
        </div>
    </form>
@endsection
