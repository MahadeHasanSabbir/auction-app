<?php

namespace Tests\Feature\Notices;

use App\Enums\NoticeAudience;
use App\Filament\Admin\Resources\Notices\Pages\ManageNotices;
use App\Models\Notice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\InteractsWithCompanies;
use Tests\TestCase;

/** Notices: who sees which, the banner, the list page and the admin screen. */
class NoticesTest extends TestCase
{
    use InteractsWithCompanies;
    use RefreshDatabase;

    public function test_each_viewer_sees_the_notices_meant_for_them(): void
    {
        $everyone = Notice::factory()->create(['title' => 'Open to everyone', 'published_at' => now()->subDays(3)]);
        $buyers = Notice::factory()->create(['title' => 'Only for buyers', 'audience' => NoticeAudience::Buyers, 'published_at' => now()->subDays(2)]);
        $sellers = Notice::factory()->create(['title' => 'Only for sellers', 'audience' => NoticeAudience::Sellers, 'published_at' => now()->subDay()]);
        Notice::factory()->draft()->create(['title' => 'Not yet out']);
        Notice::factory()->expired()->create(['title' => 'Old news']);

        $this->get('/notices')->assertOk()
            ->assertSee([$everyone->title, $buyers->title])
            ->assertDontSee([$sellers->title, 'Not yet out', 'Old news']);

        // The newest one for the viewer is the banner on other pages.
        $this->get('/')->assertSee($buyers->title)->assertDontSee($sellers->title);

        [$company, $owner] = $this->company($this->plan());
        $this->actingAs($owner)->get('/notices')->assertSee([$everyone->title, $sellers->title])->assertDontSee($buyers->title);
        $this->actingAs($owner)->get('/seller/'.$company->slug)->assertOk()->assertSee($sellers->title);
    }

    public function test_staff_publish_notices_from_the_admin_panel(): void
    {
        $staff = User::factory()->platformStaff()->create();
        $this->actingAs($staff);
        Filament::setCurrentPanel('admin');

        $this->get('/admin/notices')->assertOk();

        Livewire::test(ManageNotices::class)
            ->callAction('create', [
                'title' => 'Maintenance on Friday',
                'body' => 'The site is down from 2 to 3 am.',
                'audience' => NoticeAudience::All->value,
                'published_at' => now()->subMinute()->toDateTimeString(),
            ])
            ->assertHasNoActionErrors();

        $notice = Notice::query()->where('title', 'Maintenance on Friday')->firstOrFail();
        $this->assertSame($staff->id, $notice->created_by);
        $this->get('/')->assertSee('Maintenance on Friday');

        $this->actingAs(User::factory()->create())->get('/admin/notices')->assertForbidden();
    }
}
