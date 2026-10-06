@props(['state'])

{{-- A registration-fee state (App\Support\MembershipPaymentState) as a badge: icon + words alongside the colour, like
     status-badge. "not_required" (a zero fee) is deliberately neutral — never styled or worded as a debt. --}}
@php
    $map = [
        'not_required' => ['bg-secondary-subtle text-secondary-emphasis', 'ti-circle-minus'],
        'paid' => ['bg-success-subtle text-success-emphasis', 'ti-cash'],
        'waived' => ['bg-info-subtle text-info-emphasis', 'ti-badge'],
        'awaiting_verification' => ['bg-warning-subtle text-warning-emphasis', 'ti-clock'],
        'unpaid' => ['bg-danger-subtle text-danger-emphasis', 'ti-alert-circle'],
        'no_quote' => ['bg-secondary-subtle text-secondary-emphasis', 'ti-help-circle'],
    ];
    [$classes, $icon] = $map[$state] ?? $map['no_quote'];
@endphp

<span {{ $attributes->merge(['class' => "badge $classes d-inline-flex align-items-center gap-1"]) }}>
    <i class="ti {{ $icon }}" aria-hidden="true"></i>{{ __('admin.registry.payment.'.$state) }}
</span>
