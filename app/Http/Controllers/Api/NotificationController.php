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
     * GET /notifications/all
     * Returns all notifications with counts for each category
     * This allows frontend to filter client-side with a single request
     */
    public function all(Request $request)
    {
        $user = Auth::user();
        
        // Get all user's notifications (both received and sent, including trashed)
        $allNotifications = Notification::with('sender:id,name,email')
            ->withTrashed()
            ->where(function($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere('sender_id', $user->id);
            })
            ->latest()
            ->get();

        // Categorize notifications
        $inbox = $allNotifications->filter(function($n) use ($user) {
            return $n->user_id === $user->id 
                && $n->type === 'inbox' 
                && $n->deleted_at === null;
        })->values();

        $starred = $allNotifications->filter(function($n) {
            return $n->is_starred && $n->deleted_at === null;
        })->values();

        $sent = $allNotifications->filter(function($n) use ($user) {
            return $n->sender_id === $user->id 
                && $n->type === 'sent' 
                && $n->deleted_at === null;
        })->values();

        $trash = $allNotifications->filter(function($n) {
            return $n->deleted_at !== null;
        })->values();

        // Count unread for badge
        $unreadInbox = $inbox->where('is_read', false)->count();

        return response()->json([
            'data' => [
                'inbox' => $inbox,
                'starred' => $starred,
                'sent' => $sent,
                'trash' => $trash,
            ],
            'stats' => [
                'inbox' => $inbox->count(),
                'starred' => $starred->count(),
                'sent' => $sent->count(),
                'trash' => $trash->count(),
                'unread' => $unreadInbox,
            ],
        ]);
    }

    /**
     * GET /notifications
     * Filter by category: inbox, starred, sent, trash
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $category = $request->get('category', 'inbox'); // inbox, starred, sent, trash
        
        $query = Notification::with('sender:id,name,email');

        // Filter by category
        switch ($category) {
            case 'inbox':
                // Inbox: received notifications (not sent by user)
                $query->where('user_id', $user->id)
                      ->where('type', 'inbox')
                      ->whereNull('deleted_at');
                break;
                
            case 'starred':
                // Starred: notifications marked as favorite (both received and sent)
                $query->where(function($q) use ($user) {
                    $q->where('user_id', $user->id)
                      ->orWhere('sender_id', $user->id);
                })
                ->where('is_starred', true)
                ->whereNull('deleted_at');
                break;
                
            case 'sent':
                // Sent: notifications sent by this user
                $query->where('sender_id', $user->id)
                      ->where('type', 'sent')
                      ->whereNull('deleted_at');
                break;
                
            case 'trash':
                // Trash: soft deleted notifications
                $query->where(function($q) use ($user) {
                    $q->where('user_id', $user->id)
                      ->orWhere('sender_id', $user->id);
                })->whereNotNull('deleted_at')->withTrashed();
                break;
                
            default:
                $query->where('user_id', $user->id)
                      ->whereNull('deleted_at');
        }

        // Filter by read status if provided
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
        // Allow user to mark as read notifications they received OR sent
        $notification = Notification::where(function($query) {
            $query->where('user_id', Auth::id())
                  ->orWhere('sender_id', Auth::id());
        })->findOrFail($id);

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
     * POST /notifications/send
     * Send notification to another user
     */
    public function send(Request $request)
    {
        $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'send_email' => 'boolean',
        ]);

        $sender = Auth::user();
        $sendEmail = $request->get('send_email', false);
        $notifications = [];

        foreach ($request->user_ids as $userId) {
            $user = \App\Models\User::findOrFail($userId);
            
            // Create notification for receiver (inbox)
            $inboxNotification = Notification::create([
                'user_id' => $userId,
                'sender_id' => $sender->id,
                'title' => $request->title,
                'message' => $request->message,
                'type' => 'inbox',
                'is_read' => false,
            ]);

            if ($sendEmail) {
                $this->notificationService->resendEmail($inboxNotification);
            }

            // Create copy for sender (sent)
            $sentNotification = Notification::create([
                'user_id' => $userId,
                'sender_id' => $sender->id,
                'title' => $request->title,
                'message' => $request->message,
                'type' => 'sent',
                'is_read' => true,
            ]);

            $notifications[] = $inboxNotification;
            
            // Clear cache
            CacheHelper::clearNotificationCache($userId);
        }

        CacheHelper::clearNotificationCache($sender->id);

        return response()->json([
            'message' => 'Notification sent successfully',
            'data' => $notifications,
        ], 201);
    }

    /**
     * POST /notifications/{id}/star
     * Toggle star status
     */
    public function toggleStar($id)
    {
        // Allow user to star notifications they received OR sent
        $notification = Notification::where(function($query) {
            $query->where('user_id', Auth::id())
                  ->orWhere('sender_id', Auth::id());
        })->findOrFail($id);

        $notification->is_starred = !$notification->is_starred;
        $notification->save();

        CacheHelper::clearNotificationCache(Auth::id());

        return response()->json([
            'message' => 'Notification ' . ($notification->is_starred ? 'starred' : 'unstarred'),
            'data' => $notification,
        ]);
    }

    /**
     * DELETE /notifications/{id}
     * Move to trash (soft delete)
     */
    public function destroy($id)
    {
        $notification = Notification::where(function($query) {
            $query->where('user_id', Auth::id())
                  ->orWhere('sender_id', Auth::id());
        })->findOrFail($id);

        $notification->delete();
        CacheHelper::clearNotificationCache(Auth::id());

        return response()->json([
            'message' => 'Notification moved to trash',
        ]);
    }

    /**
     * POST /notifications/{id}/restore
     * Restore from trash
     */
    public function restore($id)
    {
        $notification = Notification::where(function($query) {
            $query->where('user_id', Auth::id())
                  ->orWhere('sender_id', Auth::id());
        })->withTrashed()->findOrFail($id);

        $notification->restore();
        CacheHelper::clearNotificationCache(Auth::id());

        return response()->json([
            'message' => 'Notification restored',
            'data' => $notification,
        ]);
    }

    /**
     * DELETE /notifications/{id}/permanent
     * Permanently delete
     */
    public function forceDestroy($id)
    {
        $notification = Notification::where(function($query) {
            $query->where('user_id', Auth::id())
                  ->orWhere('sender_id', Auth::id());
        })->withTrashed()->findOrFail($id);

        $notification->forceDelete();
        CacheHelper::clearNotificationCache(Auth::id());

        return response()->json([
            'message' => 'Notification permanently deleted',
        ]);
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
