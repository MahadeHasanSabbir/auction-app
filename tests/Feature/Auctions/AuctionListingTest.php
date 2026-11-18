<?php

namespace Tests\Feature\Auctions;

use App\Actions\Auctions\ApproveAuction;
use App\Actions\Auctions\CancelAuction;
use App\Actions\Auctions\RejectAuction;
use App\Actions\Auctions\SaveAuction;
use App\Actions\Auctions\SubmitAuction;
use App\Actions\Auctions\WithdrawAuction;
use App\Enums\AuctionCloseReason;
use App\Enums\AuctionStatus;
use App\Enums\CompanyStatus;
use App\Enums\KycStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Auction;
use App\Models\Company;
use App\Models\CompanyAgreement;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithCompanies;
use Tests\TestCase;

/** A seller prepares an auction, sends it for review, and the platform approves or rejects it. */
class AuctionListingTest extends TestCase
{
    use InteractsWithCompanies;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $member;

    private User $staff;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfSecond());

        [$this->company, $this->owner] = $this->company($this->plan(['max_active_auctions' => 2]));
        $this->member = User::factory()->companyMember($this->company)->create();
        $this->staff = User::factory()->platformStaff()->create();
        $this->product = $this->product();
    }

    private function product(): Product
    {
        $product = Product::factory()->create(['company_id' => $this->company->id]);
        ProductImage::factory()->create(['product_id' => $product->id]);

        return $product;
    }

    /** @param  array<string, mixed>  $data */
    private function draft(array $data = [], ?Product $product = null, ?Auction $auction = null): Auction
    {
        return app(SaveAuction::class)->handle($this->member, $this->company, $auction, $data + [
            'product_id' => ($product ?? $this->product)->id,
            'title' => 'Brass ship lantern',
            'starting_price' => Money::minor(100_000),
            'min_increment' => '50',
            'reserve_price' => '1500',
            'buy_now_price' => null,
            'proposed_starts_at' => now()->addDay()->toDateTimeString(),
            'proposed_ends_at' => now()->addDays(8)->toDateTimeString(),
        ]);
    }

    private function refused(callable $callback, string $key): void
    {
        try {
            $callback();
            $this->fail("Expected a refusal on {$key}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors(), (string) json_encode($e->errors()));
        }
    }

    public function test_a_team_member_saves_a_draft(): void
    {
        $auction = $this->draft();

        $this->assertSame(AuctionStatus::Draft, $auction->status);
        $this->assertSame($this->company->id, $auction->company_id);
        $this->assertSame($this->member->id, $auction->created_by);
        $this->assertSame(100_000, $auction->current_price->amount);
        $this->assertSame(5_000, $auction->min_increment->amount);
        $this->assertSame(150_000, $auction->reserve_price?->amount);
        $this->assertTrue($auction->proposed_starts_at?->equalTo(now()->addDay()));
    }

    public function test_prices_must_make_sense(): void
    {
        $this->refused(fn () => $this->draft(['starting_price' => '0']), 'starting_price');
        $this->refused(fn () => $this->draft(['min_increment' => '']), 'min_increment');
        $this->refused(fn () => $this->draft(['reserve_price' => '999']), 'reserve_price');
        $this->refused(fn () => $this->draft(['buy_now_price' => '1000']), 'buy_now_price');
        $this->refused(fn () => $this->draft(['buy_now_price' => '1200']), 'buy_now_price');
        $this->refused(fn () => $this->draft(['starting_price' => '12.345']), 'starting_price');
    }

    public function test_the_schedule_must_be_ahead_and_of_an_allowed_length(): void
    {
        $this->refused(fn () => $this->draft(['proposed_starts_at' => now()->subMinute()->toDateTimeString()]), 'proposed_starts_at');
        $this->refused(fn () => $this->draft(['proposed_starts_at' => now()->addDays(91)->toDateTimeString(), 'proposed_ends_at' => now()->addDays(92)->toDateTimeString()]), 'proposed_starts_at');
        $this->refused(fn () => $this->draft(['proposed_ends_at' => now()->addDay()->addMinutes(59)->toDateTimeString()]), 'proposed_ends_at');
        $this->refused(fn () => $this->draft(['proposed_ends_at' => now()->addDays(32)->toDateTimeString()]), 'proposed_ends_at');
        $this->refused(fn () => $this->draft(['proposed_ends_at' => null]), 'proposed_ends_at');
    }

    public function test_only_the_companys_own_unsold_product_without_another_auction_can_be_listed(): void
    {
        [$other] = $this->company($this->plan());
        $foreign = Product::factory()->create(['company_id' => $other->id]);
        $this->refused(fn () => $this->draft(product: $foreign), 'product_id');

        $this->draft();
        $this->refused(fn () => $this->draft(), 'product_id');

        $sold = $this->product();
        Auction::factory()->sold()->create(['company_id' => $this->company->id, 'product_id' => $sold->id]);
        $this->refused(fn () => $this->draft(product: $sold), 'product_id');
    }

    public function test_a_buyer_or_another_company_cannot_touch_the_auction(): void
    {
        $auction = $this->draft();
        [, $stranger] = $this->company($this->plan());

        $this->assertFalse(User::factory()->create()->can('view', $auction));
        $this->assertFalse($stranger->can('view', $auction));

        $this->expectException(AuthorizationException::class);
        app(SubmitAuction::class)->handle($stranger, $auction);
    }

    public function test_a_member_sends_a_draft_for_review_and_can_take_it_back(): void
    {
        $auction = app(SubmitAuction::class)->handle($this->member, $this->draft());

        $this->assertSame(AuctionStatus::PendingReview, $auction->status);
        $this->assertTrue($auction->submitted_at?->equalTo(now()));
        $this->assertFalse($this->member->can('update', $auction));

        $auction = app(WithdrawAuction::class)->handle($this->member, $auction);
        $this->assertSame(AuctionStatus::Draft, $auction->status);
        $this->assertNull($auction->submitted_at);
    }

    public function test_an_unverified_or_suspended_company_can_only_keep_drafts(): void
    {
        $auction = $this->draft();

        foreach ([CompanyStatus::Pending, CompanyStatus::Suspended] as $status) {
            $this->company->forceFill(['status' => $status])->save();
            $this->refused(fn () => app(SubmitAuction::class)->handle($this->member, $auction), 'auction');
        }

        // An active company still needs its documents approved.
        $this->company->forceFill(['status' => CompanyStatus::Active, 'kyc_status' => KycStatus::Submitted])->save();
        $this->refused(fn () => app(SubmitAuction::class)->handle($this->member, $auction), 'auction');

        $this->assertSame(AuctionStatus::Draft, $auction->refresh()->status);
    }

    public function test_a_company_whose_plan_does_not_allow_selling_cannot_submit(): void
    {
        $auction = $this->draft();
        $this->company->subscription()->update(['status' => SubscriptionStatus::Expired]);

        $this->refused(fn () => app(SubmitAuction::class)->handle($this->member, $auction), 'auction');
    }

    public function test_a_product_without_pictures_cannot_be_sent_for_review(): void
    {
        $bare = Product::factory()->create(['company_id' => $this->company->id]);

        $this->refused(fn () => app(SubmitAuction::class)->handle($this->member, $this->draft(product: $bare)), 'auction');
    }

    public function test_a_draft_whose_start_has_passed_must_be_rescheduled_before_review(): void
    {
        $auction = $this->draft();
        $this->travel(2)->days();

        $this->refused(fn () => app(SubmitAuction::class)->handle($this->member, $auction), 'proposed_starts_at');
    }

    public function test_the_plan_caps_auctions_under_review_scheduled_or_live(): void
    {
        Auction::factory()->live()->create(['company_id' => $this->company->id, 'product_id' => $this->product()->id]);
        Auction::factory()->scheduled()->create(['company_id' => $this->company->id, 'product_id' => $this->product()->id]);
        Auction::factory()->sold()->create(['company_id' => $this->company->id, 'product_id' => $this->product()->id]);

        $auction = $this->draft();
        $this->refused(fn () => app(SubmitAuction::class)->handle($this->member, $auction), 'auction');

        // A custom limit agreed for the company counts instead of the plan's.
        $this->company->subscription()->update(['max_active_auctions_override' => 3]);
        $this->assertSame(AuctionStatus::PendingReview, app(SubmitAuction::class)->handle($this->member, $auction)->status);
    }

    public function test_staff_approve_with_the_schedule_and_fix_commission_and_anti_sniping(): void
    {
        CompanyAgreement::factory()->create(['company_id' => $this->company->id, 'rate_bps' => 750, 'effective_from' => now()->subDay()]);
        $auction = app(SubmitAuction::class)->handle($this->member, $this->draft());

        $starts = now()->addDays(2);
        $ends = now()->addDays(5);
        $auction = app(ApproveAuction::class)->handle($this->staff, $auction, $starts, $ends->toDateTimeString());

        $this->assertSame(AuctionStatus::Scheduled, $auction->status);
        $this->assertTrue($auction->starts_at?->equalTo($starts));
        $this->assertTrue($auction->ends_at?->equalTo($ends));
        $this->assertTrue($auction->original_ends_at?->equalTo($ends));
        $this->assertSame($this->staff->id, $auction->reviewed_by);
        $this->assertSame(750, $auction->fee_snapshot['sale_commission']['rate_bps']);
        $this->assertSame(config('auction.bidding.anti_snipe_window_seconds'), $auction->anti_snipe_window_seconds);

        // A later agreement does not change the approved auction.
        CompanyAgreement::factory()->create(['company_id' => $this->company->id, 'rate_bps' => 1500, 'effective_from' => now()]);
        $this->assertSame(750, $auction->refresh()->fee_snapshot['sale_commission']['rate_bps']);

        $this->assertDatabaseHas('activity_log', ['log_name' => 'auctions', 'event' => 'approved', 'subject_id' => $auction->id, 'causer_id' => $this->staff->id]);
    }

    public function test_a_start_chosen_as_now_is_moved_to_the_moment_of_approval(): void
    {
        $auction = app(SubmitAuction::class)->handle($this->member, $this->draft());
        $this->travel(30)->seconds();

        $auction = app(ApproveAuction::class)->handle($this->staff, $auction, now()->subSeconds(20), now()->addDays(2));

        $this->assertTrue($auction->starts_at?->equalTo(now()));
        $this->refused(fn () => app(ApproveAuction::class)->handle($this->staff, Auction::factory()->pendingReview()->create(), now()->subHour(), now()->addDay()), 'starts_at');
    }

    public function test_only_platform_staff_review_and_only_auctions_awaiting_review(): void
    {
        $auction = $this->draft();

        $this->refused(fn () => app(ApproveAuction::class)->handle($this->staff, $auction, now()->addDay(), now()->addDays(2)), 'auction');

        $auction = app(SubmitAuction::class)->handle($this->member, $auction);
        $this->expectException(AuthorizationException::class);
        app(ApproveAuction::class)->handle($this->owner, $auction, now()->addDay(), now()->addDays(2));
    }

    public function test_approval_is_refused_once_the_company_is_suspended(): void
    {
        $auction = app(SubmitAuction::class)->handle($this->member, $this->draft());
        $this->company->forceFill(['status' => CompanyStatus::Suspended])->save();

        $this->refused(fn () => app(ApproveAuction::class)->handle($this->staff, $auction, now()->addDay(), now()->addDays(2)), 'auction');
    }

    public function test_an_auto_approved_company_is_scheduled_on_submission(): void
    {
        $this->company->forceFill(['auto_approve' => true])->save();

        $auction = app(SubmitAuction::class)->handle($this->member, $this->draft());

        $this->assertSame(AuctionStatus::Scheduled, $auction->status);
        $this->assertTrue($auction->starts_at?->equalTo(now()->addDay()));
        $this->assertNull($auction->reviewed_by);
        $this->assertNotNull($auction->fee_snapshot);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'auctions', 'event' => 'auto-approved', 'subject_id' => $auction->id]);
    }

    public function test_a_rejected_auction_goes_back_to_draft_when_edited_and_can_be_sent_again(): void
    {
        $auction = app(SubmitAuction::class)->handle($this->member, $this->draft());

        $this->refused(fn () => app(RejectAuction::class)->handle($this->staff, $auction, '  '), 'reason');
        $auction = app(RejectAuction::class)->handle($this->staff, $auction, 'Show the maker’s mark.');

        $this->assertSame(AuctionStatus::Rejected, $auction->status);
        $this->assertSame('Show the maker’s mark.', $auction->rejection_reason);
        $this->assertTrue($this->member->can('update', $auction));

        $auction = $this->draft(['title' => 'Brass ship lantern, marked'], auction: $auction);
        $this->assertSame(AuctionStatus::Draft, $auction->status);

        $auction = app(SubmitAuction::class)->handle($this->member, $auction);
        $this->assertSame(AuctionStatus::PendingReview, $auction->status);
        $this->assertNull($auction->rejection_reason);
    }

    public function test_only_the_owner_cancels_an_approved_auction(): void
    {
        $auction = app(SubmitAuction::class)->handle($this->member, $this->draft());
        $auction = app(ApproveAuction::class)->handle($this->staff, $auction, now()->addDay(), now()->addDays(2));

        $this->assertFalse($this->member->can('cancel', $auction));

        try {
            app(CancelAuction::class)->handle($this->member, $auction);
            $this->fail('A member cancelled an approved auction.');
        } catch (AuthorizationException) {
            $this->assertSame(AuctionStatus::Scheduled, $auction->refresh()->status);
        }

        $auction = app(CancelAuction::class)->handle($this->owner, $auction);
        $this->assertSame(AuctionStatus::Cancelled, $auction->status);
        $this->assertSame(AuctionCloseReason::CancelledBySeller, $auction->close_reason);

        // The product can be listed again.
        $this->assertSame(AuctionStatus::Draft, $this->draft()->status);
    }

    public function test_a_member_cancels_a_draft_and_staff_cancel_with_a_reason(): void
    {
        $this->assertSame(AuctionStatus::Cancelled, app(CancelAuction::class)->handle($this->member, $this->draft())->status);

        $scheduled = Auction::factory()->scheduled()->create(['company_id' => $this->company->id, 'product_id' => $this->product()->id]);
        $this->refused(fn () => app(CancelAuction::class)->handle($this->staff, $scheduled), 'reason');

        $scheduled = app(CancelAuction::class)->handle($this->staff, $scheduled, 'Counterfeit item.');
        $this->assertSame(AuctionCloseReason::CancelledByPlatform, $scheduled->close_reason);
        $this->assertSame('Counterfeit item.', $scheduled->cancellation_reason);
    }

    public function test_a_live_auction_without_bids_is_cancelled_by_the_owner_not_a_member(): void
    {
        $live = Auction::factory()->live()->create(['company_id' => $this->company->id, 'product_id' => $this->product()->id]);

        $this->assertFalse($this->member->can('cancel', $live));
        $this->assertSame(AuctionStatus::Cancelled, app(CancelAuction::class)->handle($this->owner, $live)->status);

        $sold = Auction::factory()->sold()->create(['company_id' => $this->company->id, 'product_id' => $this->product()->id]);
        $this->refused(fn () => app(CancelAuction::class)->handle($this->staff, $sold, 'Reason'), 'auction');
    }
}
