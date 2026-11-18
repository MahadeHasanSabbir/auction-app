<?php

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Models\User;
use Filament\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The one notification the platform sends. What it says is in the title and
 * body; what kind it is decides which of the user's switches apply (the bell,
 * the email). The bell reads `data.title` and `data.body`.
 */
class AppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** The page it leads to, as a path on this site. */
    public readonly string $path;

    public function __construct(
        public readonly NotificationKind $kind,
        public readonly string $title,
        public readonly string $body,
        string $url,
    ) {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);
        $this->path = $query ? "{$path}?{$query}" : $path;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return [];
        }

        $category = $this->kind->category();
        $channels = [];

        if ($notifiable->wantsNotification($category, 'bell')) {
            $channels[] = 'database';
            $channels[] = 'broadcast';
        }

        if ($notifiable->wantsNotification($category, 'mail') && $notifiable->hasVerifiedEmail()) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /** @return array<string, string> */
    public function viaQueues(): array
    {
        return [
            'mail' => 'default',
            'database' => 'default',
            'broadcast' => (string) config('auction.realtime.queue'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->line($this->body)
            ->action(__('Open'), url($this->path))
            ->line(__('You can choose which emails you get in your notification settings.'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        // The site's bell reads kind, title, body and url; the panels' bell (Filament) reads its own format.
        $panel = FilamentNotification::make()
            ->title($this->title)
            ->body($this->body)
            ->actions([FilamentAction::make('open')->label(__('Open'))->url($this->path)->markAsRead()])
            ->getDatabaseMessage();
        unset($panel['id']);

        return [
            ...$panel,
            'kind' => $this->kind->value,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->path,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
