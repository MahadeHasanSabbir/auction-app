<?php

namespace App\Livewire\Account;

use App\Models\User;
use App\Support\Home;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** A buyer's referral link and how many friends joined and bought through it. */
#[Layout('layouts.site')]
class Referrals extends Component
{
    public function mount(): void
    {
        $user = $this->user();

        if (! $user->isBuyer()) {
            $this->redirect(Home::pathFor($user));
        }
    }

    public function render(): View
    {
        $user = $this->user();
        $referred = User::query()->where('referred_by_id', $user->id);

        return view('livewire.account.referrals', [
            'link' => $user->referralUrl(),
            'joined' => (clone $referred)->count(),
            'rewarded' => (clone $referred)->whereNotNull('referral_rewarded_at')->count(),
            'forReferrer' => Money::minor((int) config('auction.engagement.referral_bonus_referrer')),
            'forReferred' => Money::minor((int) config('auction.engagement.referral_bonus_referred')),
        ])->title(__('Invite friends'));
    }

    private function user(): User
    {
        return Auth::user() ?? abort(401);
    }
}
