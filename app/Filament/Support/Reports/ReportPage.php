<?php

namespace App\Filament\Support\Reports;

use App\Support\Csv;
use App\Support\Period;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A report in either panel: a table with a period filter, summary figures
 * over everything the filters match (not just the page shown), and a CSV
 * download of the same rows.
 */
abstract class ReportPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.reports.page';

    /** The CSV file's name before the period, e.g. "sales". */
    abstract protected function csvName(): string;

    /** @return list<string> */
    abstract protected function csvHeader(): array;

    /** @return array<int, scalar|null> */
    abstract protected function csvRow(Model $record): array;

    /**
     * Figures over the filtered rows, shown above the table.
     *
     * @return array<string, string>
     */
    abstract public function summary(): array;

    public static function getNavigationGroup(): ?string
    {
        return __('Reports');
    }

    /** The period filter on a date column, opening on the last 30 days. */
    protected static function periodFilter(string $column): Filter
    {
        return Filter::make('period')
            ->schema([
                DatePicker::make('from')->label(__('From'))->default(Period::startDate(30))->native(false)->displayFormat('j M Y'),
                DatePicker::make('until')->label(__('Until'))->native(false)->displayFormat('j M Y'),
            ])
            ->columns(2)
            ->query(fn (Builder $query, array $data) => Period::fromDates($data['from'] ?? null, $data['until'] ?? null)->apply($query, $column))
            ->indicateUsing(function (array $data): ?string {
                $from = filled($data['from'] ?? null) ? (string) $data['from'] : null;
                $until = filled($data['until'] ?? null) ? (string) $data['until'] : null;

                return match (true) {
                    $from && $until => __(':from to :until', ['from' => $from, 'until' => $until]),
                    (bool) $from => __('From :date', ['date' => $from]),
                    (bool) $until => __('Until :date', ['date' => $until]),
                    default => null,
                };
            });
    }

    /**
     * The filtered rows, without the table's eager loads or sort (for sums).
     *
     * @return Builder<Model>
     */
    protected function filteredQuery(): Builder
    {
        $query = $this->getFilteredTableQuery();

        return ($query ?? $this->getTable()->getQuery())->reorder()->setEagerLoads([]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('Download CSV'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn () => $this->export()),
        ];
    }

    public function export(): StreamedResponse
    {
        $query = $this->getFilteredSortedTableQuery() ?? $this->getTable()->getQuery();
        $period = $this->tableFilters['period'] ?? [];

        return Csv::download(
            Csv::filename($this->csvName(), $period['from'] ?? null, $period['until'] ?? null),
            $this->csvHeader(),
            (function () use ($query) {
                foreach ($query->lazy(500) as $record) {
                    yield $this->csvRow($record);
                }
            })(),
        );
    }
}
