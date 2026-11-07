<?php

namespace App\Http\Controllers;

use App\Http\Resources\AdminNotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminNotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $notifications = $user->notifications()->reorder()
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(20)->withQueryString();

        return AdminNotificationResource::collection($notifications)
            ->additional(['meta' => ['unread_count' => $user->unreadNotifications()->count()]]);
    }

    public function update(Request $request, string $notification): AdminNotificationResource
    {
        $item = $request->user()->notifications()->findOrFail($notification);
        $item->markAsRead();

        return new AdminNotificationResource($item);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['unread_count' => 0]);
    }
}
