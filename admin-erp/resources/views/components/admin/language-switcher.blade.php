@php
    use App\Support\AdminLocale;

    $current = app()->getLocale();
    $supported = AdminLocale::SUPPORTED;
@endphp

{{--
    Phase 3 — admin UI language switcher.

    A POST form per option rather than links: switching writes a durable
    preference to the admin's own account, so it must not be reachable by a
    prefetch or a crawler, and it carries CSRF like every other write.

    The URL never changes — there is no /en/admin mirror. `back()` in
    LocaleController returns the admin to exactly the page they were on.

    Icon is the existing Tabler subset glyph (ti-language); no emoji, per the
    panel's icon policy. Each option's label is written in its OWN language,
    which is how a language switcher stays usable to someone who cannot read
    the language currently active.
--}}
<div class="topbar-item">
    <div class="dropdown">
        <button class="topbar-link dropdown-toggle drop-arrow-none" type="button"
                data-bs-toggle="dropdown" aria-expanded="false"
                aria-label="{{ __('admin.locale.current', ['language' => AdminLocale::label($current)]) }}">
            <i class="ti ti-language fs-22" aria-hidden="true"></i>
        </button>
        <div class="dropdown-menu dropdown-menu-end" role="group" aria-label="{{ __('admin.locale.choose') }}">
            @foreach ($supported as $code => $label)
                <form method="POST" action="{{ route('locale.update') }}" class="d-block">
                    @csrf
                    <input type="hidden" name="locale" value="{{ $code }}">
                    <button type="submit"
                            class="dropdown-item {{ $code === $current ? 'active' : '' }}"
                            @if ($code === $current) aria-current="true" @endif
                            lang="{{ $code }}">
                        <i class="ti {{ $code === $current ? 'ti-check' : 'ti-point' }} me-1 fs-17 align-middle" aria-hidden="true"></i>
                        <span class="align-middle">{{ $label }}</span>
                    </button>
                </form>
            @endforeach
        </div>
    </div>
</div>
