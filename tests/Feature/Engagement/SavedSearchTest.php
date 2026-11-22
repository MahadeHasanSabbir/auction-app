<?php

namespace Tests\Feature\Engagement;

use App\Actions\Auctions\ApproveAuction;
use App\Actions\Searches\SaveSearch;
use App\Enums\NotificationKind;
use App\Jobs\AlertSavedSearches;
use App\Livewire\Account\SavedSearches;
use App\Livewire\Auctions\Index;
use App\Models\Auction;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\SavedSearch;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Services\Notifications\AccountAlerts;
use App\Support\AuctionFilters;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Saving the auction list's filters and being told about new matches. */
class SavedSearchTest extends TestCase
{
    use RefreshDatabase;

    private function auction(string $title, Category $category, int $price = 100_000): Auction
    {
        $product = Product::factory()->create(['category_id' => $category->id, 'name' => $title]);

        return Auction::factory()->scheduled()->create([
            'product_id' => $product->id,
            'company_id' => $product->company_id,
            'title' => $title,
            'starting_price' => $price,
        ]);
    }

    public function test_a_buyer_saves_the_current_filters_from_the_auction_list(): void
    {
        $buyer = User::factory()->create();

        Livewire::actingAs($buyer)
            ->test(Index::class)
            ->set('search', 'lamp')
            ->set('maxPrice', '5000')
            ->assertSee(__('Save this search'))
            ->set('searchName', 'Cheap lamps')
            ->call('saveSearch')
            ->assertHasNoErrors();

        $search = SavedSearch::query()->sole();
        $this->assertSame($buyer->id, $search->user_id);
        $this->assertSame('Cheap lamps', $search->name);
        $this->assertSame(['q' => 'lamp', 'max' => '5000'], $search->filters);
        $this->assertTrue($search->alerts);
    }

    public function test_saving_refuses_no_filters_duplicates_the_cap_and_sellers(): void
    {
        $buyer = User::factory()->create();
        $save = app(SaveSearch::class);

        $refused = function (callable $call): void {
            try {
                $call();
                $this->fail('The save was accepted.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('searchName', $e->errors());
            }
        };

        $refused(fn () => $save->handle($buyer, new AuctionFilters));

        $save->handle($buyer, new AuctionFilters(search: 'clock'));
        $this->assertSame('“clock”', SavedSearch::query()->sole()->name);
        $refused(fn () => $save->handle($buyer, new AuctionFilters(search: 'clock'), 'Again'));

        config(['auction.engagement.saved_searches_max' => 1]);
        $refused(fn () => $save->handle($buyer, new AuctionFilters(search: 'vase')));

        $this->expectException(AuthorizationException::class);
        $save->handle(User::factory()->companyOwner(Company::factory()->create())->create(), new AuctionFilters(search: 'vase'));
    }

    public function test_approval_queues_the_alerts(): void
    {
        Queue::fake();
        $auction = Auction::factory()->pendingReview()->create();

        // Both staff approval and auto-approval go through schedule().
        app(ApproveAuction::class)->schedule($auction, $auction->company, now()->addHour(), now()->addDays(2), null);

        Queue::assertPushed(AlertSavedSearches::class, fn (AlertSavedSearches $job) => $job->auctionId === $auction->id);
    }

    public function test_matching_buyers_are_told_once(): void
    {
        Notification::fake();
        $lighting = Category::factory()->create(['slug' => 'lighting']);
        $lamps = Category::factory()->create(['slug' => 'lamps', 'parent_id' => $lighting->id]);
        $auction = $this->auction('Brass desk lamp', $lamps, 300_000);

        $match = User::factory()->create();
        SavedSearch::factory()->create(['user_id' => $match->id, 'filters' => ['q' => 'LAMP', 'category' => 'lighting', 'max' => '5000']]);
        SavedSearch::factory()->create(['user_id' => $match->id, 'filters' => ['q' => 'brass']]);

        $tooCheap = User::factory()->create();
        SavedSearch::factory()->create(['user_id' => $tooCheap->id, 'filters' => ['q' => 'lamp', 'max' => '1000']]);

        $muted = User::factory()->create();
        SavedSearch::factory()->create(['user_id' => $muted->id, 'filters' => ['q' => 'lamp'], 'alerts' => false]);

        $otherCategory = User::factory()->create();
        SavedSearch::factory()->create(['user_id' => $otherCategory->id, 'filters' => ['category' => 'furniture']]);

        (new AlertSavedSearches($auction->id))->handle(app(AccountAlerts::class));

        Notification::assertSentToTimes($match, AppNotification::class, 1);
        Notification::assertSentTo($match, AppNotification::class, fn (AppNotification $n) => $n->kind === NotificationKind::SavedSearchMatch);
        Notification::assertNotSentTo([$tooCheap, $muted, $otherCategory], AppNotification::class);
        $this->assertSame(1, SavedSearch::query()->whereNotNull('last_alerted_at')->count());
    }

    public function test_the_saved_searches_page_switches_alerts_and_deletes(): void
    {
        $buyer = User::factory()->create();
        $search = SavedSearch::factory()->create(['user_id' => $buyer->id, 'name' => 'Old clocks']);
        $theirs = SavedSearch::factory()->create();

        $this->actingAs($buyer)->get(route('saved-searches'))->assertOk()->assertSee('Old clocks');

        Livewire::actingAs($buyer)->test(SavedSearches::class)
            ->call('toggleAlerts', $search->id)
            ->call('delete', $theirs->id)
            ->assertNotFound();

        $this->assertFalse($search->refresh()->alerts);
        $this->assertModelExists($theirs);

        Livewire::actingAs($buyer)->test(SavedSearches::class)->call('delete', $search->id);
        $this->assertModelMissing($search);
    }
}
