@props(['state', 'standing' => false])

{{-- A monthly contribution state as a badge: icon + words alongside the colour (Membership task 4). With `standing` it is
     where a membership stands (MembershipDueLedger::standing()), otherwise the state of one month's due. "not_required"
     (no monthly contribution due) is deliberately neutral — never worded or styled as paid or unpaid. --}}
@php
    $map = [
        'not_required' => ['bg-secondary-subtle text-secondary-emphasis', 'ti-circle-minus'],
        'current' => ['bg-success-subtle text-success-emphasis', 'ti-circle-check'],
        'paid' => ['bg-success-subtle text-success-emphasis', 'ti-cash'],
        'waived' => ['bg-info-subtle text-info-emphasis', 'ti-badge'],
        'partially_paid' => ['bg-warning-subtle text-warning-emphasis', 'ti-clock'],
        'due' => ['bg-primary-subtle text-primary-emphasis', 'ti-calendar-time'],
        'overdue' => ['bg-danger-subtle text-danger-emphasis', 'ti-alert-circle'],
    ];
    [$classes, $icon] = $map[$state] ?? $map['due'];
@endphp

<span {{ $attributes->merge(['class' => "badge $classes d-inline-flex align-items-center gap-1"]) }} data-state="{{ $state }}">
    <i class="ti {{ $icon }}" aria-hidden="true"></i>{{ __(($standing ? 'admin.dues.standing.' : 'admin.dues.state.').$state) }}
</span>
