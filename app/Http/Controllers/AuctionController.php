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
    public function index(): View
    {
        $auctions = Auction::all()->where('host_id', Auth::user()->id);
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
        $product = Product::find($request->product);

        $auction = Auction::create([
            'name' => $request->name,
            'final_price' => $request->final_price,
            'host_id' => Auth::user()->id,
            'host_name' => Auth::user()->name,
            'product_id' => $product->id,
        ]);

        if(Auth::user()->role == 0){
            return redirect(route('dashboard', absolute: false))
                                ->with('status', 'Auction created successfully. Please wait for administrator review to seen it online');
        }
        else{
            return redirect(route('admin.index', absolute: false))
                    ->with('status', 'Auction created successfully. Please wait for administrator review to seen it online');
        }
        
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
        $request->validate([
            'final_price' => ['required', 'integer'],
        ]);

        if($request->final_price < $auction->final_price){
            return redirect(route('auction.show', compact('auction')));
        }
        
        $auctions = Auction::where('id',$auction->id)->update([
            'final_price' => $request->final_price,
            'owner_id' => Auth::user()->id,
            'owner_name' => Auth::user()->name,
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
