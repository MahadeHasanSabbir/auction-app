<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * A report period: whole days in the display time zone, turned into UTC
 * bounds for the query. Either end may be open.
 */
final readonly class Period
{
    public function __construct(
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $until,
    ) {}

    /** From two Y-m-d strings (as a date picker gives them); blanks are open ends. */
    public static function fromDates(?string $from, ?string $until): self
    {
        $zone = (string) config('auction.display_timezone');
        $parse = fn (?string $date) => filled($date) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $date)
            ? CarbonImmutable::parse(substr((string) $date, 0, 10), $zone)
            : null;

        $start = $parse($from)?->startOfDay();
        $end = $parse($until)?->endOfDay();

        if ($start && $end && $start->greaterThan($end)) {
            [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
        }

        return new self($start?->utc(), $end?->utc());
    }

    /** The last $days days, today included. */
    public static function lastDays(int $days): self
    {
        $today = CarbonImmutable::now((string) config('auction.display_timezone'));

        return new self($today->subDays($days - 1)->startOfDay()->utc(), $today->endOfDay()->utc());
    }

    /** Today minus $days - 1, as Y-m-d in the display time zone (a filter default). */
    public static function startDate(int $days): string
    {
        return CarbonImmutable::now((string) config('auction.display_timezone'))->subDays($days - 1)->toDateString();
    }

    /**
     * @template TQuery of Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function apply(Builder $query, string $column): Builder
    {
        if ($this->from) {
            $query->where($column, '>=', $this->from);
        }

        if ($this->until) {
            $query->where($column, '<=', $this->until);
        }

        return $query;
    }
}
