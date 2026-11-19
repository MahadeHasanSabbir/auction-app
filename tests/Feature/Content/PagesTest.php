<?php

namespace Tests\Feature\Content;

use App\Actions\Content\UpdatePage;
use App\Enums\SitePage;
use App\Filament\Admin\Resources\Pages\Pages\EditPage;
use App\Models\Page;
use App\Models\User;
use Database\Seeders\PlatformSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The text pages staff edit: terms, privacy, FAQ and about. */
class PagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeder_creates_every_page_and_they_render(): void
    {
        $this->seed(PlatformSeeder::class);

        $this->assertSame(count(SitePage::cases()), Page::query()->count());

        $this->get('/terms')->assertOk()->assertSee('Terms of use')->assertSee('Bidding');
        $this->get('/privacy')->assertOk()->assertSee('Privacy policy');
        $this->get('/faq')->assertOk()->assertSee('How much can I bid?');
        $this->get('/about')->assertOk()->assertSee('About us');

        // Running it again keeps what staff wrote.
        Page::for(SitePage::Terms)?->forceFill(['body_en' => '<p>Our own terms.</p>'])->save();
        $this->seed(PlatformSeeder::class);
        $this->get('/terms')->assertSee('Our own terms.');
    }

    public function test_a_page_without_text_is_not_found(): void
    {
        $this->get('/faq')->assertNotFound();

        Page::factory()->create(['slug' => SitePage::Faq, 'body_en' => null]);
        $this->get('/faq')->assertNotFound();

        // /about always has its built-in introduction.
        $this->get('/about')->assertOk();
    }

    public function test_staff_edit_a_page_and_the_html_is_cleaned(): void
    {
        $staff = User::factory()->platformStaff()->create();
        $page = Page::factory()->create(['slug' => SitePage::Terms]);

        app(UpdatePage::class)->handle($staff, $page, [
            'title_en' => 'Terms',
            'title_bn' => 'শর্তাবলি',
            'body_en' => '<h2 onclick="steal()">Rules</h2><script>alert(1)</script><p style="position:fixed">Be fair. <a href="javascript:alert(1)">x</a> <a href="https://example.com">site</a></p><iframe src="https://evil.test"></iframe>',
            'body_bn' => '<p>ন্যায্য থাকুন।</p>',
        ]);

        $page->refresh();
        $this->assertStringContainsString('<h2>Rules</h2>', (string) $page->body_en);
        $this->assertStringNotContainsString('script', (string) $page->body_en);
        $this->assertStringNotContainsString('onclick', (string) $page->body_en);
        $this->assertStringNotContainsString('style=', (string) $page->body_en);
        $this->assertStringNotContainsString('javascript:', (string) $page->body_en);
        $this->assertStringNotContainsString('iframe', (string) $page->body_en);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', (string) $page->body_en);
        $this->assertSame($staff->id, $page->updated_by);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'admin', 'event' => 'page_updated', 'causer_id' => $staff->id]);

        $this->get('/terms')->assertOk()->assertSee('Be fair.')->assertDontSee('alert(1)', false);

        // Bangla readers get the translation; without one, the English page.
        $this->withSession(['locale' => 'bn'])->get('/terms')->assertSee('শর্তাবলি')->assertSee('ন্যায্য থাকুন।');
        $page->forceFill(['title_bn' => null, 'body_bn' => null])->save();
        $this->withSession(['locale' => 'bn'])->get('/terms')->assertSee('Be fair.');
    }

    public function test_buyers_and_sellers_cannot_edit_pages(): void
    {
        $page = Page::factory()->create();

        $this->expectException(AuthorizationException::class);
        app(UpdatePage::class)->handle(User::factory()->create(), $page, ['title_en' => 'Mine']);
    }

    public function test_the_admin_screen_edits_a_page(): void
    {
        $staff = User::factory()->platformStaff()->create();
        $page = Page::factory()->create(['slug' => SitePage::Privacy, 'title_en' => 'Privacy']);
        $this->actingAs($staff);
        Filament::setCurrentPanel('admin');

        $this->get('/admin/pages')->assertOk()->assertSee('Privacy');

        Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
            ->fillForm(['title_en' => 'Your privacy', 'body_en' => '<p>We keep little.</p>'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Your privacy', $page->refresh()->title_en);
        $this->get('/privacy')->assertSee('We keep little.');
    }
}
