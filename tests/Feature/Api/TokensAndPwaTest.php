<?php

namespace Tests\Feature\Api;

use App\Enums\NotificationCategory;
use App\Enums\NotificationKind;
use App\Livewire\Account\ApiTokens;
use App\Livewire\Account\NotificationSettings;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Support\InteractsWithOtp;
use Tests\TestCase;

/** The token page, web push subscriptions and the installable-app files. */
class TokensAndPwaTest extends TestCase
{
    use InteractsWithOtp;
    use RefreshDatabase;

    public function test_the_token_page_asks_for_a_fresh_code(): void
    {
        $this->actingAs(User::factory()->create())->get('/profile/api-tokens')->assertRedirect(route('otp.confirm'));
    }

    public function test_a_buyer_makes_a_token_sees_it_once_and_revokes_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withConfirmedOtp()->get('/profile/api-tokens')->assertOk()->assertSee('Create a token');

        $component = Livewire::test(ApiTokens::class)
            ->set('name', 'my phone app')
            ->set('access', 'write')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSee('Your new token');

        $token = $component->get('newToken');
        $this->assertStringContainsString('|', (string) $token);

        $this->withToken((string) $token)->getJson('/api/v1/me')->assertOk();
        $row = $user->tokens()->first();
        $this->assertSame(['read', 'write'], $row->abilities);
        $this->assertNotNull($row->expires_at);

        $component->call('dismiss')->assertSet('newToken', null)->assertSee('my phone app');

        $component->call('revoke', $row->id);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_tokens_need_a_name_and_are_capped(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withConfirmedOtp();

        Livewire::test(ApiTokens::class)->set('name', '')->call('create')->assertHasErrors('name');

        config(['auction.api.tokens_max' => 1]);
        $user->createToken('first');

        Livewire::test(ApiTokens::class)->set('name', 'second')->call('create')->assertHasErrors('name');
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_a_stale_session_cannot_create_a_token_through_livewire(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ApiTokens::class)->set('name', 'x')->call('create')->assertForbidden();
    }

    public function test_staff_have_no_tokens_page_and_nobody_else_can_revoke_a_token(): void
    {
        $this->actingAs(User::factory()->platformStaff()->create())->withConfirmedOtp()->get('/profile/api-tokens')->assertForbidden();

        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        $token = $theirs->createToken('theirs')->accessToken;

        $this->actingAs($mine)->withConfirmedOtp();
        Livewire::test(ApiTokens::class)->call('revoke', $token->id);

        $this->assertSame(1, $theirs->tokens()->count());
    }

    public function test_push_is_a_channel_only_when_keys_exist_and_the_user_wants_it(): void
    {
        $user = User::factory()->create();
        $notification = new AppNotification(NotificationKind::Outbid, 'You were outbid', 'Someone bid more.', '/auctions/5?x=1');

        config(['webpush.vapid.public_key' => null]);
        $this->assertNotContains(WebPushChannel::class, $notification->via($user));

        config(['webpush.vapid.public_key' => 'BKey']);
        $this->assertContains(WebPushChannel::class, $notification->via($user));

        $user->notification_preferences = [NotificationCategory::Bidding->value => ['bell' => true, 'mail' => false, 'push' => false]];
        $this->assertNotContains(WebPushChannel::class, $notification->via($user));
        $this->assertContains('database', $notification->via($user));

        $message = $notification->toWebPush($user)->toArray();
        $this->assertSame('You were outbid', $message['title']);
        $this->assertSame('/auctions/5?x=1', $message['data']['url']);
    }

    public function test_the_settings_page_saves_the_push_choice(): void
    {
        config(['webpush.vapid.public_key' => 'BKey']);
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(NotificationSettings::class)
            ->assertSee('Phone / browser')
            ->set('choices.bidding.push', false)
            ->call('save');

        $this->assertFalse($user->refresh()->wantsNotification(NotificationCategory::Bidding, 'push'));
        $this->assertTrue($user->wantsNotification(NotificationCategory::Orders, 'push'));
    }

    public function test_a_browser_subscribes_and_unsubscribes(): void
    {
        $user = User::factory()->create();
        $payload = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'keys' => ['p256dh' => 'pk', 'auth' => 'at'], 'contentEncoding' => 'aes128gcm'];

        $this->postJson('/push/subscriptions', $payload)->assertUnauthorized();

        $this->actingAs($user)->postJson('/push/subscriptions', $payload)->assertCreated();
        $this->assertSame(1, $user->pushSubscriptions()->count());

        // The same browser again is one row, not two.
        $this->postJson('/push/subscriptions', $payload)->assertCreated();
        $this->assertSame(1, $user->pushSubscriptions()->count());

        $this->deleteJson('/push/subscriptions', ['endpoint' => $payload['endpoint']])->assertOk();
        $this->assertSame(0, $user->pushSubscriptions()->count());
    }

    public function test_only_real_push_services_are_accepted(): void
    {
        $this->actingAs(User::factory()->create());
        $keys = ['p256dh' => 'pk', 'auth' => 'at'];

        foreach (['http://fcm.googleapis.com/x', 'https://evil.example.com/x', 'https://127.0.0.1/x', 'https://googleapis.com.evil.test/x', 'https://notgoogleapis.com/x'] as $endpoint) {
            $this->postJson('/push/subscriptions', ['endpoint' => $endpoint, 'keys' => $keys])->assertUnprocessable();
        }

        $this->postJson('/push/subscriptions', ['endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/x', 'keys' => $keys])->assertCreated();
        $this->postJson('/push/subscriptions', ['endpoint' => 'https://web.push.apple.com/x', 'keys' => $keys])->assertCreated();
    }

    public function test_the_oldest_browsers_are_dropped_past_the_limit(): void
    {
        config(['auction.push.max_per_user' => 2]);
        $user = User::factory()->create();
        $this->actingAs($user);

        foreach (['a', 'b', 'c'] as $name) {
            $this->postJson('/push/subscriptions', ['endpoint' => "https://fcm.googleapis.com/fcm/send/{$name}", 'keys' => ['p256dh' => 'pk', 'auth' => 'at']])->assertCreated();
        }

        $this->assertSame(['https://fcm.googleapis.com/fcm/send/b', 'https://fcm.googleapis.com/fcm/send/c'], $user->pushSubscriptions()->orderBy('id')->pluck('endpoint')->all());
    }

    public function test_the_api_documentation_is_served(): void
    {
        $this->get('/docs/api')->assertOk()->assertSee('/api/v1/auctions', false);
    }

    public function test_the_app_can_be_installed(): void
    {
        $manifest = $this->get('/manifest.webmanifest')->assertOk()->assertHeader('Content-Type', 'application/manifest+json');

        $this->assertSame('standalone', $manifest->json('display'));
        $this->assertSame(config('app.name'), $manifest->json('name'));
        $this->assertContains('maskable', array_column($manifest->json('icons'), 'purpose'));

        foreach ($manifest->json('icons') as $icon) {
            $this->assertFileExists(public_path($icon['src']));
        }

        $this->get('/offline')->assertOk()->assertSee('You are offline');
        $this->get('/')->assertOk()->assertSee('rel="manifest"', false)->assertSee('apple-touch-icon', false);
        $this->assertFileExists(public_path('sw.js'));
    }
}
