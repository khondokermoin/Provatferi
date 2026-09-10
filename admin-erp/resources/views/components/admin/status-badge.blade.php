@props(['status'])

@php
    // Icon + label alongside colour, so status never depends on colour alone.
    $map = [
        'active' => ['bg-success-subtle text-success-emphasis', 'ti-circle-check'],
        'inactive' => ['bg-secondary-subtle text-secondary-emphasis', 'ti-circle-minus'],
        'pending' => ['bg-warning-subtle text-warning-emphasis', 'ti-clock'],
        'under_review' => ['bg-warning-subtle text-warning-emphasis', 'ti-eye-search'],
        'need_information' => ['bg-warning-subtle text-warning-emphasis', 'ti-help-circle'],
        'approved' => ['bg-success-subtle text-success-emphasis', 'ti-circle-check'],
        'rejected' => ['bg-danger-subtle text-danger-emphasis', 'ti-circle-x'],
        'cancelled' => ['bg-secondary-subtle text-secondary-emphasis', 'ti-ban'],
        'open' => ['bg-success-subtle text-success-emphasis', 'ti-door-exit'],
        'closed' => ['bg-secondary-subtle text-secondary-emphasis', 'ti-lock'],
        'draft' => ['bg-secondary-subtle text-secondary-emphasis', 'ti-pencil'],
        'published' => ['bg-success-subtle text-success-emphasis', 'ti-world'],
        'archived' => ['bg-secondary-subtle text-secondary-emphasis', 'ti-archive'],
        'suspended' => ['bg-warning-subtle text-warning-emphasis', 'ti-player-pause'],
        'expired' => ['bg-danger-subtle text-danger-emphasis', 'ti-calendar-off'],
        'submitted' => ['bg-warning-subtle text-warning-emphasis', 'ti-send'],
        'shortlisted' => ['bg-success-subtle text-success-emphasis', 'ti-star'],
        'selected' => ['bg-success-subtle text-success-emphasis', 'ti-circle-check'],
    ];
    [$classes, $icon] = $map[$status] ?? ['bg-secondary-subtle text-secondary-emphasis', 'ti-point'];
    $label = status_label($status);
@endphp

<span {{ $attributes->merge(['class' => "badge $classes d-inline-flex align-items-center gap-1"]) }}>
    <i class="ti {{ $icon }}" aria-hidden="true"></i>{{ $label }}
</span>
