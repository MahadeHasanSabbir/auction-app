<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ContactController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        $contacts = Contact::get();

        return view('profile.contact', compact('contacts'));
    }

    /**
     * Store a newly created message in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'message' => ['required', 'string'],
            'check' => ['required'],
        ]);

        $contact = Contact::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'number' => $request->number,
            'message' => $request->message,
        ]);

        return Redirect::route('home')->with('status', 'Message sent successfully');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $contact = Contact::where('id', $id)->update([
            'status' => '1',
        ]);

        return Redirect::route('contact.index')->with('status', 'Message mark as read');
    }

    /**
     * Remove the specified message from storage.
     */
    public function destroy(string $id): RedirectResponse
    {
        Contact::destroy($id);

        return Redirect::route('contact.index')->with('status', 'Massage delete successfully');
    }
}
