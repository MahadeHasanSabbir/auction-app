<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Saves and removes the browser's web push subscription for the signed-in
 * user (called by the settings page's script, with the session and CSRF).
 */
class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url:https', 'max:1024'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', Rule::in(['aesgcm', 'aes128gcm'])],
        ]);

        // The server will post to this address, so it must be a real push service.
        $host = Str::lower((string) parse_url($data['endpoint'], PHP_URL_HOST));

        $allowed = collect((array) config('auction.push.allowed_hosts'))
            ->contains(fn (string $suffix) => $host === $suffix || str_ends_with($host, '.'.$suffix));

        if (! $allowed) {
            throw ValidationException::withMessages(['endpoint' => __('This browser’s push service is not supported.')]);
        }

        $user = $request->user();

        $user->updatePushSubscription($data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth'], $data['contentEncoding'] ?? null);

        // Keep the newest few; a browser that was reset leaves dead rows behind.
        $stale = $user->pushSubscriptions()->latest('id')->skip((int) config('auction.push.max_per_user'))->take(100)->pluck('id');

        if ($stale->isNotEmpty()) {
            $user->pushSubscriptions()->whereIn('id', $stale)->delete();
        }

        return response()->json(['subscribed' => true], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1024']]);

        $request->user()->deletePushSubscription($data['endpoint']);

        return response()->json(['subscribed' => false]);
    }
}
