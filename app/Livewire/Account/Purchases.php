<?php

namespace App\Livewire\Account;

use App\Models\Order;
use App\Models\User;
use App\Support\Csv;
use App\Support\Home;
use App\Support\Money;
use App\Support\Period;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A buyer's purchase report: what they bought in a period, what they paid,
 * what came back as refunds, with a CSV download of the same rows.
 */
#[Layout('layouts.site')]
class Purchases extends Component
{
    use WithPagination;

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    public function mount(): void
    {
        $user = $this->user();

        if (! $user->isBuyer()) {
            $this->redirect(Home::pathFor($user));
        }

        if (! self::isDate($this->from)) {
            $this->from = Period::startDate(365);
        }

        if (! self::isDate($this->until)) {
            $this->until = '';
        }
    }

    public function updatedFrom(): void
    {
        $this->from = self::isDate($this->from) ? $this->from : '';
        $this->resetPage();
    }

    public function updatedUntil(): void
    {
        $this->until = self::isDate($this->until) ? $this->until : '';
        $this->resetPage();
    }

    public function export(): StreamedResponse
    {
        $orders = $this->orders()->oldest('created_at')->oldest('id');
        $zone = (string) config('auction.display_timezone');

        return Csv::download(
            Csv::filename('purchases', $this->from ?: null, $this->until ?: null),
            [__('Date'), __('Order'), __('Item'), __('Seller'), __('Status'), __('Price'), __('Buyer fee'), __('Refunded'), __('Paid'), __('Currency')],
            (function () use ($orders, $zone) {
                foreach ($orders->lazy(500) as $order) {
                    yield [
                        $order->created_at?->setTimezone($zone)->format('Y-m-d H:i'),
                        $order->number,
                        $order->auction?->title,
                        $order->company?->name,
                        $order->status->label(),
                        $order->amount->toDecimal(),
                        $order->buyer_fee->toDecimal(),
                        $order->refunded_amount->toDecimal(),
                        self::paid($order)->toDecimal(),
                        $order->amount->currency,
                    ];
                }
            })(),
        );
    }

    /** What a purchase cost the buyer in the end: price and fee, less refunds. */
    public static function paid(Order $order): Money
    {
        return $order->amount->plus($order->buyer_fee)->minus($order->refunded_amount);
    }

    public function render(): View
    {
        $totals = $this->orders()->toBase()
            ->selectRaw('count(*) as orders, coalesce(sum(amount + buyer_fee), 0) as spent, coalesce(sum(refunded_amount), 0) as refunded')
            ->first();

        $spent = (int) ($totals->spent ?? 0);
        $refunded = (int) ($totals->refunded ?? 0);

        return view('livewire.account.purchases', [
            'orders' => $this->orders()->latest('id')->paginate(20),
            'totals' => [
                __('Purchases') => number_format((int) ($totals->orders ?? 0)),
                __('Spent') => Money::minor($spent)->format(),
                __('Refunded') => Money::minor($refunded)->format(),
                __('Paid in the end') => Money::minor($spent - $refunded)->format(),
            ],
        ])->title(__('Purchases'));
    }

    /** @return Builder<Order> */
    private function orders(): Builder
    {
        $query = Order::query()
            ->where('buyer_id', $this->user()->id)
            ->with(['auction:id,title', 'company:id,name,slug']);

        return Period::fromDates($this->from ?: null, $this->until ?: null)->apply($query, 'created_at');
    }

    private static function isDate(string $value): bool
    {
        return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private function user(): User
    {
        return Auth::user() ?? abort(401);
    }
}
