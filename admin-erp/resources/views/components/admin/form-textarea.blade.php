@props([
    'name',
    'label',
    'value' => null,
    'help' => null,
    'required' => false,
    'rows' => 4,
])

@php
    $id = $attributes->get('id', 'field-'.$name);
    $helpId = $help ? $id.'-help' : null;
    $errorId = $errors->has($name) ? $id.'-error' : null;
    $describedBy = collect([$helpId, $errorId])->filter()->join(' ');
@endphp

<div class="mb-3">
    <label class="form-label" for="{{ $id }}">
        {{ $label }}
        @if ($required)
            <span class="pf-required" aria-hidden="true">*</span>
            <span class="visually-hidden">(আবশ্যক)</span>
        @endif
    </label>

    <textarea id="{{ $id }}"
              name="{{ $name }}"
              rows="{{ $rows }}"
              @if ($required) required @endif
              @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
              @if ($errors->has($name)) aria-invalid="true" @endif
              {{ $attributes->merge(['class' => 'form-control'.($errors->has($name) ? ' is-invalid' : '')]) }}>{{ old($name, $value) }}</textarea>

    @if ($help)
        <div class="form-text" id="{{ $helpId }}">{{ $help }}</div>
    @endif

    @error($name)
        <div class="invalid-feedback d-block" id="{{ $errorId }}">
            <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}
        </div>
    @enderror
</div>
