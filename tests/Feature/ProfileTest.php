<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed_for_every_kind_of_account(): void
    {
        $accounts = [
            User::factory()->create(),
            User::factory()->companyOwner()->create(),
            User::factory()->platformStaff()->create(),
        ];

        foreach ($accounts as $user) {
            $this->actingAs($user)->get('/profile')->assertOk()->assertSee($user->email);
        }
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/profile')->assertRedirect('/login');
        $this->patch('/profile', ['name' => 'Nobody'])->assertRedirect('/login');
    }

    public function test_profile_details_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'phone' => '01711111111',
                'address' => "House 1, Road 2\nDhaka",
                'timezone' => 'Europe/London',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('01711111111', $user->phone);
        $this->assertSame("House 1, Road 2\nDhaka", $user->address);
        $this->assertSame('Europe/London', $user->timezone);
        $this->assertSame('Europe/London', $user->displayTimezone());
    }

    public function test_the_profile_form_cannot_change_the_email_role_or_status(): void
    {
        $user = User::factory()->create(['email' => 'me@example.com']);

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'other@example.com',
                'role' => 'system_owner',
                'is_active' => false,
                'kyc_status' => 'approved',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $user->fresh();

        $this->assertSame('me@example.com', $fresh->email);
        $this->assertTrue($fresh->isBuyer());
        $this->assertTrue($fresh->is_active);
        $this->assertSame($user->kyc_status, $fresh->kyc_status);
        $this->assertNotNull($fresh->email_verified_at);
    }

    public function test_an_unknown_timezone_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/profile', ['name' => $user->name, 'timezone' => 'Mars/Olympus_Mons'])
            ->assertSessionHasErrors('timezone');
    }

    public function test_a_picture_is_re_encoded_cropped_and_stored_under_a_random_name(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => $user->name,
                'avatar' => UploadedFile::fake()->image('my holiday photo.png', 1200, 800),
            ])
            ->assertSessionHasNoErrors();

        $path = $user->refresh()->avatar_path;

        $this->assertNotNull($path);
        $this->assertMatchesRegularExpression('#^avatars/[A-Za-z0-9]{40}\.webp$#', $path);
        Storage::disk('public')->assertExists($path);

        // Stored as a WebP square no larger than 400 px, whatever was uploaded.
        $info = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame('image/webp', $info['mime']);
        $this->assertSame([400, 400], [$info[0], $info[1]]);

        $this->assertNotNull($user->avatarUrl());
    }

    public function test_replacing_or_removing_the_picture_deletes_the_old_file(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $this->actingAs($user)->patch('/profile', [
            'name' => $user->name,
            'avatar' => UploadedFile::fake()->image('one.jpg', 300, 300),
        ]);
        $first = $user->refresh()->avatar_path;

        $this->patch('/profile', [
            'name' => $user->name,
            'avatar' => UploadedFile::fake()->image('two.jpg', 300, 300),
        ]);
        $second = $user->refresh()->avatar_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $this->patch('/profile', ['name' => $user->name, 'remove_avatar' => '1'])->assertSessionHasNoErrors();

        $this->assertNull($user->refresh()->avatar_path);
        Storage::disk('public')->assertMissing($second);
    }

    public function test_a_file_that_is_not_a_picture_is_refused(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $uploads = [
            // A script renamed to look like a picture.
            UploadedFile::fake()->createWithContent('avatar.png', '<?php echo "hello";'),
            UploadedFile::fake()->create('document.pdf', 20, 'application/pdf'),
            // A real picture, but larger than the 4 MB limit.
            UploadedFile::fake()->image('huge.jpg')->size(5 * 1024),
        ];

        foreach ($uploads as $upload) {
            $this->actingAs($user)
                ->patch('/profile', ['name' => $user->name, 'avatar' => $upload])
                ->assertSessionHasErrors('avatar');
        }

        $this->assertNull($user->refresh()->avatar_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
