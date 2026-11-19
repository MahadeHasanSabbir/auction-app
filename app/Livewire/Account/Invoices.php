<?php

namespace App\Livewire\Account;

use App\Models\Invoice;
use App\Models\User;
use App\Support\Home;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/** A buyer's receipts and notices: purchases, deposits, withdrawals, fines. */
#[Layout('layouts.site')]
class Invoices extends Component
{
    use WithPagination;

    public function mount(): void
    {
        $user = $this->user();

        if (! $user->isBuyer()) {
            $this->redirect(Home::pathFor($user));
        }
    }

    public function render(): View
    {
        return view('livewire.account.invoices', [
            'invoices' => Invoice::query()->where('user_id', $this->user()->id)->latest('issued_at')->latest('id')->paginate(20),
        ])->title(__('Invoices and receipts'));
    }

    private function user(): User
    {
        return Auth::user() ?? abort(401);
    }
}
