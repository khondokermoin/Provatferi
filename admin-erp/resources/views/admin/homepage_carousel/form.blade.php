@extends('layouts.admin')

@section('content')
    @php $isEdit = $slide->exists; @endphp

    <form method="POST" enctype="multipart/form-data"
          action="{{ $isEdit ? route('admin.homepage-carousel.update', $slide) : route('admin.homepage-carousel.store') }}">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        <div class="row">
            <div class="col-lg-8">
                <x-admin.card title="{{ __('admin.fields.slide_image') }}">
                    @if ($isEdit)
                        <div class="mb-3">
                            <span class="form-label d-block">{{ __('admin.fields.current_image') }}</span>
                            <img src="{{ $photos->publicUrl($slide->image_path) }}" alt=""
                                 class="rounded border" style="max-width: 320px; max-height: 180px; object-fit: cover;">
                        </div>
                    @endif
                    <x-admin.form-input name="image" label="{{ __('admin.fields.slide_image') }}"
                        type="file" accept="image/jpeg,image/png,image/webp" :required="! $isEdit"
                        help="{{ $isEdit ? __('admin.fields.replace_image_help').' '.__('admin.fields.slide_image_help') : __('admin.fields.slide_image_help') }}" />

                    <x-admin.bilingual-field name="title" label="{{ __('admin.fields.slide_heading') }}"
                        :bn-value="$slide->title" :en-value="$slide->title_en" />

                    <x-admin.bilingual-field name="alt_text" label="{{ __('admin.fields.slide_alt_text') }}"
                        :bn-value="$slide->alt_text" :en-value="$slide->alt_text_en" />
                </x-admin.card>

                <x-admin.card title="{{ __('admin.fields.link_url') }}">
                    <x-admin.form-input name="link_url" label="{{ __('admin.fields.link_url') }}" type="url"
                        :value="$slide->link_url" placeholder="https://" />

                    <x-admin.bilingual-field name="link_label" label="{{ __('admin.fields.link_label') }}"
                        :bn-value="$slide->link_label" :en-value="$slide->link_label_en" />
                </x-admin.card>
            </div>

            <div class="col-lg-4">
                <x-admin.card title="{{ __('admin.common.publication') }}">
                    <x-admin.form-select name="status" label="{{ __('admin.common.status') }}" :options="$statuses"
                        :value="$slide->status" :placeholder="null" required />
                    <x-admin.form-input name="sort_order" label="{{ __('admin.common.order') }}" type="number"
                        :value="$slide->sort_order ?? 0" required min="0"
                        help="{{ __('admin.fields.lower_shows_first_help') }}" />
                </x-admin.card>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ $isEdit ? __('admin.actions.update') : __('admin.actions.create') }}
                    </button>
                    <a href="{{ route('admin.homepage-carousel.index') }}" class="btn btn-light">{{ __('admin.actions.cancel') }}</a>
                </div>
            </div>
        </div>
    </form>
@endsection
