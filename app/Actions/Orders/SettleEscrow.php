<?php

namespace App\Actions\Orders;

use App\Enums\LedgerComponent;
use App\Enums\LedgerTransactionType;
use App\Enums\SystemAccount;
use App\Models\Auction;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Fees\CommissionTerms;
use App\Services\Invoices\Invoices;
use App\Services\Ledger\Ledger;
use App\Support\Money;
use App\Support\Vat;
use LogicException;

/**
 * Empties an order's escrow (the price less any seller-coupon discount),
 * once: $refund goes back to the buyer, the rest
 * to the company's wallet minus the commission, which shrinks in proportion
 * to what the company keeps (a full refund earns the platform nothing).
 *
 * The commission includes VAT, which the ledger moves to tax payable and
 * the order records in `tax`. When something was kept, the buyer gets a
 * purchase receipt and the company a commission invoice.
 *
 * Runs inside the caller's transaction with the order row already locked;
 * the caller moves the order to its final status.
 */
final class SettleEscrow
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Invoices $invoices,
    ) {}

    public function handle(Order $order, Money $refund, ?User $by, string $description): void
    {
        $paid = $order->paid();

        if ($refund->isNegative() || $refund->greaterThan($paid) || ! $order->refunded_amount->isZero()) {
            throw new LogicException("Order {$order->id} cannot refund {$refund->amount}.");
        }

        $kept = $paid->minus($refund);
        $commission = $kept->isZero() ? Money::zero($kept->currency) : $order->commission->share($kept->amount, $paid->amount);
        // Bonus credit paid with comes back as bonus credit first.
        $bonus = Money::minor(min($order->bonus_amount->amount, $refund->amount), $refund->currency);
        $payout = $kept->minus($commission);

        $transaction = $this->ledger->transaction($kept->isZero() ? LedgerTransactionType::Refund : LedgerTransactionType::EscrowRelease)
            ->reference($order)
            ->idempotent("settle:{$order->id}")
            ->describe($description)
            ->by($by)
            ->debit($this->ledger->system(SystemAccount::Escrow), $paid)
            ->creditBonus(Wallet::query()->where('user_id', $order->buyer_id)->firstOrFail(), $refund, $bonus)
            ->credit(Wallet::query()->where('company_id', $order->company_id)->firstOrFail(), $payout)
            ->creditWithVat($this->ledger->system(SystemAccount::CommissionRevenue), $commission, LedgerComponent::Commission, $this->terms($order))
            ->meta(['refund' => $refund->amount, 'commission' => $commission->amount, 'payout' => $payout->amount]);

        $posted = $transaction->post();

        $order->forceFill([
            'refunded_amount' => $refund,
            'bonus_amount' => 0,
            'commission' => $commission,
            'tax' => Vat::included($commission),
            'seller_payout' => $payout,
        ]);

        if (! $kept->isZero()) {
            $this->invoices->settlement($order, $posted);
        }
    }

    private function terms(Order $order): ?CommissionTerms
    {
        $terms = Auction::query()->find($order->auction_id, ['id', 'fee_snapshot'])?->fee_snapshot['sale_commission'] ?? null;

        return is_array($terms) ? CommissionTerms::fromSnapshot($terms) : null;
    }
}
