<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\ImageStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class ProfileController extends Controller
{
    /**
     * The profile form: name, contact details, timezone and picture.
     */
    public function edit(Request $request): View
    {
        return view('account.profile', [
            'user' => $request->user(),
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    public function update(ProfileUpdateRequest $request, ImageStore $images): RedirectResponse
    {
        $user = $request->user();
        $previousAvatar = $user->avatar_path;

        $user->fill($request->safe()->only(['name', 'phone', 'address', 'timezone']));

        if ($request->hasFile('avatar')) {
            try {
                $user->avatar_path = $images->store($request->file('avatar'), 'avatars', maxSide: 400, square: true);
            } catch (InvalidArgumentException) {
                // It passed the type check but cannot be decoded: damaged, or not really a picture.
                throw ValidationException::withMessages(['avatar' => __('This file could not be read as a picture. Try a JPG or PNG.')]);
            }
        } elseif ($request->boolean('remove_avatar')) {
            $user->avatar_path = null;
        }

        $user->save();

        if ($user->avatar_path !== $previousAvatar) {
            $images->delete($previousAvatar);
        }

        return redirect()->route('profile.edit')->with('status', __('Profile saved.'));
    }
}
