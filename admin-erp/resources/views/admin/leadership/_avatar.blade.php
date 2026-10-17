{{--
    A profile's photo for the admin: the card-size copy, else the person's initials. Decorative (alt=""): the name is always
    written next to it. $profile, optional $large.
--}}
@php
    $name = $profile->displayName();
    $initials = collect(preg_split('/\s+/u', trim($name)) ?: [])->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    $classes = 'pf-avatar'.(($large ?? false) ? ' pf-avatar-lg' : '');
    $src = $profile->photoSmUrl();
@endphp

@if ($src)
    <img src="{{ $src }}" alt="" loading="lazy" decoding="async" class="{{ $classes }}">
@else
    <span class="{{ $classes }}" aria-hidden="true">{{ $initials !== '' ? $initials : '?' }}</span>
@endif
