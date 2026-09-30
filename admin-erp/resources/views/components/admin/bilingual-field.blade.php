@props([
    'as' => 'input',
    'type' => 'text',
    'name',
    'label',
    'bnValue' => null,
    'enValue' => null,
    'rows' => 4,
    'required' => false,
    'help' => null,
])

{{--
    Phase 3: the reusable [বাংলা] [English] tabbed editor for every `_en`
    content pair, replacing the old stacked "field" / "field (English)"
    input layout (see git history for the pre-Phase-3 pattern still used
    by a handful of not-yet-migrated forms).

    - Both language inputs are always present in the DOM — Bootstrap's tab
      plugin only toggles `display` via `.active`/`.show`, so switching tabs
      can never lose what an admin typed on the other one.
    - A failed submit opens whichever tab actually has the validation error
      (falling back to Bangla when neither or both do), so an error can
      never be silently hidden behind the inactive tab.
    - The English tab gets a non-colour-only "missing translation" mark
      (icon + visually-hidden text, matching flash-message.blade.php's own
      "icon + text accompanies colour" rule) whenever it's empty, since
      English is always optional here — see admin.bilingual.optional_note.
    - data-bs-toggle="tab" on a proper role="tablist"/role="tab" structure
      gets Bootstrap's native arrow-key/Home/End tab navigation for free,
      the same plugin already used for this theme's modals/dropdowns.
--}}

@php
    $bnName = $name;
    $enName = $name.'_en';
    $uid = $attributes->get('id') ?: 'bf-'.str_replace(['[', ']'], ['-', ''], $name);

    $bnTabId = $uid.'-bn-tab';
    $enTabId = $uid.'-en-tab';
    $bnPaneId = $uid.'-bn-pane';
    $enPaneId = $uid.'-en-pane';

    $bnHasError = $errors->has($bnName);
    $enHasError = $errors->has($enName);

    $resolvedEnValue = old($enName, $enValue);
    $enIsMissing = trim((string) $resolvedEnValue) === '';

    // Bangla is the default tab, EXCEPT when only the English side failed
    // validation — that tab must open so the error is never hidden.
    $enIsActive = $enHasError && ! $bnHasError;
    $bnIsActive = ! $enIsActive;

    $sharedAttributes = $attributes->except(['id']);
@endphp

<div class="mb-3 pf-bilingual-field">
    <span class="form-label d-block mb-1">
        {{ $label }}
        @if ($required)
            <span class="pf-required" aria-hidden="true">*</span>
            <span class="visually-hidden">{{ __('admin.forms.required_marker') }}</span>
        @endif
    </span>

    <ul class="nav nav-tabs pf-bilingual-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link{{ $bnIsActive ? ' active' : '' }}"
                    id="{{ $bnTabId }}"
                    data-bs-toggle="tab"
                    data-bs-target="#{{ $bnPaneId }}"
                    type="button"
                    role="tab"
                    aria-controls="{{ $bnPaneId }}"
                    aria-selected="{{ $bnIsActive ? 'true' : 'false' }}">
                {{ __('admin.bilingual.bn_tab') }}
                @if ($bnHasError)
                    <i class="ti ti-alert-circle text-danger ms-1" aria-hidden="true"></i>
                    <span class="visually-hidden">{{ __('admin.bilingual.tab_has_error') }}</span>
                @endif
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link{{ $enIsActive ? ' active' : '' }}"
                    id="{{ $enTabId }}"
                    data-bs-toggle="tab"
                    data-bs-target="#{{ $enPaneId }}"
                    type="button"
                    role="tab"
                    aria-controls="{{ $enPaneId }}"
                    aria-selected="{{ $enIsActive ? 'true' : 'false' }}">
                {{ __('admin.bilingual.en_tab') }}
                @if ($enHasError)
                    <i class="ti ti-alert-circle text-danger ms-1" aria-hidden="true"></i>
                    <span class="visually-hidden">{{ __('admin.bilingual.tab_has_error') }}</span>
                @elseif ($enIsMissing)
                    <i class="ti ti-alert-triangle text-warning ms-1" aria-hidden="true"></i>
                    <span class="visually-hidden">{{ __('admin.bilingual.en_missing') }}</span>
                @endif
            </button>
        </li>
    </ul>

    <div class="tab-content pf-bilingual-tab-content border border-top-0 rounded-bottom p-3">
        <div class="tab-pane fade{{ $bnIsActive ? ' show active' : '' }}"
             id="{{ $bnPaneId }}" role="tabpanel" aria-labelledby="{{ $bnTabId }}">
            @if ($as === 'textarea')
                <textarea id="{{ $uid }}-bn"
                          name="{{ $bnName }}"
                          rows="{{ $rows }}"
                          @if ($required) required @endif
                          @if ($bnHasError) aria-invalid="true" @endif
                          {{ $sharedAttributes->merge(['class' => 'form-control'.($bnHasError ? ' is-invalid' : '')]) }}>{{ old($bnName, $bnValue) }}</textarea>
            @else
                <input type="{{ $type }}"
                       id="{{ $uid }}-bn"
                       name="{{ $bnName }}"
                       value="{{ old($bnName, $bnValue) }}"
                       @if ($required) required @endif
                       @if ($bnHasError) aria-invalid="true" @endif
                       {{ $sharedAttributes->merge(['class' => 'form-control'.($bnHasError ? ' is-invalid' : '')]) }}>
            @endif
            @error($bnName)
                <div class="invalid-feedback d-block"><i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}</div>
            @enderror
        </div>
        <div class="tab-pane fade{{ $enIsActive ? ' show active' : '' }}"
             id="{{ $enPaneId }}" role="tabpanel" aria-labelledby="{{ $enTabId }}">
            @if ($as === 'textarea')
                <textarea id="{{ $uid }}-en"
                          name="{{ $enName }}"
                          rows="{{ $rows }}"
                          @if ($enHasError) aria-invalid="true" @endif
                          {{ $sharedAttributes->merge(['class' => 'form-control'.($enHasError ? ' is-invalid' : '')]) }}>{{ old($enName, $enValue) }}</textarea>
            @else
                <input type="{{ $type }}"
                       id="{{ $uid }}-en"
                       name="{{ $enName }}"
                       value="{{ old($enName, $enValue) }}"
                       @if ($enHasError) aria-invalid="true" @endif
                       {{ $sharedAttributes->merge(['class' => 'form-control'.($enHasError ? ' is-invalid' : '')]) }}>
            @endif
            @error($enName)
                <div class="invalid-feedback d-block"><i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}</div>
            @enderror
            @if ($enIsMissing && ! $enHasError)
                <div class="form-text pf-bilingual-missing-hint">
                    <i class="ti ti-info-circle" aria-hidden="true"></i> {{ __('admin.bilingual.en_missing_hint') }}
                </div>
            @endif
        </div>
    </div>

    @if ($help)
        <div class="form-text">{{ $help }}</div>
    @endif
</div>
