<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Auction;
use Illuminate\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class AdminController extends Controller
{
    public function index(): View
    {
        $users = User::all();
        return view('admin', compact('users'));
    }

    public function auction(): View
    {
        $auctions = Auction::where('status', '0')->get();
        return view('profile.manageauction', compact('auctions'));
    }

    public function users(): View
    {
        $users = User::all();
        return view('viewusers', compact('users'));
    }

    public function accept(string $id): RedirectResponse
    {
        $auctions = Auction::where('id', $id)->update([
            'status' => '1',
        ]);

        $auctions = Auction::where('status', '0')->get();
        return redirect(route('admin.auction', compact('auctions')));
    }

    public function deny(string $id): RedirectResponse
    {
        $auctions = Auction::where('id', $id)->update([
            'status' => '2',
        ]);

        $auctions = Auction::where('status', '0')->get();
        return redirect(route('admin.auction', compact('auctions')));
    }
}
