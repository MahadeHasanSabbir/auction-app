<?php

namespace Database\Seeders;

use App\Actions\Billing\IssueInvoice;
use App\Actions\Wallet\ApproveWithdrawal;
use App\Actions\Wallet\CompleteDeposit;
use App\Actions\Wallet\PayWithdrawal;
use App\Actions\Wallet\RequestWithdrawal;
use App\Enums\AuctionCloseReason;
use App\Enums\AuctionStatus;
use App\Enums\BidType;
use App\Enums\CompanyStatus;
use App\Enums\DepositStatus;
use App\Enums\HoldType;
use App\Enums\InvoiceStatus;
use App\Enums\KycStatus;
use App\Enums\LedgerComponent;
use App\Enums\LedgerTransactionType;
use App\Enums\NoticeAudience;
use App\Enums\OrderStatus;
use App\Enums\PaymentOutcome;
use App\Enums\PayoutMethod;
use App\Enums\ProductCondition;
use App\Enums\ShipmentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\SystemAccount;
use App\Enums\UserRole;
use App\Events\Account\WalletUpdated;
use App\Models\Auction;
use App\Models\AutoBid;
use App\Models\Bid;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyAgreement;
use App\Models\CompanyInvitation;
use App\Models\Deposit;
use App\Models\KycRequest;
use App\Models\Notice;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Review;
use App\Models\Shipment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserType;
use App\Models\Wallet;
use App\Services\Fees\CommissionTerms;
use App\Services\Fees\FeeResolver;
use App\Services\Ledger\Ledger;
use App\Services\Payments\PaymentResult;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Demo data for local work: platform staff, five companies with teams and
 * plans, twelve buyers with funded wallets, and auctions in every status
 * with bids, holds, orders and reviews that add up.
 *
 * Every demo account uses the password "password".
 */
class DemoSeeder extends Seeder
{
    private Ledger $ledger;

    private User $owner;

    private User $staff;

    /** @var list<User> */
    private array $buyers = [];

    /** @var array<int, Wallet> buyer wallets keyed by user id */
    private array $wallets = [];

    /** @var array<string, int> category ids keyed by slug */
    private array $categories = [];

    private int $auctionCount = 0;

    private int $orderCount = 0;

    public function run(): void
    {
        if (Company::query()->exists()) {
            return;
        }

        $this->ledger = app(Ledger::class);
        $this->owner = User::query()->where('role', UserRole::SystemOwner)->firstOrFail();
        $this->categories = Category::query()->pluck('id', 'slug')->all();

        // Demo money moves through the real ledger; nobody is watching, so nothing is broadcast.
        Event::fakeFor(fn () => DB::transaction(function (): void {
            $this->staff();
            $this->buyers();

            foreach ($this->companies() as $definition) {
                $this->company($definition);
            }

            $this->withdrawals();
            $this->notices();
        }), [WalletUpdated::class]);
    }

    /** One withdrawal already paid out and one waiting for staff. */
    private function withdrawals(): void
    {
        $request = app(RequestWithdrawal::class);

        $paid = $request->handle($this->buyers[0], $this->wallets[$this->buyers[0]->id], Money::minor(500_000), PayoutMethod::Bkash, [
            'account_name' => $this->buyers[0]->name,
            'account_number' => '01712345678',
        ]);
        app(ApproveWithdrawal::class)->handle($this->staff, $paid);
        app(PayWithdrawal::class)->handle($this->staff, $paid, 'BKASH-8F3K2L9Q');

        $request->handle($this->buyers[1], $this->wallets[$this->buyers[1]->id], Money::minor(1_200_000), PayoutMethod::Bank, [
            'account_name' => $this->buyers[1]->name,
            'account_number' => '1234567890123',
            'bank_name' => 'Dutch-Bangla Bank',
            'branch' => 'Gulshan',
        ]);
    }

    private function staff(): void
    {
        $this->staff = User::factory()->platformStaff()->create([
            'name' => 'Farhana Akter',
            'email' => 'staff@auction.com',
        ]);

        User::factory()->platformStaff()->create([
            'name' => 'Imran Hossain',
            'email' => 'support@auction.com',
        ]);
    }

    private function buyers(): void
    {
        $type = UserType::query()->where('slug', 'standard-buyer')->value('id');

        $names = [
            'Ayesha Rahman', 'Mahmud Hasan', 'Tasnim Karim', 'Sohel Rana', 'Priya Das', 'Zubair Ahmed',
            'Nadia Sultana', 'Rakib Mia', 'Shirin Akhter', 'Fahim Chowdhury', 'Mou Saha', 'Anik Biswas',
        ];

        foreach ($names as $index => $name) {
            $buyer = User::factory()->create([
                'name' => $name,
                'email' => 'buyer'.($index + 1).'@auction.com',
                'user_type_id' => $type,
                'phone' => '0171'.str_pad((string) (1000000 + $index * 7919), 7, '0', STR_PAD_LEFT),
            ]);

            $this->buyers[] = $buyer;
            $this->wallets[$buyer->id] = Wallet::factory()->create(['user_id' => $buyer->id]);
        }

        // Everyone but the last buyer starts with BDT 300,000; the last one
        // has an empty wallet, to show what a new account looks like.
        foreach (array_slice($this->buyers, 0, -1) as $buyer) {
            $this->deposit($buyer, Money::minor(30_000_000));
        }
    }

    /**
     * Auction rows are [title, category slug, starting price in BDT, scenario, number of bids].
     *
     * @return list<array{name: string, plan: string, status: CompanyStatus, subscription: string, owner: string, members: list<string>, commission_bps: int|null, auctions: list<array{0: string, 1: string, 2: int, 3: string, 4?: int}>}>
     */
    private function companies(): array
    {
        return [
            [
                'name' => 'Dhaka Antiques',
                'plan' => 'business',
                'status' => CompanyStatus::Active,
                'subscription' => 'active',
                'owner' => 'Nusrat Jahan',
                'members' => ['Tanvir Ahmed', 'Sadia Islam', 'Rafiq Uddin'],
                'commission_bps' => 750,
                'auctions' => [
                    ['Mughal-era brass water jug', 'antiques', 12_000, 'live', 6],
                    ['1972 Bangladesh first-issue banknote set', 'coins-and-notes', 8_000, 'live_auto_bid', 4],
                    ['Hand-carved teak writing desk', 'furniture', 45_000, 'scheduled'],
                    ['British India one rupee silver coin, 1917', 'coins-and-notes', 3_500, 'sold_completed', 7],
                    ['Vintage Favre-Leuba wrist watch', 'watches', 18_000, 'sold_in_escrow', 5],
                    ['Colonial-era Dhaka street map, framed', 'antiques', 15_000, 'unsold_reserve', 2],
                    ['Nakshi kantha wall hanging', 'textiles', 6_000, 'draft'],
                    ['Bronze Nataraja statue', 'sculpture', 60_000, 'pending_review'],
                ],
            ],
            [
                'name' => 'Chattogram Motors',
                'plan' => 'team',
                'status' => CompanyStatus::Active,
                'subscription' => 'active',
                'owner' => 'Kamal Chowdhury',
                'members' => ['Mitu Barua', 'Jahid Hasan'],
                'commission_bps' => null,
                'auctions' => [
                    ['Toyota Corolla 2012, 1500cc', 'cars', 950_000, 'live', 5],
                    ['Honda CB Hornet 160R, 2019', 'motorcycles', 140_000, 'live', 0],
                    ['Mitsubishi Pajero Sport 2015', 'cars', 2_800_000, 'scheduled'],
                    ['Yamaha FZS V2, 2017', 'motorcycles', 110_000, 'sold_shipped', 6],
                    ['Suzuki Gixxer 2016', 'motorcycles', 95_000, 'rejected'],
                    ['Nissan Sunny 2008', 'cars', 520_000, 'cancelled'],
                    ['Toyota Noah 2014 hybrid', 'cars', 1_900_000, 'pending_review'],
                ],
            ],
            [
                'name' => 'Sylhet Tea and Crafts',
                'plan' => 'starter',
                'status' => CompanyStatus::Active,
                'subscription' => 'trialing',
                'owner' => 'Rumana Begum',
                'members' => ['Arif Khan'],
                'commission_bps' => null,
                'auctions' => [
                    ['Sylhet first-flush tea, 5 kg chest', 'handicrafts', 4_000, 'live', 3],
                    ['Manipuri handloom saree', 'textiles', 7_500, 'scheduled'],
                    ['Cane and bamboo lounge chair', 'furniture', 9_000, 'draft'],
                    ['Shital pati floor mat, large', 'handicrafts', 2_500, 'unsold_no_bids'],
                ],
            ],
            [
                // Signed up, not yet verified by the platform.
                'name' => 'Rajshahi Silk House',
                'plan' => 'starter',
                'status' => CompanyStatus::Pending,
                'subscription' => 'trialing',
                'owner' => 'Shahin Alam',
                'members' => [],
                'commission_bps' => null,
                'auctions' => [
                    ['Rajshahi silk saree, royal blue', 'textiles', 14_000, 'draft'],
                ],
            ],
            [
                // Missed its last plan payment.
                'name' => 'Khulna Collectibles',
                'plan' => 'team',
                'status' => CompanyStatus::Active,
                'subscription' => 'past_due',
                'owner' => 'Dipak Roy',
                'members' => ['Lima Sarker'],
                'commission_bps' => null,
                'auctions' => [
                    ['Pakistan-era stamp album, 1950s', 'stamps', 5_000, 'sold_completed', 4],
                    ['Antique brass hookah base', 'antiques', 7_000, 'unsold_no_bids'],
                ],
            ],
        ];
    }

    /**
     * @param  array{name: string, plan: string, status: CompanyStatus, subscription: string, owner: string, members: list<string>, commission_bps: int|null, auctions: list<array{0: string, 1: string, 2: int, 3: string, 4?: int}>}  $definition
     */
    private function company(array $definition): void
    {
        $slug = Str::slug($definition['name']);

        $company = Company::factory()->create([
            'name' => $definition['name'],
            'slug' => $slug,
            'email' => "hello@{$slug}.test",
            'status' => $definition['status'],
            'verified_at' => $definition['status'] === CompanyStatus::Active ? now()->subMonths(2) : null,
            'user_type_id' => UserType::query()->where('slug', 'standard-seller')->value('id'),
        ]);

        $owner = User::factory()->companyOwner($company)->create([
            'name' => $definition['owner'],
            'email' => "owner@{$slug}.test",
        ]);

        $team = [$owner];

        foreach ($definition['members'] as $name) {
            $team[] = User::factory()->companyMember($company)->create([
                'name' => $name,
                'email' => Str::lower(Str::before($name, ' '))."@{$slug}.test",
            ]);
        }

        $wallet = Wallet::factory()->forCompany($company)->create();

        $plan = Plan::query()->where('slug', $definition['plan'])->firstOrFail();

        $subscription = Subscription::factory();
        $subscription = match ($definition['subscription']) {
            'trialing' => $subscription->trialing(),
            'past_due' => $subscription->pastDue(),
            default => $subscription,
        };
        $this->billing($subscription->create([
            'company_id' => $company->id,
            'plan_id' => $plan->id,
            'price' => $plan->price_monthly,
        ]), $plan);

        if ($definition['commission_bps'] !== null) {
            CompanyAgreement::factory()->create([
                'company_id' => $company->id,
                'rate_bps' => $definition['commission_bps'],
                'effective_from' => now()->subMonths(2),
                'note' => 'Launch partner rate agreed for the first year.',
                'created_by' => $this->owner->id,
            ]);
        }

        if ($definition['status'] === CompanyStatus::Pending) {
            $company->forceFill(['kyc_status' => KycStatus::Requested])->save();

            KycRequest::query()->forceCreate([
                'company_id' => $company->id,
                'status' => KycStatus::Requested,
                'note' => 'Please upload the trade licence and the owner\'s national ID.',
                'requested_by' => $this->staff->id,
            ]);
        }

        if ($definition['name'] === 'Chattogram Motors') {
            CompanyInvitation::factory()->create([
                'company_id' => $company->id,
                'email' => "sales@{$slug}.test",
                'invited_by' => $owner->id,
            ]);
        }

        foreach ($definition['auctions'] as $index => $row) {
            // Listings are spread over the team.
            $this->auction($company, $wallet, $team[$index % count($team)], $row);
        }
    }

    /**
     * Billing history for the plan: an active company has paid its current
     * period at a gateway; a company that missed a payment has an open invoice
     * for the next one. Trials have not been billed yet.
     */
    private function billing(Subscription $subscription, Plan $plan): void
    {
        $invoices = app(IssueInvoice::class);

        if ($subscription->status === SubscriptionStatus::Active) {
            $invoice = $invoices->handle($subscription, $plan, $subscription->billing_interval, $subscription->price, $subscription->current_period_start, $subscription->current_period_end);

            $transaction = $this->ledger->transaction(LedgerTransactionType::SubscriptionPayment)
                ->reference($invoice)
                ->idempotent("invoice:{$invoice->id}")
                ->describe("Plan invoice {$invoice->number}")
                ->debit($this->ledger->system(SystemAccount::GatewayClearing), $invoice->amount)
                ->credit($this->ledger->system(SystemAccount::SubscriptionRevenue), $invoice->amount)
                ->post();

            $invoice->forceFill([
                'status' => InvoiceStatus::Paid,
                'paid_at' => $subscription->current_period_start,
                'gateway' => 'sslcommerz',
                'ledger_transaction_id' => $transaction->id,
            ])->save();
        }

        if ($subscription->status === SubscriptionStatus::PastDue) {
            $start = $subscription->current_period_end;

            $invoices->handle($subscription, $plan, $subscription->billing_interval, $subscription->price, $start, $subscription->billing_interval->endFrom($start));
        }
    }

    /**
     * @param  array{0: string, 1: string, 2: int, 3: string, 4?: int}  $row
     */
    private function auction(Company $company, Wallet $companyWallet, User $member, array $row): void
    {
        [$title, $categorySlug, $startingBdt, $scenario] = $row;
        $bidCount = $row[4] ?? 0;

        $number = ++$this->auctionCount;
        $starting = $startingBdt * 100;
        // About 5% of the starting price, in steps of BDT 50.
        $increment = max(5_000, intdiv(intdiv($starting, 20), 5_000) * 5_000);

        $product = Product::query()->forceCreate([
            'company_id' => $company->id,
            'category_id' => $this->categories[$categorySlug],
            'created_by' => $member->id,
            'name' => $title,
            'description' => fake()->paragraph(3),
            'condition' => $categorySlug === 'handicrafts' || $categorySlug === 'textiles'
                ? ProductCondition::New
                : ProductCondition::Used,
        ]);

        $attributes = [
            'company_id' => $company->id,
            'product_id' => $product->id,
            'created_by' => $member->id,
            'title' => $title,
            'starting_price' => $starting,
            'min_increment' => $increment,
            'current_price' => $starting,
            'buy_now_price' => $scenario === 'scheduled' ? $starting * 2 : null,
            'reserve_price' => $scenario === 'unsold_reserve' ? $starting * 2 : null,
            'proposed_starts_at' => now()->addDay()->startOfHour(),
            'proposed_ends_at' => now()->addDays(4)->startOfHour(),
        ];

        // The company's agreement, else the platform's default commission.
        $commission = app(FeeResolver::class)->saleCommission($company);

        // What the platform fixes when it approves an auction.
        $approved = fn (CarbonImmutable $startsAt, CarbonImmutable $endsAt): array => [
            'submitted_at' => $startsAt->subDays(2),
            'reviewed_by' => $this->staff->id,
            'reviewed_at' => $startsAt->subDay(),
            'proposed_starts_at' => $startsAt,
            'proposed_ends_at' => $endsAt,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'original_ends_at' => $endsAt,
            'anti_snipe_window_seconds' => config('auction.bidding.anti_snipe_window_seconds'),
            'anti_snipe_extend_seconds' => config('auction.bidding.anti_snipe_extend_seconds'),
            'anti_snipe_max_extensions' => config('auction.bidding.anti_snipe_max_extensions'),
            'fee_snapshot' => ['sale_commission' => $commission->toSnapshot()],
        ];

        $ended = fn (int $daysAgo): array => $approved(now()->subDays($daysAgo + 3), now()->subDays($daysAgo))
            + ['closed_at' => now()->subDays($daysAgo)];

        $attributes = match ($scenario) {
            'draft' => $attributes + ['status' => AuctionStatus::Draft],
            'pending_review' => $attributes + [
                'status' => AuctionStatus::PendingReview,
                'submitted_at' => now()->subHours(5),
            ],
            'rejected' => $attributes + [
                'status' => AuctionStatus::Rejected,
                'submitted_at' => now()->subDays(2),
                'reviewed_by' => $this->staff->id,
                'reviewed_at' => now()->subDay(),
                'rejection_reason' => 'The registration papers are missing from the photos. Add them and submit again.',
            ],
            'cancelled' => $attributes + [
                'status' => AuctionStatus::Cancelled,
                'close_reason' => AuctionCloseReason::CancelledBySeller,
                'closed_at' => now()->subDays(3),
            ],
            'scheduled' => $approved(now()->addDays(1 + $number % 3)->startOfHour(), now()->addDays(4 + $number % 3)->startOfHour())
                + $attributes + ['status' => AuctionStatus::Scheduled],
            'live', 'live_auto_bid' => $approved(now()->subHours(20 + $number), now()->addHours(3 + $number * 5))
                + $attributes + ['status' => AuctionStatus::Live],
            'unsold_no_bids' => $ended(6) + $attributes + [
                'status' => AuctionStatus::Unsold,
                'close_reason' => AuctionCloseReason::NoBids,
            ],
            'unsold_reserve' => $ended(4) + $attributes + [
                'status' => AuctionStatus::Unsold,
                'close_reason' => AuctionCloseReason::ReserveNotMet,
            ],
            'sold_in_escrow' => $ended(1) + $attributes + ['status' => AuctionStatus::Sold, 'close_reason' => AuctionCloseReason::Sold],
            'sold_shipped' => $ended(3) + $attributes + ['status' => AuctionStatus::Sold, 'close_reason' => AuctionCloseReason::Sold],
            default => $ended(12) + $attributes + ['status' => AuctionStatus::Sold, 'close_reason' => AuctionCloseReason::Sold],
        };

        $auction = Auction::query()->forceCreate($attributes);

        if ($bidCount === 0) {
            return;
        }

        $leader = $this->bids($auction, $bidCount, $number);
        $price = $auction->current_price;

        if ($auction->status === AuctionStatus::Live) {
            $hold = $price;

            // An automatic bid sets its whole maximum aside, not just the current price.
            if ($scenario === 'live_auto_bid') {
                $hold = $price->plus($auction->min_increment->times(6));
                AutoBid::query()->create(['auction_id' => $auction->id, 'user_id' => $leader->id, 'max_amount' => $hold]);
            }

            $this->fund($leader, $hold);
            $this->ledger->hold($this->wallets[$leader->id], $hold, HoldType::Bid, $auction);

            // A couple of other buyers keep an eye on it.
            $auction->watchers()->attach([
                $this->buyers[($number + 5) % 11]->id => ['created_at' => now()->subHours(2)],
                $this->buyers[($number + 8) % 11]->id => ['created_at' => now()->subHour()],
            ]);

            return;
        }

        if ($auction->status === AuctionStatus::Sold) {
            $auction->forceFill(['winner_id' => $leader->id, 'final_price' => $price])->save();

            $this->order($auction, $leader, $companyWallet, $commission, $scenario);
        }
    }

    /**
     * Three buyers outbid each other; returns the one left in front.
     */
    private function bids(Auction $auction, int $count, int $number): User
    {
        $steps = [1, 2, 1, 3];
        $price = $auction->starting_price;
        $bidder = $this->buyers[0];
        $startedAt = $auction->starts_at ?? now();

        for ($seq = 1; $seq <= $count; $seq++) {
            if ($seq > 1) {
                $price = $price->plus($auction->min_increment->times($steps[$seq % 4]));
            }

            // Never the buyer with the empty wallet (the last one).
            $bidder = $this->buyers[($number * 2 + $seq % 3) % 11];

            Bid::query()->forceCreate([
                'auction_id' => $auction->id,
                'user_id' => $bidder->id,
                'amount' => $price,
                'type' => BidType::Manual,
                'seq' => $seq,
                'created_at' => $startedAt->addMinutes($seq * 47),
            ]);
        }

        $auction->forceFill([
            'current_price' => $price,
            'bid_count' => $count,
            'leader_id' => $bidder->id,
            'reserve_met' => $auction->reserve_price === null || $price->greaterThanOrEqual($auction->reserve_price),
        ])->save();

        return $bidder;
    }

    /**
     * Tops a buyer up so that the amount is within the share of the balance
     * the marketplace lets a buyer commit to bids.
     */
    private function fund(User $buyer, Money $amount): void
    {
        $wallet = $this->wallets[$buyer->id];
        $needed = intdiv($amount->amount * 10_000, (int) config('auction.bidding.biddable_share_bps')) + 1;

        if ($wallet->available()->amount >= $needed) {
            return;
        }

        // Round the top-up to whole BDT 10,000.
        $topUp = (int) ceil(($needed - $wallet->available()->amount) / 1_000_000) * 1_000_000;

        $this->deposit($buyer, Money::minor($topUp));
    }

    /** A top-up through the fake gateway, completed by the real deposit action. */
    private function deposit(User $buyer, Money $amount): void
    {
        $deposit = new Deposit;
        $deposit->forceFill([
            'wallet_id' => $this->wallets[$buyer->id]->id,
            'user_id' => $buyer->id,
            'gateway' => 'fake',
            'tran_id' => 'DEMO'.Str::upper((string) Str::ulid()),
            'amount' => $amount,
            'net_amount' => $amount,
            'currency' => $amount->currency,
            'status' => DepositStatus::Pending,
        ])->save();

        app(CompleteDeposit::class)->handle(new PaymentResult('fake', $deposit->tran_id, PaymentOutcome::Succeeded, $amount));

        $this->wallets[$buyer->id]->refresh();
    }

    private function order(Auction $auction, User $buyer, Wallet $companyWallet, CommissionTerms $terms, string $scenario): void
    {
        $price = $auction->final_price ?? $auction->current_price;
        $commission = $terms->apply($price, $auction->starting_price);
        $closedAt = $auction->closed_at ?? now();

        $this->fund($buyer, $price);

        $order = Order::query()->forceCreate([
            'number' => 'ORD-'.now()->format('Y').'-'.str_pad((string) ++$this->orderCount, 6, '0', STR_PAD_LEFT),
            'auction_id' => $auction->id,
            'buyer_id' => $buyer->id,
            'company_id' => $auction->company_id,
            'amount' => $price,
            'commission' => $commission,
            'seller_payout' => $price->minus($commission),
            'status' => OrderStatus::InEscrow,
            'shipping_name' => $buyer->name,
            'shipping_phone' => $buyer->phone,
            'shipping_address' => fake()->streetAddress().', Dhaka',
            'ship_by_at' => $closedAt->addDays((int) config('auction.orders.ship_by_days')),
            'created_at' => $closedAt,
        ]);

        // Closing captures the winner's hold into escrow.
        $buyerWallet = $this->wallets[$buyer->id];
        $hold = $this->ledger->hold($buyerWallet, $price, HoldType::Bid, $auction);

        $this->ledger->transaction(LedgerTransactionType::SaleCapture)
            ->reference($order)
            ->describe("Order {$order->number} paid into escrow")
            ->capturing($hold)
            ->debit($buyerWallet, $price)
            ->credit($this->ledger->system(SystemAccount::Escrow), $price)
            ->post();
        $this->event($order, 'created', null, OrderStatus::InEscrow, null, 'Auction won; payment is held in escrow.', $closedAt);

        if ($scenario === 'sold_in_escrow') {
            return;
        }

        $shippedAt = $closedAt->addDay();

        Shipment::query()->forceCreate([
            'order_id' => $order->id,
            'courier' => 'Sundarban Courier',
            'tracking_number' => 'SCS'.str_pad((string) ($order->id * 7919), 9, '0', STR_PAD_LEFT),
            'status' => $scenario === 'sold_shipped' ? ShipmentStatus::InTransit : ShipmentStatus::Delivered,
            'shipped_at' => $shippedAt,
            'delivered_at' => $scenario === 'sold_shipped' ? null : $shippedAt->addDays(2),
            'created_by' => $auction->created_by,
        ]);

        $order->forceFill([
            'status' => OrderStatus::Shipped,
            'shipped_at' => $shippedAt,
            'auto_release_at' => $shippedAt->addDays((int) config('auction.orders.auto_release_days')),
        ])->save();
        $this->event($order, 'shipped', OrderStatus::InEscrow, OrderStatus::Shipped, $auction->created_by, 'Handed to the courier.', $shippedAt);

        if ($scenario === 'sold_shipped') {
            return;
        }

        $completedAt = $shippedAt->addDays(3);

        $order->forceFill([
            'status' => OrderStatus::Completed,
            'delivered_at' => $shippedAt->addDays(2),
            'completed_at' => $completedAt,
        ])->save();

        $this->ledger->transaction(LedgerTransactionType::EscrowRelease)
            ->reference($order)
            ->describe("Order {$order->number} released to the seller")
            ->debit($this->ledger->system(SystemAccount::Escrow), $order->amount)
            ->credit($companyWallet, $order->seller_payout)
            ->credit($this->ledger->system(SystemAccount::CommissionRevenue), $order->commission, LedgerComponent::Commission, $terms)
            ->post();
        $this->event($order, 'completed', OrderStatus::Shipped, OrderStatus::Completed, $buyer->id, 'Buyer confirmed delivery; payment released to the seller.', $completedAt);

        $rating = 4 + $order->id % 2;

        Review::query()->forceCreate([
            'order_id' => $order->id,
            'company_id' => $order->company_id,
            'buyer_id' => $buyer->id,
            'rating' => $rating,
            'comment' => $rating === 5
                ? 'Exactly as described and packed with care.'
                : 'Good item. Delivery took a day longer than promised.',
            'created_at' => $completedAt->addHours(6),
        ]);

        Company::query()->whereKey($order->company_id)->update([
            'rating_sum' => DB::raw('rating_sum + '.$rating),
            'rating_count' => DB::raw('rating_count + 1'),
        ]);
    }

    private function event(Order $order, string $event, ?OrderStatus $from, OrderStatus $to, ?int $actorId, string $description, CarbonImmutable $at): void
    {
        OrderEvent::query()->forceCreate([
            'order_id' => $order->id,
            'event' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actorId,
            'description' => $description,
            'created_at' => $at,
        ]);
    }

    private function notices(): void
    {
        Notice::factory()->create([
            'title' => 'Welcome to the marketplace',
            'body' => 'Deposits by card and mobile banking are open. A buyer can commit up to 80% of the available balance to bids.',
            'audience' => NoticeAudience::All,
            'published_at' => now()->subDays(7),
            'created_by' => $this->staff->id,
        ]);

        Notice::factory()->create([
            'title' => 'Ship within five days',
            'body' => 'An order that is not shipped within five days of the auction closing is cancelled and the buyer refunded in full.',
            'audience' => NoticeAudience::Sellers,
            'published_at' => now()->subDays(2),
            'created_by' => $this->staff->id,
        ]);
    }
}
