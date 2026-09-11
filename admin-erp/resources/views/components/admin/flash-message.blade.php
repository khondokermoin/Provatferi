@php
    // Icon + text accompany the colour so status is never colour-only.
    $flashes = [
        'success' => ['class' => 'alert-success', 'icon' => 'ti-circle-check', 'label' => 'সফল'],
        'error' => ['class' => 'alert-danger', 'icon' => 'ti-alert-circle', 'label' => 'ত্রুটি'],
        'warning' => ['class' => 'alert-warning', 'icon' => 'ti-alert-triangle', 'label' => 'সতর্কতা'],
        'status' => ['class' => 'alert-info', 'icon' => 'ti-info-circle', 'label' => 'নোটিশ'],
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
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="বন্ধ করুন"></button>
        </div>
    @endif
@endforeach

@if ($errors->any() && ! $errors->has('email'))
    <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
        <i class="ti ti-alert-circle fs-18 mt-1 flex-shrink-0" aria-hidden="true"></i>
        <div>
            <strong>ফর্মে {{ $errors->count() }}টি সমস্যা পাওয়া গেছে।</strong>
            <span class="visually-hidden">চিহ্নিত ঘরগুলো সংশোধন করুন।</span>
            <ul class="mb-0 mt-1 ps-3">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
