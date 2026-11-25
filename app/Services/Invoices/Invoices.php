<?php

namespace App\Services\Invoices;

use App\Enums\InvoiceKind;
use App\Enums\SystemAccount;
use App\Models\Auction;
use App\Models\Company;
use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Order;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issues the invoices and receipts, inside the caller's transaction and
 * after its ledger posting, so a document exists exactly when the money
 * moved. One document per kind and subject: issuing again returns the
 * first. Numbers run without gaps per kind and calendar year (display time
 * zone); the sequence row is the last lock taken.
 *
 * The VAT shown is read from the posting's tax-payable entries, so the
 * document and the ledger always agree.
 */
final class Invoices
{
    /** The buyer's receipt for a deposit that was credited. */
    public function deposit(Deposit $deposit, LedgerTransaction $transaction): Invoice
    {
        $buyer = User::query()->withTrashed()->findOrFail($deposit->user_id);
        $charges = $deposit->gateway_charge->plus($deposit->service_charge);

        return $this->issue(InvoiceKind::Deposit, $deposit, $this->forBuyer($buyer), [
            [__('Paid through :gateway (:reference)', ['gateway' => ucfirst($deposit->gateway), 'reference' => $deposit->tran_id]), $deposit->amount],
            [__('Gateway charge'), $deposit->gateway_charge->negated()],
            [__('Service charge'), $deposit->service_charge->negated()],
        ], $deposit->net_amount, $charges, $transaction);
    }

    /** The receipt for a paid withdrawal, to the buyer or the company. */
    public function withdrawal(WithdrawalRequest $withdrawal, LedgerTransaction $transaction): Invoice
    {
        $wallet = Wallet::query()->findOrFail($withdrawal->wallet_id);
        $charges = $withdrawal->gateway_charge->plus($withdrawal->service_charge);

        return $this->issue(InvoiceKind::Withdrawal, $withdrawal, $this->forWallet($wallet), [
            [__('Withdrawal to :destination (reference :reference)', ['destination' => $withdrawal->destinationSummary(), 'reference' => (string) $withdrawal->payout_reference]), $withdrawal->amount],
            [__('Gateway charge'), $withdrawal->gateway_charge->negated()],
            [__('Service charge'), $withdrawal->service_charge->negated()],
        ], $withdrawal->net_amount, $charges, $transaction);
    }

    /** The company's invoice for a paid plan invoice. */
    public function subscription(SubscriptionInvoice $invoice, LedgerTransaction $transaction): Invoice
    {
        $invoice->loadMissing('plan');
        $company = Company::query()->withTrashed()->findOrFail($invoice->company_id);
        $zone = (string) config('auction.display_timezone');

        return $this->issue(InvoiceKind::Subscription, $invoice, $this->forCompany($company), [
            [__(':plan plan, :from to :to (plan invoice :number)', [
                'plan' => $invoice->plan->name,
                'from' => $invoice->period_start->setTimezone($zone)->format('j M Y'),
                'to' => $invoice->period_end->setTimezone($zone)->format('j M Y'),
                'number' => (string) $invoice->number,
            ]), $invoice->amount->plus($invoice->discount)],
            [__('Coupon discount'), $invoice->discount->negated()],
        ], $invoice->amount, $invoice->amount, $transaction);
    }

    /**
     * An order's escrow was settled with something kept: the buyer's
     * purchase receipt and the company's commission invoice.
     */
    public function settlement(Order $order, LedgerTransaction $transaction): void
    {
        $title = (string) DB::table('auctions')->where('id', $order->auction_id)->value('title');
        $buyer = User::query()->withTrashed()->findOrFail($order->buyer_id);
        $company = Company::query()->withTrashed()->findOrFail($order->company_id);
        $kept = $order->paid()->minus($order->refunded_amount);

        $billed = $this->forBuyer($buyer);
        $billed['billed_name'] = $order->shipping_name ?: $billed['billed_name'];
        $billed['billed_address'] = $order->shipping_address ?: $billed['billed_address'];

        $this->issue(InvoiceKind::Purchase, $order, $billed, [
            [__(':title (order :number), sold by :seller', ['title' => $title, 'number' => $order->number, 'seller' => $company->name]), $order->amount],
            [__('Seller discount'), $order->discount->negated()],
            [__('Refunded'), $order->refunded_amount->negated()],
        ], $kept, Money::zero($kept->currency), null);

        $this->issue(InvoiceKind::Commission, $order, $this->forCompany($company), [
            [__('Sale of :title (order :number)', ['title' => $title, 'number' => $order->number]), $order->amount],
            [__('Coupon discount you gave'), $order->discount->negated()],
            [__('Refunded to the buyer'), $order->refunded_amount->negated()],
            [__('Platform commission'), $order->commission->negated()],
        ], $order->seller_payout, $order->commission, $transaction);
    }

    /** The company's invoice for featuring an auction. */
    public function featured(Auction $auction, Money $fee, Money $discount, LedgerTransaction $transaction): Invoice
    {
        $company = Company::query()->withTrashed()->findOrFail($auction->company_id);
        $charged = $fee->minus($discount);

        return $this->issue(InvoiceKind::Featured, $auction, $this->forCompany($company), [
            [__('Featured placement for “:title” (auction :id)', ['title' => $auction->title, 'id' => $auction->id]), $fee],
            [__('Coupon discount'), $discount->negated()],
        ], $charged, $charged, $transaction);
    }

    /** The notice for a fine taken from a wallet. */
    public function fine(Model $subject, Wallet $wallet, Money $amount, string $reason, LedgerTransaction $transaction): Invoice
    {
        return $this->issue(InvoiceKind::Fine, $subject, $this->forWallet($wallet), [
            [__('Fine: :reason', ['reason' => $reason]), $amount],
        ], $amount, Money::zero($amount->currency), $transaction);
    }

    /** The PDF as a download response. */
    public function download(Invoice $invoice): Response
    {
        return Pdf::loadView('invoices.pdf', [
            'invoice' => $invoice,
            'issuer' => config('auction.issuer'),
            'vatNumber' => config('auction.tax.vat_number'),
        ])->setPaper('a4')->download($invoice->fileName());
    }

    /**
     * @param  array{user_id: int|null, company_id: int|null, billed_name: string, billed_email: string|null, billed_address: string|null}  $billed
     * @param  list<array{0: string, 1: Money}>  $lines
     */
    private function issue(InvoiceKind $kind, Model $subject, array $billed, array $lines, Money $total, Money $taxable, ?LedgerTransaction $transaction): Invoice
    {
        $existing = Invoice::query()
            ->where('kind', $kind)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $issued = now();

        $invoice = new Invoice;
        $invoice->forceFill($billed + [
            'kind' => $kind,
            'number' => $this->nextNumber($kind, (int) $issued->setTimezone((string) config('auction.display_timezone'))->year),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'currency' => $total->currency,
            'lines' => array_values(array_map(
                fn (array $line): array => ['description' => $line[0], 'amount' => $line[1]->amount],
                array_filter($lines, fn (array $line) => ! $line[1]->isZero()),
            )),
            'total' => $total,
            'taxable' => $taxable,
            'tax_rate_bps' => (int) ($transaction?->meta['vat_bps'] ?? 0),
            'tax' => $this->vatIn($transaction, $total->currency),
            'ledger_transaction_id' => $transaction?->id,
            'issued_at' => $issued,
        ])->save();

        return $invoice;
    }

    private function nextNumber(InvoiceKind $kind, int $year): string
    {
        DB::table('invoice_sequences')->insertOrIgnore(['series' => $kind->series(), 'year' => $year, 'last_number' => 0]);

        $row = DB::table('invoice_sequences')->where('series', $kind->series())->where('year', $year)->lockForUpdate()->first(['id', 'last_number']);
        $next = (int) $row->last_number + 1;
        DB::table('invoice_sequences')->where('id', $row->id)->update(['last_number' => $next]);

        return sprintf('%s-%d-%06d', $kind->series(), $year, $next);
    }

    private function vatIn(?LedgerTransaction $transaction, string $currency): Money
    {
        if ($transaction === null) {
            return Money::zero($currency);
        }

        $sum = (int) LedgerEntry::query()
            ->where('ledger_transaction_id', $transaction->id)
            ->where('wallet_id', Wallet::forSystem(SystemAccount::TaxPayable)->id)
            ->sum('amount');

        return Money::minor($sum, $currency);
    }

    /** @return array{user_id: int|null, company_id: int|null, billed_name: string, billed_email: string|null, billed_address: string|null} */
    private function forWallet(Wallet $wallet): array
    {
        return $wallet->company_id !== null
            ? $this->forCompany(Company::query()->withTrashed()->findOrFail($wallet->company_id))
            : $this->forBuyer(User::query()->withTrashed()->findOrFail($wallet->user_id));
    }

    /** @return array{user_id: int|null, company_id: int|null, billed_name: string, billed_email: string|null, billed_address: string|null} */
    private function forBuyer(User $buyer): array
    {
        return ['user_id' => $buyer->id, 'company_id' => null, 'billed_name' => $buyer->name, 'billed_email' => $buyer->email, 'billed_address' => $buyer->address];
    }

    /** @return array{user_id: int|null, company_id: int|null, billed_name: string, billed_email: string|null, billed_address: string|null} */
    private function forCompany(Company $company): array
    {
        return ['user_id' => null, 'company_id' => $company->id, 'billed_name' => $company->legal_name ?: $company->name, 'billed_email' => $company->email, 'billed_address' => $company->address];
    }
}
