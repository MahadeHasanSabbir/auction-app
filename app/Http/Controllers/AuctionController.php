<?php

namespace App\Http\Controllers;

use App\Models\Auction;
use App\Models\Product;
use Illuminate\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class AuctionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        $auctions = Auction::all();
        return view('auction', compact($auctions));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(string $id): View
    {
        $product = Product::find($id);
        return view('profile.create', $product);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'start_time' => ['required', 'string', 'max:50'],
            'end_time' => ['required', 'string', 'max:550'],
        ]);

        $product = Auction::create([
            'name' => $request->name,
            'start_time' => $request->category,
            'end_time' => $request->description,
        ]);

        return redirect(route('dashboard', absolute: false))
                    ->with('status', 'Auction created successfully. Please wait for administrator review to seen it online');
    }

    /**
     * Display the specified resource.
     */
    public function show(Auction $auction): View
    {
        //$auction = Auction::find($auction);
        //return view('ongoing', compact($auction));
        return view('ongoing', $auction);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Auction $auction): View
    {
        return view('profile.create', $auction);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Auction $auction): RedirectResponse
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Auction $auction): RedirectResponse
    {
        //
    }
}
