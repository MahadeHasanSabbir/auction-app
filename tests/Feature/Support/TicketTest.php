<?php

namespace Tests\Feature\Support;

use App\Actions\Support\AssignTicket;
use App\Actions\Support\CloseTicket;
use App\Actions\Support\OpenTicket;
use App\Actions\Support\ReplyToTicket;
use App\Enums\ConversationStatus;
use App\Enums\ConversationType;
use App\Enums\NotificationKind;
use App\Livewire\Contact;
use App\Livewire\Support\Index;
use App\Livewire\Support\Show;
use App\Models\Conversation;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\GuestTicketReply;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Support tickets, the staff's actions on them, and the public contact form. */
class TicketTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->staff = User::factory()->platformStaff()->create();
    }

    private function ticket(?User $user = null): Conversation
    {
        return app(OpenTicket::class)->handle($user ?? $this->user, [
            'subject' => 'Payment problem',
            'body' => 'My deposit has not arrived in my wallet yet.',
        ]);
    }

    public function test_a_signed_in_user_opens_a_ticket(): void
    {
        $ticket = $this->ticket();

        $this->assertSame(ConversationType::Support, $ticket->type);
        $this->assertSame(ConversationStatus::Open, $ticket->status);
        $this->assertSame($this->user->id, $ticket->opened_by);
        $this->assertNull($ticket->guest_email);
        $this->assertSame(['My deposit has not arrived in my wallet yet.'], $ticket->messages()->pluck('body')->all());
        $this->assertNotNull($ticket->last_message_at);
    }

    public function test_a_visitor_opens_a_ticket_with_a_name_and_an_email(): void
    {
        $ticket = app(OpenTicket::class)->handle(null, [
            'name' => 'Nasrin Akter',
            'email' => 'Nasrin@Example.com',
            'subject' => 'Selling books',
            'body' => 'Can a company account list old books?',
        ]);

        $this->assertNull($ticket->opened_by);
        $this->assertSame('Nasrin Akter', $ticket->guest_name);
        $this->assertSame('nasrin@example.com', $ticket->guest_email);
        $this->assertNull($ticket->messages()->firstOrFail()->user_id);
    }

    public function test_the_ticket_is_validated_and_limited(): void
    {
        foreach ([
            [null, ['subject' => 'Hi', 'body' => 'Long enough message here.', 'name' => 'A B', 'email' => 'a@b.test']],
            [$this->user, ['subject' => 'Fine subject', 'body' => 'short']],
            [null, ['subject' => 'Fine subject', 'body' => 'Long enough message here.', 'name' => 'A B', 'email' => 'not-an-email']],
        ] as [$who, $input]) {
            try {
                app(OpenTicket::class)->handle($who, $input);
                $this->fail('Accepted bad input.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        config(['auction.messaging.tickets_per_hour' => 2]);
        $this->ticket();
        $this->ticket();

        $this->expectException(ValidationException::class);
        $this->ticket();
    }

    public function test_staff_reply_and_the_requester_is_told(): void
    {
        Notification::fake();
        $ticket = $this->ticket();

        app(ReplyToTicket::class)->handle($this->staff, $ticket, 'Deposits can take a few minutes; it is there now.');

        Notification::assertSentTo($this->user, AppNotification::class, fn (AppNotification $n) => $n->kind === NotificationKind::TicketReply && $n->path === "/support/{$ticket->id}");
        $this->assertSame(2, $ticket->messages()->count());
    }

    public function test_a_visitor_gets_the_answer_by_email(): void
    {
        Notification::fake();
        $ticket = app(OpenTicket::class)->handle(null, [
            'name' => 'Nasrin Akter',
            'email' => 'nasrin@example.com',
            'subject' => 'Selling books',
            'body' => 'Can a company account list old books?',
        ]);

        app(ReplyToTicket::class)->handle($this->staff, $ticket, 'Yes, any lawful item can be listed.');

        Notification::assertSentOnDemand(GuestTicketReply::class, fn (GuestTicketReply $n, array $channels, object $notifiable) => $notifiable->routes['mail'] === 'nasrin@example.com' && $n->ticketId === $ticket->id);
    }

    public function test_a_reply_reopens_a_closed_ticket_and_tells_the_assignee(): void
    {
        $ticket = $this->ticket();
        app(AssignTicket::class)->handle($this->staff, $ticket, $this->staff);
        app(CloseTicket::class)->handle($this->user, $ticket);
        $this->assertSame(ConversationStatus::Closed, $ticket->status);
        $this->assertNotNull($ticket->closed_at);

        Notification::fake();
        app(ReplyToTicket::class)->handle($this->user, $ticket, 'It is still not working, sorry.');

        $this->assertSame(ConversationStatus::Open, $ticket->refresh()->status);
        $this->assertNull($ticket->closed_at);
        Notification::assertSentTo($this->staff, AppNotification::class, fn (AppNotification $n) => $n->kind === NotificationKind::TicketReply);
    }

    public function test_only_the_requester_and_staff_may_act_on_a_ticket(): void
    {
        $ticket = $this->ticket();
        $stranger = User::factory()->create();

        foreach ([
            fn () => app(ReplyToTicket::class)->handle($stranger, $ticket, 'Hello'),
            fn () => app(CloseTicket::class)->handle($stranger, $ticket),
            fn () => app(AssignTicket::class)->handle($this->user, $ticket, $this->staff),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Allowed.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        // Only staff can take a ticket.
        $this->expectException(ValidationException::class);
        app(AssignTicket::class)->handle($this->staff, $ticket, $this->user);
    }

    public function test_the_requester_reads_and_answers_on_the_support_pages(): void
    {
        $ticket = $this->ticket();
        app(ReplyToTicket::class)->handle($this->staff, $ticket, 'We are looking into it.');
        $this->actingAs($this->user);

        $this->get('/support')->assertOk()->assertSee('Payment problem');
        $this->get("/support/{$ticket->id}")->assertOk()->assertSee('We are looking into it.')->assertSee('Support');

        Livewire::test(Show::class, ['conversation' => $ticket])
            ->call('markRead')
            ->set('body', 'Thank you!')
            ->call('reply')
            ->assertHasNoErrors()
            ->assertSee('Thank you!')
            ->call('close')
            ->assertSee('Closed');

        $this->assertSame(ConversationStatus::Closed, $ticket->refresh()->status);
    }

    public function test_nobody_else_opens_someone_elses_ticket(): void
    {
        $ticket = $this->ticket();

        $this->actingAs(User::factory()->create())->get("/support/{$ticket->id}")->assertNotFound();
    }

    public function test_the_support_page_opens_a_ticket(): void
    {
        $this->actingAs($this->user);

        $this->get('/support')->assertOk()->assertSee('How can we help?');

        Livewire::test(Index::class)
            ->set('subject', 'Closing my account')
            ->set('body', 'I would like to close my account please.')
            ->call('open')
            ->assertRedirect();

        $this->assertSame(1, Conversation::query()->where('opened_by', $this->user->id)->count());
    }

    public function test_a_visitor_uses_the_contact_form(): void
    {
        $this->get('/contact')->assertOk()->assertSee('Contact us');

        Livewire::test(Contact::class)
            ->set('name', 'Nasrin Akter')
            ->set('email', 'nasrin@example.com')
            ->set('subject', 'Selling books')
            ->set('body', 'Can a company account list old books?')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSee('Thank you');

        $ticket = Conversation::query()->firstOrFail();
        $this->assertNull($ticket->opened_by);
        $this->assertSame('nasrin@example.com', $ticket->guest_email);
    }

    public function test_the_contact_form_shows_errors_and_catches_bots(): void
    {
        Livewire::test(Contact::class)
            ->set('name', '')
            ->set('email', 'nope')
            ->set('subject', 'x')
            ->set('body', 'short')
            ->call('send')
            ->assertHasErrors(['name', 'email', 'subject', 'body']);

        Livewire::test(Contact::class)
            ->set('name', 'Spam Bot')
            ->set('email', 'bot@example.com')
            ->set('subject', 'Buy cheap watches')
            ->set('body', 'Visit my website for amazing offers.')
            ->set('website', 'http://spam.test')
            ->call('send')
            ->assertSee('Thank you');

        $this->assertSame(0, Conversation::query()->count());
    }

    public function test_a_signed_in_user_writing_through_the_contact_form_owns_the_ticket(): void
    {
        $this->actingAs($this->user);

        Livewire::test(Contact::class)
            ->assertSet('email', $this->user->email)
            ->set('subject', 'Question about fees')
            ->set('body', 'How much does a withdrawal cost me?')
            ->call('send')
            ->assertSee('support tickets');

        $this->assertSame($this->user->id, Conversation::query()->firstOrFail()->opened_by);
    }
}
