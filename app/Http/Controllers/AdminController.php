<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Auction;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class AdminController extends Controller
{
    public function index(): View
    {
        $auctions = Auction::where('status', '1')
                            ->where('start_time', '<=', date('Y-m-d H:i:s'))
                            ->where('end_time', '>=', date('Y-m-d H:i:s'))
                            ->get();
        return view('admin', compact('auctions'));
    }

    public function auction(): View
    {
        $auctions = Auction::where('status', '0')->get();
        return view('profile.requestauction', compact('auctions'));
    }

    public function users(): View
    {
        $users = User::paginate(5);
        return view('viewusers', compact('users'));
    }

    public function accept(string $id): RedirectResponse
    {
        $timezone = config('app.timezone');
        $start = Carbon::now($timezone)->addMinutes(30);
        $end = Carbon::now($timezone)->addHours(3);
        //$end = Carbon::now($timezone)->addMinutes(30);
        $auctions = Auction::where('id', $id)->update([
            'start_time' => $start,
            'end_time' => $end,
            'status' => '1',
        ]);

        //$auctions = Auction::where('status', '0')->get();
        return redirect(route('admin.auction'));
    }

    public function deny(Request $request, string $id): RedirectResponse
    {
        $auctions = Auction::where('id', $id)->update([
            'status' => '2',
            'massage' => $request->massage,
        ]);

        //$auctions = Auction::where('status', '0')->get();
        return redirect(route('admin.auction'));
    }
}
