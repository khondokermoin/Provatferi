@php
    // Icon + text accompany the colour so status is never colour-only.
    $flashes = [
        'success' => ['class' => 'alert-success', 'icon' => 'ti-circle-check', 'label' => __('admin.flash.success')],
        'error' => ['class' => 'alert-danger', 'icon' => 'ti-alert-circle', 'label' => __('admin.flash.error')],
        'warning' => ['class' => 'alert-warning', 'icon' => 'ti-alert-triangle', 'label' => __('admin.flash.warning')],
        'status' => ['class' => 'alert-info', 'icon' => 'ti-info-circle', 'label' => __('admin.flash.info')],
    ];
@endphp

@foreach ($flashes as $key => $meta)
    @if (session($key))
        <div class="alert {{ $meta['class'] }} d-flex align-items-start gap-2 alert-dismissible fade show" role="alert">
            <i class="ti {{ $meta['icon'] }} fs-18 mt-1 flex-shrink-0" aria-hidden="true"></i>
            <div>
                <span class="visually-hidden">{{ $meta['label'] }}:</span>
                {{ session($key) }}
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('admin.actions.close') }}"></button>
        </div>
    @endif
@endforeach

@if ($errors->any() && ! $errors->has('email'))
    <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
        <i class="ti ti-alert-circle fs-18 mt-1 flex-shrink-0" aria-hidden="true"></i>
        <div>
            <strong>{{ trans_choice('admin.forms.error_count', $errors->count(), ['count' => bn_number($errors->count())]) }}</strong>
            <span class="visually-hidden">{{ __('admin.forms.fix_errors') }}</span>
            <ul class="mb-0 mt-1 ps-3">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
