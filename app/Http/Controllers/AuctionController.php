<?php

namespace App\Http\Controllers;

use App\Models\Auction;
use App\Models\Product;
use Illuminate\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\RedirectResponse;

class AuctionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $id): View
    {
        $id->validate([
            'key' => ['required','integer']
        ]);

        $auctions = Auction::all()->where('host', $id->key);
        return view('profile.manageauction', compact('auctions'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(string $id): View
    {
        $product = Product::findorfail($id);
        return view('profile.create', compact('product'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'start_time' => ['required', 'date'],
            'end_time' => ['required', 'date'],
        ]);

        $product = Product::find($request->product);

        $auction = Auction::create([
            'name' => $request->name,
            'start_time' => $request->start_time." ".$request->start_time1,
            'end_time' => $request->end_time." ".$request->end_time1,
            'final_price' => $request->final_price,
            'host' => $request->host,
            'product_id' => $product->id,
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
        return view('ongoing', compact('auction'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Auction $auction): View
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Auction $auction): RedirectResponse
    {
        $auctions = Auction::where('id',$auction->id)->update([
            'final_price' => $request->final_price,
            'owner' => Auth::user()->name,
        ]);

        $auctions = Auction::where('id', $auction->id)->increment('no_of_bid');
        Auth::user()->increment('total_bid');

        return redirect(route('auction.show', compact('auction')));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Auction $auction): RedirectResponse
    {
        Auction::destroy($auction->id);

        return redirect(route('auction.index'))->with('status', 'Auction deleted successfully!');
    }
}
