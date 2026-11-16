@props(['user', 'size' => 'size-9'])

{{-- A user's picture, or their initial on a brand tint. --}}
@if ($user->avatarUrl())
    <img src="{{ $user->avatarUrl() }}" alt="" {{ $attributes->class([$size, 'rounded-full object-cover']) }}>
@else
    <span {{ $attributes->class([$size, 'inline-flex items-center justify-center rounded-full bg-brand-100 font-semibold text-brand-800 dark:bg-brand-900 dark:text-brand-100']) }} aria-hidden="true">
        {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
    </span>
@endif
