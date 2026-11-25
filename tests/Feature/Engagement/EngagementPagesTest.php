<?php

namespace Tests\Feature\Engagement;

use App\Enums\CouponTarget;
use App\Filament\Admin\Resources\Coupons\Pages\ManageCoupons as AdminCoupons;
use App\Filament\Seller\Resources\Coupons\Pages\ManageCoupons as SellerCoupons;
use App\Livewire\Auctions\Index;
use App\Livewire\Home;
use App\Models\Auction;
use App\Models\Coupon;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\InteractsWithCompanies;
use Tests\TestCase;

/** The coupon screens in both panels and where featured auctions show. */
class EngagementPagesTest extends TestCase
{
    use InteractsWithCompanies;
    use RefreshDatabase;

    public function test_the_owner_creates_platform_coupons(): void
    {
        // A bonus credit coupon is the system owner's to make.
        $staff = User::factory()->systemOwner()->create();
        Coupon::factory()->forCompany()->create(['code' => 'SELLERS']);
        Filament::setCurrentPanel('admin');
        $this->actingAs($staff);

        Livewire::test(AdminCoupons::class)
            ->assertCanNotSeeTableRecords(Coupon::query()->get())
            ->callAction('create', [
                'code' => 'welcome',
                'target' => CouponTarget::WalletBonus->value,
                'discount_type' => 'fixed',
                'fixed' => '500',
                'per_user_limit' => 1,
                'is_active' => true,
            ])
            ->assertHasNoActionErrors();

        $coupon = Coupon::query()->where('code', 'WELCOME')->sole();
        $this->assertSame(50_000, $coupon->value);
        $this->assertSame(CouponTarget::WalletBonus, $coupon->target);
        $this->assertNull($coupon->company_id);
    }

    public function test_the_owner_makes_order_coupons_and_the_team_only_looks(): void
    {
        [$company, $owner] = $this->company($this->plan());
        $theirs = Coupon::factory()->forCompany()->create();
        $this->actingAs($owner);
        Filament::setCurrentPanel('seller');
        Filament::setTenant($company);

        Livewire::test(SellerCoupons::class)
            ->callAction('create', ['code' => 'thanks', 'discount_type' => 'percent', 'percent' => '12.5', 'per_user_limit' => 1, 'is_active' => true])
            ->assertHasNoActionErrors();

        $coupon = Coupon::query()->where('code', 'THANKS')->sole();
        $this->assertSame(CouponTarget::Order, $coupon->target);
        $this->assertSame($company->id, $coupon->company_id);
        $this->assertSame(1_250, $coupon->value);

        Livewire::test(SellerCoupons::class)->assertCanSeeTableRecords([$coupon])->assertCanNotSeeTableRecords([$theirs]);

        $this->actingAs(User::factory()->companyMember($company)->create());
        Livewire::test(SellerCoupons::class)->assertActionHidden('create');
    }

    public function test_featured_auctions_lead_the_list_and_show_on_the_home_page(): void
    {
        Auction::factory()->live()->create(['ends_at' => now()->addHour(), 'title' => 'Plain clock']);
        $featured = Auction::factory()->live()->create(['ends_at' => now()->addDays(3), 'featured_at' => now(), 'title' => 'Shiny clock']);

        Livewire::test(Index::class)->assertSeeInOrder(['Shiny clock', 'Plain clock']);
        Livewire::test(Home::class)->assertViewHas('featured', fn ($list) => $list->pluck('id')->all() === [$featured->id]);
    }
}
