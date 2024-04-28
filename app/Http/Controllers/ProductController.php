<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        $products = Product::all();
        return view('profile.manage', compact($products));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('profile.product');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'category' => ['required', 'string', 'max:50'],
            'description' => ['required', 'string', 'max:550'],
            'starting_price' => ['required', 'numeric'],
        ]);

        $product = Product::create([
            'name' => $request->name,
            'category' => $request->category,
            'description' => $request->description,
            'starting_price' => $request->starting_price,
        ]);

        return redirect(route('product.index', absolute: false))
                    ->with('status', 'Product add successfully! Check it out in list.');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): View
    {
        $product = Product::find($id);
        return view('productview', compact($product));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request): view
    {
        return view('profile.product', [
            'product' => $request->product(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): RedirectResponse
    {
        $request->product()->fill($request->validated());

        if ($request->product()->isDirty('email')) {
            $request->product();
        }

        $request->product()->save();

        return Redirect::route('product.edit')->with('status', 'product-updated');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): RedirectResponse
    {
        $product = $request->product();

        $product->delete();

        return Redirect::to('/product');
    }
}
