@props(['membership', 'size' => null])

{{-- The member's photo for admins: the PRIVATE photo (a resized copy of the application photo, streamed by an
     admin-only route), else the live, admin-approved public-profile photo, else the person's initials. Decorative
     (alt="") — the name is always written next to it. --}}
@php
    $person = $membership->member;
    $name = $membership->holderName();
    $src = null;
    if ($person?->photo_path) {
        $src = route('admin.membership.members.photo', ['membership' => $membership, 'v' => $person->updated_at?->timestamp]);
    } elseif ($person) {
        $live = $person->relationLoaded('liveProfileVersion') ? $person->liveProfileVersion->first() : $person->liveProfileVersion()->first();
        $src = $live?->photo_approved_path ? app(\App\Services\PhotoUploadService::class)->publicUrl($live->photo_approved_path) : null;
    }
    $initials = collect(preg_split('/\s+/u', trim($name)) ?: [])->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    $classes = 'pf-avatar'.($size === 'lg' ? ' pf-avatar-lg' : '');
@endphp

@if ($src)
    <img src="{{ $src }}" alt="" loading="lazy" decoding="async" {{ $attributes->merge(['class' => $classes]) }}>
@else
    <span {{ $attributes->merge(['class' => $classes]) }} aria-hidden="true">{{ $initials !== '' ? $initials : '?' }}</span>
@endif
