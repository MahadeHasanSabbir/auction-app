@props(['status'])

{{-- An order status badge for the site; the panels use OrderStatus::color() directly. --}}
<x-badge :color="match ($status->color()) {
    'warning' => 'amber',
    'info' => 'blue',
    'success' => 'green',
    'danger' => 'red',
    default => 'slate',
}" {{ $attributes }}>{{ $status->label() }}</x-badge>
