@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'help' => null,
    'required' => false,
    'placeholder' => null,
])

@php
    $id = $attributes->get('id', 'field-'.$name);
    $helpId = $help ? $id.'-help' : null;
    $errorId = $errors->has($name) ? $id.'-error' : null;
    $describedBy = collect([$helpId, $errorId])->filter()->join(' ');
@endphp

<div class="mb-3">
    {{-- Always a real label: placeholders are never used as labels. --}}
    <label class="form-label" for="{{ $id }}">
        {{ $label }}
        @if ($required)
            <span class="pf-required" aria-hidden="true">*</span>
            <span class="visually-hidden">(আবশ্যক)</span>
        @endif
    </label>

    <input type="{{ $type }}"
           id="{{ $id }}"
           name="{{ $name }}"
           value="{{ old($name, $value) }}"
           @if ($placeholder) placeholder="{{ $placeholder }}" @endif
           @if ($required) required @endif
           @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
           @if ($errors->has($name)) aria-invalid="true" @endif
           {{ $attributes->merge(['class' => 'form-control'.($errors->has($name) ? ' is-invalid' : '')]) }}>

    @if ($help)
        <div class="form-text" id="{{ $helpId }}">{{ $help }}</div>
    @endif

    @error($name)
        <div class="invalid-feedback d-block" id="{{ $errorId }}">
            <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}
        </div>
    @enderror
</div>
