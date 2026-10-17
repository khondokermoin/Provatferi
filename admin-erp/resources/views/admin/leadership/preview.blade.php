@extends('layouts.admin')

@section('page-actions')
    @php $siteUrl = rtrim((string) config('services.public_site.url'), '/'); @endphp
    @can('leadership.update')
        <a href="{{ route('admin.leadership.edit', $profile) }}" class="btn btn-primary">
            <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ __('admin.leadership.preview_edit') }}
        </a>
    @endcan
    @if ($profile->isPublic() && $siteUrl !== '')
        <a href="{{ $siteUrl }}/leadership/{{ $profile->slug }}" class="btn btn-light" target="_blank" rel="noopener noreferrer">
            <i class="ti ti-external-link me-1" aria-hidden="true"></i>{{ __('admin.leadership.preview_open_site') }}
        </a>
    @endif
    <a href="{{ route('admin.leadership.index') }}" class="btn btn-light">{{ __('admin.leadership.preview_back') }}</a>
@endsection

@section('content')
    <div class="alert {{ $profile->isPublic() ? 'alert-success' : 'alert-secondary' }} d-flex align-items-start gap-2" role="status">
        <i class="ti {{ $profile->isPublic() ? 'ti-world' : 'ti-eye-off' }} fs-18 mt-1" aria-hidden="true"></i>
        <div class="fs-13">
            <x-admin.status-badge :status="$profile->status" class="me-2" />
            {{ $profile->isPublic() ? __('admin.leadership.preview_public') : __('admin.leadership.preview_hidden') }}
            <span class="d-block text-muted mt-1">{{ __('admin.leadership.preview_note') }}</span>
        </div>
    </div>

    <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="preview-bn-tab" data-bs-toggle="tab" data-bs-target="#preview-bn" type="button"
                    role="tab" aria-controls="preview-bn" aria-selected="true">{{ __('admin.leadership.preview_bn') }}</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="preview-en-tab" data-bs-toggle="tab" data-bs-target="#preview-en" type="button"
                    role="tab" aria-controls="preview-en" aria-selected="false">{{ __('admin.leadership.preview_en') }}</button>
        </li>
    </ul>
    <div class="tab-content border border-top-0 rounded-bottom p-3 mb-4">
        <div class="tab-pane fade show active" id="preview-bn" role="tabpanel" aria-labelledby="preview-bn-tab">
            @include('admin.leadership._preview', ['profile' => $profile, 'lang' => 'bn'])
        </div>
        <div class="tab-pane fade" id="preview-en" role="tabpanel" aria-labelledby="preview-en-tab">
            @include('admin.leadership._preview', ['profile' => $profile, 'lang' => 'en'])
        </div>
    </div>
@endsection
