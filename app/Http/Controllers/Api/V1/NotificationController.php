<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * @group Notifications
 *
 * The bell: what the platform told you. Which kinds you receive is set on the website (Profile → Notification settings).
 *
 * @authenticated
 */
class NotificationController extends ApiController
{
    /**
     * List notifications
     *
     * Newest first, with the unread count.
     *
     * @queryParam unread boolean Only unread ones. Example: true
     * @queryParam page integer Page number. Example: 1
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $page = ($request->boolean('unread') ? $user->unreadNotifications() : $user->notifications())
            ->latest()
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => $page->getCollection()->map(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                'kind' => $n->data['kind'] ?? null,
                'title' => $n->data['title'] ?? '',
                'body' => $n->data['body'] ?? '',
                'url' => isset($n->data['url']) ? url($n->data['url']) : null,
                'read' => $n->read_at !== null,
                'at' => $n->created_at?->toIso8601ZuluString(),
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'unread' => $user->unreadNotifications()->count(),
            ],
        ]);
    }

    /**
     * Mark one as read
     *
     * @urlParam id string required The notification id. Example: 9b2f6c1e-3a4d-4c8e-9f21-0a1b2c3d4e5f
     */
    public function read(Request $request, string $id): JsonResponse
    {
        $this->user($request)->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return response()->json(['message' => __('Marked as read.')]);
    }

    /**
     * Mark all as read
     */
    public function readAll(Request $request): JsonResponse
    {
        $this->user($request)->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['message' => __('Marked as read.')]);
    }
}
