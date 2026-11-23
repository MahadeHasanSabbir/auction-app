<?php

namespace App\Livewire\Account;

use App\Http\Middleware\EnsureOtpConfirmed;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Personal access tokens for the API: made here, shown once, revocable. The
 * page sits behind a fresh emailed code like the other security settings.
 */
#[Layout('layouts.site')]
class ApiTokens extends Component
{
    public string $name = '';

    /** 'read' (GET only) or 'write'. */
    public string $access = 'read';

    /** The token just made, shown once and then forgotten. */
    public ?string $newToken = null;

    public function mount(): void
    {
        abort_unless($this->allowed($this->user()), 403);
    }

    public function create(): void
    {
        $user = $this->user();

        // Livewire actions do not pass through the route's middleware.
        abort_unless($this->allowed($user) && EnsureOtpConfirmed::confirmedIn(session()->driver()), 403);

        $this->validate([
            'name' => ['required', 'string', 'max:60'],
            'access' => ['required', Rule::in(['read', 'write'])],
        ]);

        if ($user->tokens()->count() >= (int) config('auction.api.tokens_max')) {
            throw ValidationException::withMessages(['name' => __('You already have :count tokens. Revoke one first.', ['count' => config('auction.api.tokens_max')])]);
        }

        $days = config('auction.api.token_days');

        $this->newToken = $user->createToken(
            trim($this->name),
            $this->access === 'write' ? ['read', 'write'] : ['read'],
            is_numeric($days) ? now()->addDays((int) $days) : null,
        )->plainTextToken;

        $this->reset('name', 'access');
    }

    public function dismiss(): void
    {
        $this->newToken = null;
    }

    public function revoke(int $id): void
    {
        $user = $this->user();

        abort_unless($this->allowed($user) && EnsureOtpConfirmed::confirmedIn(session()->driver()), 403);

        $user->tokens()->whereKey($id)->delete();

        $this->dispatch('toast', message: __('Token revoked.'));
    }

    public function render(): View
    {
        return view('livewire.account.api-tokens', [
            'tokens' => $this->user()->tokens()->latest('id')->get(),
        ])->title(__('API tokens'));
    }

    private function allowed(User $user): bool
    {
        return $user->is_active && ! $user->isPlatform();
    }

    private function user(): User
    {
        return Auth::user() ?? abort(401);
    }
}
