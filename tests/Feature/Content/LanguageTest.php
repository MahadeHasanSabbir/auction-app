<?php

namespace Tests\Feature\Content;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** English and Bangla on the public site; the panels and emails stay English. */
class LanguageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_switches_the_site_to_bangla_and_back(): void
    {
        $this->get('/')->assertOk()->assertSee('<html lang="en">', false)->assertSee('বাংলা');

        $this->from('/auctions')->post('/locale', ['locale' => 'bn'])->assertRedirect('/auctions');

        $this->get('/auctions')->assertOk()
            ->assertSee('<html lang="bn">', false)
            ->assertSee('হোম')
            ->assertSee('English');
        $this->get('/login')->assertOk()->assertSee('সাইন ইন');

        $this->post('/locale', ['locale' => 'en']);
        $this->get('/auctions')->assertSee('Home')->assertDontSee('হোম');
    }

    public function test_an_unknown_language_is_refused(): void
    {
        $this->post('/locale', ['locale' => 'fr'])->assertSessionHasErrors('locale');
        $this->get('/')->assertSee('<html lang="en">', false);
    }

    public function test_a_signed_in_users_choice_is_saved_on_the_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/locale', ['locale' => 'bn']);
        $this->assertSame('bn', $user->refresh()->locale);

        // A new session picks it up from the account.
        $this->flushSession();
        $this->actingAs($user)->get('/')->assertSee('<html lang="bn">', false);
    }

    public function test_validation_messages_are_translated(): void
    {
        $this->withSession(['locale' => 'bn'])
            ->post('/register', ['account_type' => 'buyer'])
            ->assertSessionHasErrors(['email' => 'ইমেইল দিতে হবে।']);
    }

    public function test_the_panels_and_emails_stay_in_english(): void
    {
        $staff = User::factory()->platformStaff()->create(['locale' => 'bn']);

        $this->actingAs($staff)->withSession(['locale' => 'bn'])
            ->get('/admin')->assertOk()->assertSee('lang="en"', false)->assertDontSee('lang="bn"', false);

        $this->assertSame('en', $staff->preferredLocale());
    }

    public function test_every_bangla_translation_is_filled_and_keeps_its_placeholders(): void
    {
        /** @var array<string, string> $bn */
        $bn = json_decode((string) file_get_contents(lang_path('bn.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($bn as $english => $bangla) {
            $this->assertNotSame('', trim($bangla), "Missing Bangla for: {$english}");

            preg_match_all('/:[a-z_]+/', $english, $expected);
            foreach (array_unique($expected[0]) as $placeholder) {
                $this->assertStringContainsString($placeholder, $bangla, "{$placeholder} lost in: {$english}");
            }
        }
    }
}
