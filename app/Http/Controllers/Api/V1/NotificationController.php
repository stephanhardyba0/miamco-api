<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AppNotification::where('user_id', $request->user()->id);

        if ($request->has('type')) {
            $query->byType($request->get('type'));
        }

        if ($request->has('is_read')) {
            $query->where('is_read', filter_var($request->get('is_read'), FILTER_VALIDATE_BOOLEAN));
        }

        $notifications = $query->latest()->paginate($request->get('per_page', 15));

        return response()->json($notifications);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = NotificationService::getUnreadCount($request->user()->id);

        return response()->json(['count' => $count]);
    }

    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $success = NotificationService::markAsRead($id, $request->user()->id);

        if (! $success) {
            return response()->json(['message' => 'Notification introuvable.'], 404);
        }

        return response()->json(['message' => 'Notification marquée comme lue.']);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $count = NotificationService::markAllAsRead($request->user()->id);

        return response()->json(['message' => "{$count} notifications marquées comme lues."]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleted = AppNotification::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Notification introuvable.'], 404);
        }

        return response()->json(['message' => 'Notification supprimée.']);
    }
}
