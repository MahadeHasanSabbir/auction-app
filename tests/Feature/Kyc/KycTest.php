<?php

namespace Tests\Feature\Kyc;

use App\Actions\Accounts\RegisterBuyer;
use App\Actions\Accounts\RegisterCompany;
use App\Actions\Bidding\BuyNow;
use App\Actions\Bidding\PlaceBid;
use App\Actions\Kyc\RequestKyc;
use App\Actions\Kyc\ReviewKyc;
use App\Actions\Kyc\SubmitKyc;
use App\Actions\Wallet\RequestWithdrawal;
use App\Enums\KycDocumentType;
use App\Enums\KycStatus;
use App\Enums\NotificationKind;
use App\Enums\PayoutMethod;
use App\Filament\Admin\Resources\Buyers\Pages\ViewBuyer;
use App\Filament\Admin\Resources\Kyc\KycRequestsRelationManager;
use App\Filament\Admin\Resources\Kyc\Pages\ListKycRequests;
use App\Filament\Admin\Resources\Kyc\Pages\ViewKycRequest;
use App\Filament\Seller\Pages\Verification as SellerVerification;
use App\Http\Controllers\KycDocumentController;
use App\Livewire\Account\Verification;
use App\Models\Auction;
use App\Models\Company;
use App\Models\KycRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\AppNotification;
use App\Support\Money;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\InteractsWithCompanies;
use Tests\Support\InteractsWithLedger;
use Tests\TestCase;

/** Identity checks: who must verify, what it blocks, the upload pages and the staff desk. */
class KycTest extends TestCase
{
    use InteractsWithCompanies;
    use InteractsWithLedger;
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('kyc');
        Notification::fake();
        $this->createPlatformAccounts();
        $this->staff = User::factory()->platformStaff()->create();
    }

    private function newBuyer(): User
    {
        $buyer = app(RegisterBuyer::class)->handle(['name' => 'New Buyer', 'email' => 'new@example.test', 'password' => 'secret-password']);
        $buyer->forceFill(['email_verified_at' => now()])->save();

        return $buyer;
    }

    private function refused(callable $callback, string $field): void
    {
        try {
            $callback();
            $this->fail('Expected a refusal.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    private function idCard(): array
    {
        return [['type' => KycDocumentType::NationalId, 'file' => UploadedFile::fake()->image('nid.jpg')]];
    }

    public function test_a_new_buyer_cannot_bid_buy_or_withdraw_until_verified(): void
    {
        $buyer = $this->newBuyer();
        $this->assertSame(KycStatus::Requested, $buyer->kyc_status);

        $wallet = $this->fund(Wallet::query()->where('user_id', $buyer->id)->firstOrFail(), 500_000);
        $auction = Auction::factory()->live()->create(['buy_now_price' => 100_000]);

        $this->refused(fn () => app(PlaceBid::class)->handle($buyer, $auction, '100'), 'amount');
        $this->refused(fn () => app(BuyNow::class)->handle($buyer, $auction), 'buy_now');
        $this->refused(fn () => app(RequestWithdrawal::class)->handle($buyer, $wallet, Money::minor(100_000), PayoutMethod::Bkash, [
            'account_name' => 'New Buyer', 'account_number' => '01712345678',
        ]), 'amount');

        $buyer->forceFill(['kyc_status' => KycStatus::Approved])->save();
        $result = app(PlaceBid::class)->handle($buyer, $auction, $auction->current_price->plus(Money::minor(10_000))->toDecimal());
        $this->assertTrue($result->leading);
        $this->assertLedgerBalanced();
    }

    public function test_verification_can_be_switched_off(): void
    {
        config(['auction.kyc.required' => false]);

        $this->assertSame(KycStatus::NotRequired, $this->newBuyer()->kyc_status);
    }

    public function test_a_buyer_uploads_documents_and_staff_approve_them(): void
    {
        $buyer = $this->newBuyer();

        $this->actingAs($buyer)->get('/verification')->assertOk()->assertSee(__('Send for review'));

        Livewire::test(Verification::class)
            ->set('idType', KycDocumentType::Passport->value)
            ->set('idFile', UploadedFile::fake()->create('passport.pdf', 200, 'application/pdf'))
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame(KycStatus::Submitted, $buyer->refresh()->kyc_status);
        $request = KycRequest::query()->where('user_id', $buyer->id)->with('documents')->firstOrFail();
        $this->assertSame(KycStatus::Submitted, $request->status);
        $this->assertSame(KycDocumentType::Passport, $request->documents->first()?->type);
        Storage::disk('kyc')->assertExists((string) $request->documents->first()?->path);
        Notification::assertSentTo($this->staff, AppNotification::class, fn (AppNotification $n) => $n->kind === NotificationKind::KycSubmitted);

        $this->actingAs($this->staff);
        Filament::setCurrentPanel('admin');

        Livewire::test(ListKycRequests::class)->assertCanSeeTableRecords([$request]);
        Livewire::test(ViewKycRequest::class, ['record' => $request->getRouteKey()])
            ->assertSee('passport.pdf')
            ->callAction('approveKyc');

        $this->assertSame(KycStatus::Approved, $buyer->refresh()->kyc_status);
        $this->assertSame($this->staff->id, $request->refresh()->reviewed_by);
        Notification::assertSentTo($buyer, AppNotification::class, fn (AppNotification $n) => $n->kind === NotificationKind::KycApproved);

        // The files stay visible after approval: on the buyer's admin page and to the buyer.
        Livewire::test(ViewBuyer::class, ['record' => $buyer->getRouteKey()])
            ->assertSee(__('Identity documents'))
            ->assertSee('passport.pdf');
        $this->actingAs($buyer)->get('/verification')->assertSee(__('Documents you sent'))->assertSee('passport.pdf');
    }

    public function test_rejected_documents_are_sent_again_on_a_new_request(): void
    {
        $buyer = $this->newBuyer();
        $first = app(SubmitKyc::class)->handle($buyer, $buyer, $this->idCard());

        app(ReviewKyc::class)->reject($this->staff, $first, 'The photo is blurred.');
        $this->assertSame(KycStatus::Rejected, $buyer->refresh()->kyc_status);
        Notification::assertSentTo($buyer, AppNotification::class, fn (AppNotification $n) => $n->kind === NotificationKind::KycRejected);

        $this->actingAs($buyer)->get('/verification')->assertSee('The photo is blurred.');

        $second = app(SubmitKyc::class)->handle($buyer, $buyer, $this->idCard());
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(KycStatus::Submitted, $buyer->refresh()->kyc_status);

        // Both rounds are kept, with their own files, in the buyer's history.
        $this->actingAs($this->staff);
        Filament::setCurrentPanel('admin');
        Livewire::test(KycRequestsRelationManager::class, ['ownerRecord' => $buyer, 'pageClass' => ViewBuyer::class])
            ->assertCanSeeTableRecords([$first, $second])
            ->assertSee('The photo is blurred.');
        $this->assertSame(2, $buyer->kycRequests()->withCount('documents')->get()->sum('documents_count'));

        // Nothing to submit while it is being checked, and a review happens once.
        $this->refused(fn () => app(SubmitKyc::class)->handle($buyer, $buyer, $this->idCard()), 'documents');
        app(ReviewKyc::class)->approve($this->staff, $second);
        $this->refused(fn () => app(ReviewKyc::class)->reject($this->staff, $second, 'Late'), 'reason');
    }

    public function test_the_upload_is_checked(): void
    {
        $buyer = $this->newBuyer();

        $this->refused(fn () => app(SubmitKyc::class)->handle($buyer, $buyer, [
            ['type' => KycDocumentType::NationalId, 'file' => UploadedFile::fake()->create('id.exe', 10, 'application/octet-stream')],
        ]), 'documents.0.file');
        $this->refused(fn () => app(SubmitKyc::class)->handle($buyer, $buyer, [
            ['type' => KycDocumentType::Other, 'file' => UploadedFile::fake()->image('bill.jpg')],
        ]), 'documents');

        $this->expectException(AuthorizationException::class);
        app(SubmitKyc::class)->handle(User::factory()->create(), $buyer, $this->idCard());
    }

    public function test_a_company_owner_sends_the_licence_and_the_company_may_then_list(): void
    {
        $owner = app(RegisterCompany::class)->handle(['name' => 'Owner', 'email' => 'owner@shop.test', 'password' => 'secret-password', 'company_name' => 'Shop']);
        $company = Company::query()->findOrFail($owner->company_id);
        $this->assertSame(KycStatus::Requested, $company->kyc_status);

        $member = User::factory()->companyMember($company)->create();
        $this->expectsRefusal(fn () => app(SubmitKyc::class)->handle($member, $company, $this->idCard()));

        // The ID alone is not enough for a company.
        $this->refused(fn () => app(SubmitKyc::class)->handle($owner, $company, $this->idCard()), 'documents');

        $owner->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($owner);
        Filament::setCurrentPanel('seller');
        Filament::setTenant($company);

        Livewire::test(SellerVerification::class)
            ->fillForm([
                'id_type' => KycDocumentType::NationalId->value,
                'id_file' => [UploadedFile::fake()->image('nid.jpg')],
                'licence_file' => [UploadedFile::fake()->create('licence.pdf', 100, 'application/pdf')],
            ])
            ->call('submit')
            ->assertHasNoFormErrors();

        $request = KycRequest::query()->where('company_id', $company->id)->withCount('documents')->firstOrFail();
        $this->assertSame(2, $request->documents_count);
        $this->assertSame(KycStatus::Submitted, $company->refresh()->kyc_status);

        app(ReviewKyc::class)->approve($this->staff, $request);
        $this->assertTrue($company->refresh()->kyc_status->isCleared());
    }

    public function test_staff_can_ask_again_but_not_twice(): void
    {
        $buyer = User::factory()->create(['kyc_status' => KycStatus::Approved]);

        $this->actingAs($this->staff);
        Filament::setCurrentPanel('admin');

        Livewire::test(ViewBuyer::class, ['record' => $buyer->getRouteKey()])
            ->callAction('requestKyc', ['note' => 'Your ID has expired.'])
            ->assertActionHidden('requestKyc');

        $this->assertSame(KycStatus::Requested, $buyer->refresh()->kyc_status);
        $this->assertSame('Your ID has expired.', KycRequest::query()->where('user_id', $buyer->id)->value('note'));
        Notification::assertSentTo($buyer, AppNotification::class, fn (AppNotification $n) => $n->kind === NotificationKind::KycRequested);

        $this->refused(fn () => app(RequestKyc::class)->handle($this->staff, $buyer), 'note');
        $this->expectException(AuthorizationException::class);
        app(RequestKyc::class)->handle($buyer, $buyer);
    }

    public function test_documents_open_only_through_a_signed_link_for_allowed_viewers(): void
    {
        $buyer = $this->newBuyer();
        $request = app(SubmitKyc::class)->handle($buyer, $buyer, $this->idCard());
        $document = $request->documents()->firstOrFail();
        $link = KycDocumentController::link($document);

        $this->actingAs($this->staff)->get($link)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($this->staff)->get(route('kyc.document', $document))->assertForbidden();
        $this->actingAs(User::factory()->create())->get($link)->assertForbidden();
        $this->actingAs($buyer)->get($link)->assertOk();
    }

    private function expectsRefusal(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected an authorization refusal.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }
}
