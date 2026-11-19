<?php

namespace Tests\Feature\Content;

use App\Actions\Settings\UpdateBranding;
use App\Enums\NotificationKind;
use App\Filament\Admin\Pages\Branding as BrandingPage;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Services\Branding\Branding;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Platform branding: colour, logo and favicon on the site, the panels and emails. */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_the_built_in_look_is_used_until_the_owner_sets_one(): void
    {
        $this->assertNull(app(Branding::class)->css());

        $this->get('/')->assertOk()
            ->assertDontSee('--color-brand-600:', false)
            ->assertSee(asset('favicon.ico'), false);
    }

    public function test_the_owner_sets_the_colour_logo_and_favicon(): void
    {
        $owner = User::factory()->systemOwner()->create();

        app(UpdateBranding::class)->handle(
            $owner,
            '#0F766E',
            UploadedFile::fake()->image('logo.png', 800, 200),
            favicon: UploadedFile::fake()->image('icon.png', 256, 256),
        );

        $branding = app(Branding::class);
        $this->assertSame('#0f766e', $branding->color());
        $this->assertStringEndsWith('.png', (string) $branding->logoPath());
        Storage::disk('public')->assertExists([(string) $branding->logoPath(), (string) $branding->faviconPath()]);

        // The site: the brand scale, the logo instead of the wordmark, the favicon.
        $this->get('/')->assertOk()
            ->assertSee('--color-brand-600:', false)
            ->assertSee((string) $branding->logoUrl(), false)
            ->assertSee((string) $branding->faviconUrl(), false);
        $this->get('/login')->assertOk()->assertSee('--color-brand-600:', false);

        // The panels take the colour as their primary scale.
        $this->assertSame($branding->palette(), Filament::getPanel('admin')->getColors()['primary']);

        // Emails: the header band in the colour, with the logo.
        $mail = (new AppNotification(
            NotificationKind::NewTicket, 'Hello', 'Body', '/support',
        ))->toMail($owner)->render();
        $this->assertStringContainsString('background-color: #0f766e', (string) $mail);
        $this->assertStringContainsString((string) $branding->logoUrl(), (string) $mail);

        // Replacing the logo deletes the old file; removing the favicon deletes it.
        $oldLogo = (string) $branding->logoPath();
        $oldFavicon = (string) $branding->faviconPath();
        app(UpdateBranding::class)->handle($owner, null, UploadedFile::fake()->image('new.png', 400, 100), removeFavicon: true);

        Storage::disk('public')->assertMissing([$oldLogo, $oldFavicon]);
        $this->assertNull($branding->color());
        $this->assertNull($branding->faviconPath());
        $this->assertNotSame($oldLogo, $branding->logoPath());
        $this->assertDatabaseHas('activity_log', ['log_name' => 'admin', 'event' => 'branding_changed', 'causer_id' => $owner->id]);
    }

    public function test_a_bad_colour_or_picture_is_refused(): void
    {
        $owner = User::factory()->systemOwner()->create();

        try {
            app(UpdateBranding::class)->handle($owner, 'red; } body { display: none');
            $this->fail('A colour that is not #rrggbb was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('color', $e->errors());
        }

        $this->expectException(ValidationException::class);
        app(UpdateBranding::class)->handle($owner, null, UploadedFile::fake()->create('logo.png', 10, 'image/png'));
    }

    public function test_only_the_system_owner_changes_branding(): void
    {
        $staff = User::factory()->platformStaff()->create();

        $this->actingAs($staff)->get('/admin/branding')->assertForbidden();

        $this->expectException(AuthorizationException::class);
        app(UpdateBranding::class)->handle($staff, '#000000');
    }

    public function test_the_admin_page_saves_the_colour(): void
    {
        $owner = User::factory()->systemOwner()->create();
        $this->actingAs($owner);
        Filament::setCurrentPanel('admin');

        $this->get('/admin/branding')->assertOk();

        Livewire::test(BrandingPage::class)
            ->set('data.color', '#7c3aed')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('#7c3aed', app(Branding::class)->color());
    }
}
