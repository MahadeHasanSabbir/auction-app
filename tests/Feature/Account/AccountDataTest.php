<?php

namespace Tests\Feature\Account;

use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\Buyers\Pages\ListBuyers;
use App\Filament\Admin\Resources\Buyers\Pages\ViewBuyer;
use App\Models\Bid;
use App\Models\LoginEvent;
use App\Models\Order;
use App\Models\SavedSearch;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Models\Wallet;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\InteractsWithLedger;
use Tests\Support\InteractsWithOtp;
use Tests\TestCase;

/** A buyer's own data: downloading it, and staff wiping it on request. */
class AccountDataTest extends TestCase
{
    use InteractsWithLedger;
    use InteractsWithOtp;
    use RefreshDatabase;

    private User $buyer;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPlatformAccounts();
        $this->buyer = User::factory()->create(['name' => 'Karim Ahmed', 'phone' => '01700000000', 'address' => 'Road 1, Dhaka']);
        Wallet::factory()->create(['user_id' => $this->buyer->id]);
        $this->staff = User::factory()->platformStaff()->create();
    }

    public function test_a_buyer_downloads_their_data_after_a_fresh_code(): void
    {
        Bid::factory()->create(['user_id' => $this->buyer->id]);
        $this->fund($this->buyer->wallet()->firstOrFail(), 5000);

        $this->actingAs($this->buyer)->post('/profile/export')->assertRedirect(route('otp.confirm'));

        $response = $this->actingAs($this->buyer)->withConfirmedOtp()->post('/profile/export')->assertOk();

        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        $data = $response->json();
        $this->assertSame('Karim Ahmed', $data['account']['name']);
        $this->assertCount(1, $data['bids']);
        $this->assertCount(1, $data['statement']);
        $this->assertSame(5000, $data['statement'][0]['amount']['amount']);
        $this->assertArrayNotHasKey('password', $data['account']);
    }

    public function test_only_buyers_have_the_download(): void
    {
        $this->actingAs($this->staff)->withConfirmedOtp()->post('/profile/export')->assertForbidden();
    }

    public function test_staff_anonymise_a_closed_buyer_and_the_records_stay(): void
    {
        $order = Order::factory()->create(['buyer_id' => $this->buyer->id, 'status' => OrderStatus::Completed, 'shipping_address' => 'Road 1, Dhaka']);
        TrustedDevice::factory()->for($this->buyer)->create();
        SavedSearch::factory()->create(['user_id' => $this->buyer->id]);
        LoginEvent::factory()->create(['user_id' => $this->buyer->id]);
        $this->buyer->createToken('app', ['read']);
        $this->buyer->delete();

        $this->actingAs($this->staff);
        Filament::setCurrentPanel('admin');

        Livewire::test(ListBuyers::class)->assertCanNotSeeTableRecords([$this->buyer])
            ->filterTable('trashed', true)->assertCanSeeTableRecords([$this->buyer]);

        Livewire::test(ViewBuyer::class, ['record' => $this->buyer->id])
            ->callAction('anonymise', ['reason' => 'Email from the buyer, 23 Nov'])
            ->assertHasNoActionErrors();

        $user = User::withTrashed()->findOrFail($this->buyer->id);
        $this->assertNotNull($user->anonymised_at);
        $this->assertSame("Deleted user #{$user->id}", $user->name);
        $this->assertStringEndsWith('@anonymised.invalid', $user->email);
        $this->assertNull($user->phone);
        $this->assertNull($user->address);
        $this->assertFalse($user->is_active);
        $this->assertSame(0, TrustedDevice::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, SavedSearch::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, LoginEvent::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, $user->tokens()->count());

        // The order is kept, without the delivery details.
        $order->refresh();
        $this->assertSame($user->id, $order->buyer_id);
        $this->assertNull($order->shipping_address);

        $this->assertSame('Email from the buyer, 23 Nov', Activity::query()->where('event', 'anonymised')->firstOrFail()->properties['reason']);

        // Once only.
        $this->assertFalse(Gate::forUser($this->staff)->allows('anonymise', $user));
    }

    public function test_money_or_an_open_order_blocks_it(): void
    {
        $this->actingAs($this->staff);
        Filament::setCurrentPanel('admin');

        $this->fund($this->buyer->wallet()->firstOrFail(), 100);
        Livewire::test(ListBuyers::class)
            ->callAction(TestAction::make('anonymise')->table($this->buyer), ['reason' => 'Asked by phone']);
        $this->assertNull($this->buyer->refresh()->anonymised_at);

        $other = User::factory()->create();
        Order::factory()->create(['buyer_id' => $other->id, 'status' => OrderStatus::Shipped]);
        Livewire::test(ListBuyers::class)
            ->callAction(TestAction::make('anonymise')->table($other), ['reason' => 'Asked by phone']);
        $this->assertNull($other->refresh()->anonymised_at);
    }

    public function test_sellers_and_staff_cannot_be_anonymised_here(): void
    {
        $this->assertFalse(Gate::forUser($this->staff)->allows('anonymise', User::factory()->platformStaff()->create()));
        $this->assertFalse(Gate::forUser($this->buyer)->allows('anonymise', User::factory()->create()));
    }
}
