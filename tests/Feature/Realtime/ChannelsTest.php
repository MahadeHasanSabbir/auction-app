<?php

namespace Tests\Feature\Realtime;

use App\Models\Auction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Who may subscribe to which private and presence channel. Uses the Reverb
 * driver with made-up keys: signing a subscription needs no running server.
 */
class ChannelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
            'broadcasting.connections.reverb.options.host' => 'localhost',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
        ]);
        Broadcast::forgetDrivers();

        // The channel rules were registered on the test's null broadcaster; register them again.
        require base_path('routes/channels.php');
    }

    private function subscribe(string $channel): TestResponse
    {
        return $this->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
    }

    public function test_a_user_may_listen_only_to_their_own_private_channel(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user);

        $this->subscribe('private-App.Models.User.'.$user->id)->assertOk();
        $this->subscribe('private-App.Models.User.'.$other->id)->assertForbidden();
    }

    public function test_guests_cannot_join_private_or_presence_channels(): void
    {
        $auction = Auction::factory()->live()->create();

        $this->subscribe('presence-auction.'.$auction->id.'.viewers')->assertForbidden();
        $this->subscribe('private-App.Models.User.1')->assertForbidden();
    }

    public function test_viewers_of_a_public_auction_are_known_by_a_reference_not_their_id(): void
    {
        $user = User::factory()->create(['name' => 'Ayesha Rahman']);
        $auction = Auction::factory()->live()->create();

        $response = $this->actingAs($user)->subscribe('presence-auction.'.$auction->id.'.viewers')->assertOk();

        $data = json_decode((string) $response->json('channel_data'), true);

        $this->assertSame($user->getAuthIdentifierForBroadcasting(), $data['user_id']);
        $this->assertNotEquals((string) $user->id, (string) $data['user_id']);
        $this->assertStringNotContainsString('Ayesha', (string) $response->json('channel_data'));
    }

    public function test_nobody_joins_the_viewers_of_an_auction_that_is_not_public(): void
    {
        $draft = Auction::factory()->create();

        $this->actingAs(User::factory()->create())
            ->subscribe('presence-auction.'.$draft->id.'.viewers')
            ->assertForbidden();
    }
}
