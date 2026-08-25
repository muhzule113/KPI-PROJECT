<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SystemNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = SystemNotification::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $notifications->map(fn($n) => [
                'id' => $n->id,
                'title' => $n->title,
                'body' => $n->body,
                'type' => $n->type,
                'entity_type' => $n->entity_type,
                'entity_id' => $n->entity_id,
                'action_url' => $n->action_url,
                'is_read' => (bool) $n->is_read,
                'created_at' => $n->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notif = SystemNotification::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($notif) {
            $notif->update(['is_read' => true, 'read_at' => now()]);
        }

        return response()->json(['success' => true]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        SystemNotification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['success' => true]);
    }
}
