@props([
    'name',
    'label',
    'options' => [],
    'value' => null,
    'help' => null,
    'required' => false,
    'placeholder' => '— নির্বাচন করুন —',
])

@php
    $id = $attributes->get('id', 'field-'.$name);
    $helpId = $help ? $id.'-help' : null;
    $errorId = $errors->has($name) ? $id.'-error' : null;
    $describedBy = collect([$helpId, $errorId])->filter()->join(' ');
    $selected = old($name, $value);
@endphp

<div class="mb-3">
    <label class="form-label" for="{{ $id }}">
        {{ $label }}
        @if ($required)
            <span class="pf-required" aria-hidden="true">*</span>
            <span class="visually-hidden">(আবশ্যক)</span>
        @endif
    </label>

    <select id="{{ $id }}"
            name="{{ $name }}"
            @if ($required) required @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @if ($errors->has($name)) aria-invalid="true" @endif
            {{ $attributes->merge(['class' => 'form-select'.($errors->has($name) ? ' is-invalid' : '')]) }}>
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected((string) $selected === (string) $optionValue)>{{ $optionLabel }}</option>
        @endforeach
    </select>

    @if ($help)
        <div class="form-text" id="{{ $helpId }}">{{ $help }}</div>
    @endif

    @error($name)
        <div class="invalid-feedback d-block" id="{{ $errorId }}">
            <i class="ti ti-alert-circle" aria-hidden="true"></i> {{ $message }}
        </div>
    @enderror
</div>
