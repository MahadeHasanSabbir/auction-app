<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Auction;
use Illuminate\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\RedirectResponse;
use Illuminate\Auth\Events\Registered;

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

    public function create(): View
    {
        return view('profile.add');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required'],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => '1',
        ]);

        event(new Registered($user));
        
        return redirect(route('admin.create', absolute: false))
                    ->with('status', 'New admin created successfully');
    }
    
    public function users(): View
    {
        $users = User::paginate(10);
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
