<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    /**
     * GET /notifications
     */
    public function index(Request $request)
    {
        $query = Notification::where('user_id', Auth::id());

        if ($request->has('is_read')) {
            $query->where('is_read', $request->is_read);
        }

        $notifications = $query->latest()->paginate($request->get('per_page', 20));

        return response()->json($notifications);
    }

    /**
     * POST /notifications/{id}/read
     */
    public function markAsRead($id)
    {
        $notification = Notification::where('user_id', Auth::id())
            ->findOrFail($id);

        $notification->is_read = true;
        $notification->save();

        return response()->json([
            'message' => 'Notification marked as read',
            'data' => $notification,
        ]);
    }
}
