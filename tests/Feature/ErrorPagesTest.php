<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/** With debug off, visitors see the site's own error pages and nothing about the failure. */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        Route::middleware('web')->get('/_test/crash', fn () => throw new RuntimeException('secret table users_private on 10.0.0.5'));
        Route::middleware('web')->get('/_test/forbidden', fn () => abort(403, 'internal policy detail'));
    }

    public function test_a_server_error_shows_no_details(): void
    {
        $this->get('/_test/crash')
            ->assertStatus(500)
            ->assertSee(__('Something went wrong'))
            ->assertDontSee('secret table')
            ->assertDontSee('RuntimeException')
            ->assertDontSee('ErrorPagesTest')
            ->assertDontSee(base_path(), false);
    }

    public function test_client_errors_use_the_site_pages(): void
    {
        $this->get('/no-such-page')->assertNotFound()->assertSee(__('Page not found'));
        $this->get('/_test/forbidden')->assertForbidden()->assertSee(__('You can\'t open this page'))->assertDontSee('internal policy detail');
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden()->assertSee('403');
    }
}
