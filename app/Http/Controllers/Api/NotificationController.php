<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\NotificationService;
use App\Helpers\CacheHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class NotificationController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * GET /notifications
     */
    public function index(Request $request)
    {
        $query = Notification::where('user_id', Auth::id())
            ->select(['id', 'user_id', 'type', 'title', 'message', 'is_read', 'created_at']);

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

        // Clear notification cache for this user
        CacheHelper::clearNotificationCache(Auth::id());
        
        return response()->json([
            'message' => 'Notification marked as read',
            'data' => $notification,
        ]);
    }

    /**
     * POST /notifications/create
     * Example endpoint to create notification with email
     */
    public function create(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'send_email' => 'boolean',
        ]);

        $user = \App\Models\User::findOrFail($request->user_id);
        $sendEmail = $request->get('send_email', true);

        $notification = $this->notificationService->createNotification(
            $user,
            $request->title,
            $request->message,
            $sendEmail
        );

        // Clear user's notification cache
        CacheHelper::clearNotificationCache($request->user_id);

        return response()->json([
            'message' => 'Notification created successfully',
            'data' => $notification,
        ], 201);
    }

    /**
     * POST /notifications/{id}/resend-email
     */
    public function resendEmail($id)
    {
        $notification = Notification::where('user_id', Auth::id())
            ->findOrFail($id);

        $success = $this->notificationService->resendEmail($notification);

        if ($success) {
            return response()->json([
                'message' => 'Email sent successfully',
            ]);
        }

        return response()->json([
            'message' => 'Failed to send email',
        ], 500);
    }
}
