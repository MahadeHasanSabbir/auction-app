<?php

namespace Tests\Feature\Panels;

use App\Actions\Accounts\CreateStaffAccount;
use App\Actions\Accounts\SuspendAccount;
use App\Actions\Settings\UpdateSettings;
use App\Enums\AccountKind;
use App\Enums\FeeChargeType;
use App\Enums\FeeUseCase;
use App\Enums\UserRole;
use App\Filament\Admin\Pages\MarketplaceSettings;
use App\Filament\Admin\Resources\Activity\Pages\ListActivities;
use App\Filament\Admin\Resources\Categories\Pages\ManageCategories;
use App\Filament\Admin\Resources\FeeRules\Pages\ManageFeeRules;
use App\Filament\Admin\Resources\Staff\Pages\ListStaff;
use App\Filament\Admin\Resources\UserTypes\Pages\ManageUserTypes;
use App\Models\Category;
use App\Models\FeeRule;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserType;
use App\Services\Settings\Settings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\InteractsWithOtp;
use Tests\TestCase;

/** Staff accounts, fees, categories, user types, settings and the activity log in the admin panel. */
class AdminSetupTest extends TestCase
{
    use InteractsWithOtp;
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->systemOwner()->create();
        $this->staff = User::factory()->platformStaff()->create();
    }

    private function openAdmin(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
    }

    public function test_the_owner_adds_staff_who_must_choose_their_own_password(): void
    {
        $this->openAdmin($this->owner);

        Livewire::test(ListStaff::class)
            ->callAction(TestAction::make('addStaff')->table(), ['name' => 'Nadia Islam', 'email' => 'Nadia@Auction.test', 'password' => 'temporary1'])
            ->assertHasNoActionErrors();

        $new = User::query()->where('email', 'nadia@auction.test')->firstOrFail();
        $this->assertSame(UserRole::PlatformStaff, $new->role);
        $this->assertTrue($new->password_change_required);
        $this->assertNotNull($new->email_verified_at);
        $this->assertTrue(Hash::check('temporary1', $new->password));
        $this->assertSame(1, Activity::query()->where('event', 'staff_created')->count());

        // Until the password is changed, the panel sends them to the security page.
        $this->actingAs($new)->get('/admin/orders')->assertRedirect(route('profile.security'));

        $this->withConfirmedOtp()->put('/password', [
            'current_password' => 'temporary1',
            'password' => 'my-own-password',
            'password_confirmation' => 'my-own-password',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($new->refresh()->password_change_required);
        $this->get('/admin/orders')->assertOk();
    }

    public function test_a_new_temporary_password_signs_the_staff_member_out(): void
    {
        $this->openAdmin($this->owner);

        Livewire::test(ListStaff::class)
            ->assertCanSeeTableRecords([$this->staff, $this->owner])
            ->callAction(TestAction::make('resetPassword')->table($this->staff), ['password' => 'another-one1']);

        $this->staff->refresh();
        $this->assertTrue($this->staff->password_change_required);
        $this->assertTrue(Hash::check('another-one1', $this->staff->password));

        Livewire::test(ListStaff::class)->assertActionHidden(TestAction::make('resetPassword')->table($this->owner));
    }

    public function test_staff_cannot_add_staff(): void
    {
        $this->expectException(AuthorizationException::class);

        app(CreateStaffAccount::class)->handle($this->staff, ['name' => 'X Y', 'email' => 'x@y.test', 'password' => 'whatever1']);
    }

    public function test_a_fee_rule_is_added_as_a_percentage_and_ended_not_edited(): void
    {
        $this->openAdmin($this->staff);

        Livewire::test(ManageFeeRules::class)
            ->callAction('create', [
                'use_case' => FeeUseCase::Deposit->value,
                'charge_type' => FeeChargeType::Service->value,
                'percent_bps' => '2.5',
                'fixed_amount' => '10',
                'min_amount' => '',
                'max_amount' => '500',
                'effective_from' => now()->subMinute()->format('Y-m-d H:i'),
                'is_active' => true,
            ])
            ->assertHasNoActionErrors();

        $rule = FeeRule::query()->sole();
        $this->assertSame(250, $rule->percent_bps);
        $this->assertSame(1_000, $rule->fixed_amount->amount);
        $this->assertSame(50_000, $rule->max_amount?->amount);
        $this->assertNull($rule->min_amount);
        $this->assertSame($this->staff->id, $rule->created_by);

        // In force: it cannot be edited, only ended.
        Livewire::test(ManageFeeRules::class)
            ->assertActionHidden(TestAction::make('edit')->table($rule))
            ->callAction(TestAction::make('end')->table($rule));

        $this->assertNotNull($rule->refresh()->effective_until);
        $this->assertFalse(FeeRule::query()->effectiveAt()->exists());
    }

    public function test_a_category_in_use_cannot_be_deleted(): void
    {
        $this->openAdmin($this->staff);

        Livewire::test(ManageCategories::class)
            ->callAction('create', ['name' => 'Clocks', 'slug' => 'clocks', 'sort_order' => 3, 'is_active' => true])
            ->assertHasNoActionErrors();

        $clocks = Category::query()->where('slug', 'clocks')->firstOrFail();
        $empty = Category::factory()->create(['parent_id' => null]);
        Product::factory()->create(['category_id' => $clocks->id]);

        Livewire::test(ManageCategories::class)
            ->assertActionHidden(TestAction::make('delete')->table($clocks))
            ->callAction(TestAction::make('delete')->table($empty));

        $this->assertModelMissing($empty);
    }

    public function test_user_types_are_for_the_owner_and_keep_one_default_per_kind(): void
    {
        $old = UserType::factory()->create(['account_kind' => AccountKind::Buyer, 'is_default' => true]);
        $this->openAdmin($this->owner);

        Livewire::test(ManageUserTypes::class)
            ->callAction('create', ['name' => 'Collector', 'slug' => 'collector', 'account_kind' => 'buyer', 'is_default' => true, 'is_active' => true])
            ->assertHasNoActionErrors();

        $this->assertFalse($old->refresh()->is_default);
        $this->assertTrue(UserType::query()->where('slug', 'collector')->firstOrFail()->is_default);

        $this->actingAs($this->staff)->get('/admin/user-types')->assertForbidden();
    }

    public function test_settings_override_the_config_and_saving_the_default_removes_the_override(): void
    {
        $default = (int) config('auction.bidding.biddable_share_bps');
        $this->openAdmin($this->owner);

        Livewire::test(MarketplaceSettings::class)
            ->assertSet('data.bidding__biddable_share_bps', (string) ($default / 100))
            ->set('data.bidding__biddable_share_bps', '65.5')
            ->set('data.orders__ship_by_days', '4')
            ->set('data.payments__withdrawal_min', '750')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(6_550, config('auction.bidding.biddable_share_bps'));
        $this->assertSame(4, config('auction.orders.ship_by_days'));
        $this->assertSame(75_000, config('auction.payments.withdrawal_min'));
        $this->assertSame(3, Setting::query()->count());
        $this->assertSame(1, Activity::query()->where('event', 'settings_changed')->count());

        // A fresh boot (another request, a worker) reads the stored values.
        config(['auction.bidding.biddable_share_bps' => $default]);
        app(Settings::class)->apply();
        $this->assertSame(6_550, config('auction.bidding.biddable_share_bps'));

        Livewire::test(MarketplaceSettings::class)
            ->set('data.bidding__biddable_share_bps', (string) ($default / 100))
            ->call('save');

        $this->assertSame($default, config('auction.bidding.biddable_share_bps'));
        $this->assertFalse(Setting::query()->where('key', 'bidding.biddable_share_bps')->exists());
    }

    public function test_settings_are_checked_and_only_the_owner_changes_them(): void
    {
        try {
            app(UpdateSettings::class)->handle($this->owner, ['orders.ship_by_days' => 0]);
            $this->fail('Out of range was accepted.');
        } catch (ValidationException) {
        }

        try {
            app(UpdateSettings::class)->handle($this->owner, ['payments.deposit_min' => 900_000, 'payments.deposit_max' => 800_000]);
            $this->fail('A minimum above the maximum was accepted.');
        } catch (ValidationException) {
        }

        $this->assertSame(0, Setting::query()->count());

        $this->expectException(AuthorizationException::class);
        app(UpdateSettings::class)->handle($this->staff, ['orders.ship_by_days' => 3]);
    }

    public function test_the_activity_log_lists_staff_decisions(): void
    {
        $buyer = User::factory()->create();
        app(SuspendAccount::class)->handle($this->staff, $buyer, 'Repeated non-payment.');

        $this->openAdmin($this->owner);
        $entry = Activity::query()->where('event', 'suspended')->sole();

        Livewire::test(ListActivities::class)
            ->assertCanSeeTableRecords([$entry])
            ->filterTable('log_name', 'admin')
            ->assertCanSeeTableRecords([$entry])
            ->assertSee($this->staff->name);
    }
}
