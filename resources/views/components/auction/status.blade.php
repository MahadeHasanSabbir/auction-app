@props(['auction'])

@php
    use App\Enums\AuctionStatus;

    [$color, $pulse] = match ($auction->status) {
        AuctionStatus::Live => ['green', true],
        AuctionStatus::Scheduled => ['amber', false],
        AuctionStatus::Sold => ['brand', false],
        default => ['slate', false],
    };
@endphp

<x-badge :color="$color" :pulse="$pulse" {{ $attributes }}>{{ $auction->status->label() }}</x-badge>
