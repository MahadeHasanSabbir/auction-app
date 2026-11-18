<?php

namespace App\Livewire;

use App\Models\Notice;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/** Every current notice meant for the viewer, newest first. */
#[Layout('layouts.site')]
class Notices extends Component
{
    use WithPagination;

    public function render(): View
    {
        return view('livewire.notices', [
            'notices' => Notice::query()->current()->for(Auth::user())
                ->latest('published_at')->latest('id')
                ->paginate(20),
        ])->title(__('Notices'));
    }
}
