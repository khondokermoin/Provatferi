@props([
    'title' => 'কোনো তথ্য নেই',
    'message' => null,
    'icon' => 'ti-inbox',
    'colspan' => null,
])

{{-- Wraps itself in a table row when $colspan is given, so the same component
     works both inside a <tbody> and standalone. --}}
@if ($colspan)<tr><td colspan="{{ $colspan }}" class="text-center py-5">@else<div class="text-center py-5">@endif

    <i class="ti {{ $icon }} fs-1 text-muted d-block mb-2" aria-hidden="true"></i>
    <h3 class="fs-15 mb-1">{{ $title }}</h3>
    @if ($message)
        <p class="text-muted fs-13 mb-3">{{ $message }}</p>
    @endif
    @if (trim($slot) !== '')
        <div class="d-flex justify-content-center gap-2">{{ $slot }}</div>
    @endif

@if ($colspan)</td></tr>@else</div>@endif
