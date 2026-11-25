<?php

namespace Tests\Feature\Api;

use App\Filament\Admin\Resources\ApiRequests\Pages\ListApiRequests;
use App\Filament\Admin\Resources\ApiTokens\Pages\ListApiTokens;
use App\Filament\Admin\Resources\ApiTokens\Pages\ViewApiToken;
use App\Filament\Admin\Resources\ApiTokens\RelationManagers\RequestsRelationManager;
use App\Models\ApiRequest;
use App\Models\ApiToken;
use App\Models\Auction;
use App\Models\Company;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** The admin's view of the API: who has tokens, what they did, and cutting one off. */
class ApiAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $buyer;

    private User $seller;

    private Company $company;

    private ApiToken $buyerToken;

    private Auction $auction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->platformStaff()->create();
        $this->buyer = User::factory()->create(['name' => 'Rahim Uddin', 'email' => 'rahim@example.test']);
        $this->company = Company::factory()->create(['name' => 'Dhaka Antiques']);
        $this->seller = User::factory()->companyOwner($this->company)->create();

        $this->auction = Auction::factory()->live()->create();
        $this->buyerToken = $this->buyer->createToken('phone app', ['read', 'write'])->accessToken;
    }

    private function openAdmin(): void
    {
        // An earlier API call in the same test leaves the sanctum guard as the default and keeps its user.
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->actingAs($this->staff);
        Filament::setCurrentPanel('admin');
    }

    public function test_an_authenticated_request_is_logged_with_its_route_pattern(): void
    {
        $plain = $this->buyer->createToken('logger', ['read'])->plainTextToken;

        $this->withToken($plain)->getJson('/api/v1/wallet?secret=1')->assertNotFound();
        $this->withToken($plain)->getJson('/api/v1/notifications')->assertOk();

        $row = ApiRequest::query()->where('route', 'api/v1/notifications')->firstOrFail();
        $this->assertSame($this->buyer->id, $row->user_id);
        $this->assertSame('logger', $row->token_name);
        $this->assertSame('GET', $row->method);
        $this->assertSame(200, $row->status);

        // The query string is not kept, and a refusal is logged with its status.
        $this->assertSame(0, ApiRequest::query()->where('route', 'like', '%secret%')->count());
        $this->assertSame(404, ApiRequest::query()->where('route', 'api/v1/wallet')->value('status'));
    }

    public function test_guests_and_bad_tokens_are_not_logged(): void
    {
        $this->getJson('/api/v1/auctions')->assertOk();
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken('999|nonsense')->getJson('/api/v1/me')->assertUnauthorized();

        $this->assertSame(0, ApiRequest::query()->count());
    }

    public function test_a_refused_request_from_a_token_is_logged_too(): void
    {
        $plain = $this->buyer->createToken('ro', ['read'])->plainTextToken;

        $this->withToken($plain)->postJson("/api/v1/auctions/{$this->auction->id}/watch")->assertForbidden();

        $this->assertSame(403, ApiRequest::query()->firstOrFail()->status);
    }

    public function test_staff_see_who_has_tokens_and_which_company(): void
    {
        $this->seller->createToken('erp sync', ['read']);
        $this->openAdmin();

        Livewire::test(ListApiTokens::class)
            ->assertSuccessful()
            ->assertSee('Rahim Uddin')
            ->assertSee('rahim@example.test')
            ->assertSee('phone app')
            ->assertSee('Dhaka Antiques')
            ->assertSee('erp sync')
            ->assertSee('Read and write')
            ->assertSee('Never used');

        Livewire::test(ListApiTokens::class)->searchTable('rahim')->assertSee('phone app')->assertDontSee('erp sync');
        Livewire::test(ListApiTokens::class)->filterTable('access', 'write')->assertSee('phone app')->assertDontSee('erp sync');
        Livewire::test(ListApiTokens::class)->filterTable('company', $this->company->id)->assertSee('erp sync')->assertDontSee('phone app');
    }

    public function test_the_token_value_is_never_shown(): void
    {
        $plain = $this->buyer->createToken('secret one', ['read'])->plainTextToken;
        $this->openAdmin();

        $html = Livewire::test(ListApiTokens::class)->html();

        $this->assertStringNotContainsString(explode('|', $plain)[1], $html);
    }

    public function test_the_token_page_lists_its_requests(): void
    {
        $plain = $this->buyer->createToken('busy', ['read'])->plainTextToken;
        $this->withToken($plain)->getJson('/api/v1/notifications')->assertOk();
        $token = ApiToken::query()->where('name', 'busy')->firstOrFail();
        $this->openAdmin();

        Livewire::test(ViewApiToken::class, ['record' => $token->getKey()])->assertSuccessful()->assertSee('busy');

        Livewire::test(RequestsRelationManager::class, ['ownerRecord' => $token, 'pageClass' => ViewApiToken::class])
            ->assertSuccessful()
            ->assertSee('/api/v1/notifications');
    }

    public function test_the_activity_list_filters_by_result(): void
    {
        $plain = $this->buyer->createToken('mix', ['read'])->plainTextToken;
        $this->withToken($plain)->getJson('/api/v1/notifications')->assertOk();
        $this->withToken($plain)->postJson("/api/v1/auctions/{$this->auction->id}/watch")->assertForbidden();
        $this->openAdmin();

        Livewire::test(ListApiRequests::class)->assertSee('/api/v1/notifications')->assertSee('/api/v1/auctions/{auction}/watch');
        Livewire::test(ListApiRequests::class)->filterTable('result', 'client')->assertSee('/auctions/{auction}/watch')->assertDontSee('/api/v1/notifications');
    }

    public function test_staff_revoke_a_token_with_a_reason_and_it_stops_working(): void
    {
        $plain = $this->buyer->createToken('leaked', ['read'])->plainTextToken;
        $this->withToken($plain)->getJson('/api/v1/me')->assertOk();
        $token = ApiToken::query()->where('name', 'leaked')->firstOrFail();
        $this->openAdmin();

        Livewire::test(ListApiTokens::class)
            ->callAction(TestAction::make('revoke')->table($token), ['reason' => 'Key was posted publicly'])
            ->assertHasNoActionErrors();

        $this->assertNull(ApiToken::query()->find($token->id));

        // The Sanctum guard remembers the user inside one test process; start a fresh request.
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();

        $log = Activity::query()->where('event', 'api_token_revoked')->firstOrFail();
        $this->assertSame($this->staff->id, $log->causer_id);
        $this->assertSame('Key was posted publicly', $log->properties['reason']);
        $this->assertSame($this->buyer->id, (int) $log->subject_id);
        // Only that token went; the account's other token is untouched.
        $this->assertSame(1, $this->buyer->tokens()->count());
    }

    public function test_a_revoke_needs_a_reason(): void
    {
        $token = $this->buyerToken;
        $this->openAdmin();

        Livewire::test(ListApiTokens::class)
            ->callAction(TestAction::make('revoke')->table($token), ['reason' => ''])
            ->assertHasActionErrors(['reason']);

        $this->assertNotNull(ApiToken::query()->find($token->id));
    }

    public function test_only_platform_accounts_may_see_or_revoke(): void
    {
        $this->assertTrue(Gate::forUser($this->staff)->allows('viewAny', ApiToken::class));
        $this->assertTrue(Gate::forUser($this->staff)->allows('viewAny', ApiRequest::class));

        foreach ([$this->buyer, $this->seller] as $user) {
            $this->assertFalse(Gate::forUser($user)->allows('viewAny', ApiToken::class));
            $this->assertFalse(Gate::forUser($user)->allows('revoke', $this->buyerToken));
            $this->assertFalse(Gate::forUser($user)->allows('viewAny', ApiRequest::class));
        }

        $this->assertFalse(Gate::forUser(User::factory()->platformStaff()->inactive()->create())->allows('viewAny', ApiToken::class));
    }

    public function test_old_requests_are_pruned(): void
    {
        config(['auction.api.log_days' => 30]);
        $old = ApiRequest::query()->forceCreate(['user_id' => $this->buyer->id, 'method' => 'GET', 'route' => 'api/v1/me', 'status' => 200, 'created_at' => now()->subDays(31)]);
        $fresh = ApiRequest::query()->forceCreate(['user_id' => $this->buyer->id, 'method' => 'GET', 'route' => 'api/v1/me', 'status' => 200, 'created_at' => now()->subDays(2)]);

        Artisan::call('model:prune', ['--model' => [ApiRequest::class]]);

        $this->assertNull(ApiRequest::query()->find($old->id));
        $this->assertNotNull(ApiRequest::query()->find($fresh->id));
    }
}
