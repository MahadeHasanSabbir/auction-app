@props([
    'label',
    'name',
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'bag' => 'default',
])

{{-- A labelled text input with its hint and validation error. --}}
@php
    $id = $attributes->get('id', $name);
    $fieldErrors = $errors->getBag($bag);
@endphp

<div>
    <label for="{{ $id }}" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ $label }}</label>

    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
           @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
           @if ($fieldErrors->has($name)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
           {{ $attributes->except('id')->class(['form-control mt-1.5', 'border-red-500 dark:border-red-500' => $fieldErrors->has($name)]) }}>

    @if ($hint)
        <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">{{ $hint }}</p>
    @endif

    @error($name, $bag)
        <p id="{{ $id }}-error" class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
    @enderror
</div>
