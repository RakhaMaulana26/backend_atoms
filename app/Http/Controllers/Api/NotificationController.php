<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use App\Helpers\CacheHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    private function findNotificationForCurrentUser($id)
    {
        $userId = Auth::id();

        // First, try to find by exact ID
        $notification = Notification::withTrashed()->find($id);
        
        // If notification exists, check if user has access
        if ($notification) {
            if ($notification->user_id === $userId || $notification->sender_id === $userId) {
                return $notification;
            }
            // Notification exists but user doesn't have access
            return null; // Will be caught by caller and return 403
        }

        // If not found by ID and ID is not numeric, return null
        if (!is_numeric($id)) {
            return null;
        }

        // Try to find by reference_id or data fields
        $notification = Notification::withTrashed()->where(function($query) use ($userId) {
            $query->where('user_id', $userId)
                  ->orWhere('sender_id', $userId);
        })->where(function ($query) use ($id) {
            $query->where('reference_id', $id)
                  ->orWhereRaw('JSON_EXTRACT(data, "$.reference_id") = ?', [$id])
                  ->orWhereRaw('JSON_EXTRACT(data, "$.task_id") = ?', [$id])
                  ->orWhere('data', 'LIKE', '%"task_id":' . $id . '%')
                  ->orWhere('data', 'LIKE', '%"task_id":"' . $id . '%')
                  ->orWhere('data', 'LIKE', '%"reference_id":' . $id . '%')
                  ->orWhere('data', 'LIKE', '%"reference_id":"' . $id . '%');
        })->first();

        return $notification;
    }

    /**
     * GET /notifications/all
     * Returns all notifications with counts for each category
     * This allows frontend to filter client-side with a single request
     */
    public function all(Request $request)
    {
        $user = Auth::user();
        $page = max((int) $request->get('page', 1), 1);
        $perPage = (int) $request->get('per_page', 20);
        if ($perPage <= 0) {
            $perPage = 20;
        }
        $perPage = min($perPage, 100);
        
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
        $inboxCollection = $allNotifications->filter(function($n) use ($user) {
            return $n->user_id === $user->id 
                && ($n->type === 'inbox' || $n->category === 'roster') 
                && $n->deleted_at === null;
        })->values();

        $rosterCollection = $allNotifications->filter(function($n) use ($user) {
            return $n->user_id === $user->id 
                && $n->category === 'roster' 
                && $n->deleted_at === null;
        })->values();

        $starredCollection = $allNotifications->filter(function($n) {
            return $n->is_starred && $n->deleted_at === null;
        })->values();

        $sentCollection = $allNotifications->filter(function($n) use ($user) {
            return $n->sender_id === $user->id 
                && $n->type === 'sent' 
                && $n->deleted_at === null;
        })->values();

        $trashCollection = $allNotifications->filter(function($n) {
            return $n->deleted_at !== null;
        })->values();

        $inbox = $inboxCollection->forPage($page, $perPage)->values();
        $roster = $rosterCollection->forPage($page, $perPage)->values();
        $starred = $starredCollection->forPage($page, $perPage)->values();
        $sent = $sentCollection->forPage($page, $perPage)->values();
        $trash = $trashCollection->forPage($page, $perPage)->values();

        // Count unread for badge
        $unreadInbox = $inboxCollection->where('is_read', false)->count();

        return response()->json([
            'data' => [
                'inbox' => $inbox,
                'roster' => $roster,
                'starred' => $starred,
                'sent' => $sent,
                'trash' => $trash,
            ],
            'stats' => [
                'inbox' => $inboxCollection->count(),
                'roster' => $rosterCollection->count(),
                'starred' => $starredCollection->count(),
                'sent' => $sentCollection->count(),
                'trash' => $trashCollection->count(),
                'unread' => $unreadInbox,
            ],
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'inbox_total' => $inboxCollection->count(),
                'roster_total' => $rosterCollection->count(),
                'starred_total' => $starredCollection->count(),
                'sent_total' => $sentCollection->count(),
                'trash_total' => $trashCollection->count(),
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
                // Inbox: received notifications and roster notifications for this user
                $query->where('user_id', $user->id)
                      ->whereNull('deleted_at')
                      ->where(function($q) {
                          $q->where('type', 'inbox')
                            ->orWhere('category', 'roster');
                      });
                break;
                
            case 'roster':
                // Roster-specific notifications only
                $query->where('user_id', $user->id)
                      ->where('category', 'roster')
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
     * GET /notifications/daily-tasks
     * Returns current employee's scheduled shifts for today (or a selected date)
     */
    public function dailyTasks(Request $request)
    {
        $user = Auth::user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json([
                'message' => 'You must be an employee to view daily tasks'
            ], 403);
        }

        $date = $request->get('date', now()->toDateString());

        $rosterDay = \App\Models\RosterDay::whereDate('work_date', $date)
            ->with(['shiftAssignments' => function ($query) use ($employee) {
                $query->where('employee_id', $employee->id)->with('shift');
            }])->first();

        if (!$rosterDay) {
            return response()->json([
                'data' => [],
                'message' => 'No tasks found for this date',
            ]);
        }

        $task = $rosterDay->shiftAssignments->first();

        $result = [
            'date' => $date,
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->user->name,
            ],
            'schedule' => [
                'work_date' => $rosterDay->work_date->toDateString(),
                'shift_name' => $task ? ($task->shift ? $task->shift->name : $task->getShiftNameAttribute()) : 'Off',
                'notes' => $task ? $task->notes : null,
            ],
        ];

        return response()->json([ 'data' => $result ]);
    }

    /**
     * PUT /notifications/{id}/read
     */
    public function markAsRead($id)
    {
        $userId = Auth::id();
        
        // Check if notification exists at all
        $notificationExists = Notification::withTrashed()->find($id);
        
        if (!$notificationExists) {
            // Log the attempt
            \Log::warning("Notification not found: ID {$id} requested by user {$userId}");
            return response()->json([
                'message' => 'Notification not found',
                'error' => 'notification_not_found',
            ], 404);
        }
        
        // Check if user has access to this notification
        if ($notificationExists->user_id !== $userId && $notificationExists->sender_id !== $userId) {
            \Log::warning("Unauthorized access to notification: ID {$id} by user {$userId}. Belongs to user {$notificationExists->user_id} or sender {$notificationExists->sender_id}");
            return response()->json([
                'message' => 'You do not have permission to access this notification',
                'error' => 'unauthorized_notification_access',
            ], 403);
        }

        $notificationExists->is_read = true;
        $notificationExists->read_at = now();
        $notificationExists->save();

        // Clear notification cache for this user
        CacheHelper::clearNotificationCache($userId);
        
        return response()->json([
            'message' => 'Notification marked as read',
            'data' => $notificationExists,
        ]);
    }

    /**
     * PUT /notifications/{id}
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'is_read' => 'required|boolean',
        ]);

        $userId = Auth::id();
        
        // Check if notification exists
        $notification = Notification::withTrashed()->find($id);
        
        if (!$notification) {
            \Log::warning("Notification not found: ID {$id} requested by user {$userId}");
            return response()->json([
                'message' => 'Notification not found',
                'error' => 'notification_not_found',
            ], 404);
        }
        
        // Check if user has access
        if ($notification->user_id !== $userId && $notification->sender_id !== $userId) {
            \Log::warning("Unauthorized access to notification: ID {$id} by user {$userId}");
            return response()->json([
                'message' => 'You do not have permission to access this notification',
                'error' => 'unauthorized_notification_access',
            ], 403);
        }

        $notification->update([
            'is_read' => $request->is_read,
            'read_at' => $request->is_read ? now() : null,
        ]);

        // Clear notification cache for this user
        CacheHelper::clearNotificationCache($userId);
        
        return response()->json([
            'message' => 'Notification updated',
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
        // Only admins can send to role groups; any authenticated user can still send to explicit user_ids.
        $request->validate([
            'user_ids' => 'sometimes|array',
            'user_ids.*' => 'integer|exists:users,id',
            'emails' => 'sometimes|array',
            'emails.*' => 'email',
            'roles' => 'sometimes|array',
            'roles.*' => 'string',
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'send_email' => 'boolean',
        ]);

        $sender = Auth::user();

        $requestedUserIds = $request->input('user_ids', []);
        $requestedEmails = $request->input('emails', []);
        $requestedRoles = $request->input('roles', []);

        $validRoles = array_keys(User::getRoles());
        if (!empty($requestedRoles)) {
            $invalidRoles = array_diff($requestedRoles, $validRoles);
            if (!empty($invalidRoles)) {
                return response()->json([
                    'message' => 'Invalid role values: ' . implode(', ', $invalidRoles),
                ], 422);
            }
        }

        if (empty($requestedUserIds) && empty($requestedRoles) && empty($requestedEmails)) {
            return response()->json([
                'message' => 'You must provide user_ids, emails, or roles.'
            ], 422);
        }

        if (!empty($requestedRoles) && !$sender->isAdmin()) {
            return response()->json([
                'message' => 'Only admin users can send notifications by role.'
            ], 403);
        }

        $userIds = collect($requestedUserIds)->filter()->unique()->toArray();

        if (!empty($requestedEmails)) {
            $emailUserIds = User::whereIn('email', $requestedEmails)->pluck('id')->toArray();
            $userIds = array_unique(array_merge($userIds, $emailUserIds));
        }

        if (!empty($requestedRoles)) {
            $roleUserIds = User::whereIn('role', $requestedRoles)->pluck('id')->toArray();
            $userIds = array_unique(array_merge($userIds, $roleUserIds));
        }

        $sendEmail = $request->get('send_email', true);
        $notifications = [];

        foreach ($userIds as $userId) {
            $user = User::find($userId);
            if (!$user) {
                continue;
            }

            $inboxNotification = Notification::create([
                'user_id' => $userId,
                'sender_id' => $sender->id,
                'title' => $request->title,
                'message' => $request->message,
                'type' => 'inbox',
                'is_read' => false,
                'data' => [
                    'send_email' => $sendEmail,
                ],
            ]);

            // Keep a record for sender's sent view
            Notification::create([
                'user_id' => $userId,
                'sender_id' => $sender->id,
                'title' => $request->title,
                'message' => $request->message,
                'type' => 'sent',
                'is_read' => true,
            ]);

            $notifications[] = $inboxNotification;
            CacheHelper::clearNotificationCache($userId);
        }

        CacheHelper::clearNotificationCache($sender->id);

        $resolvedRecipients = User::whereIn('id', $userIds)
            ->get(['id', 'name', 'email'])
            ->toArray();

        return response()->json([
            'message' => 'Notification sent successfully',
            'data' => $notifications,
            'recipients' => $resolvedRecipients,
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

    /**
     * GET /notifications/debug/{id}
     * Debug endpoint to check notification details (development only)
     */
    public function debugNotification($id)
    {
        if (!app()->isLocal()) {
            return response()->json(['message' => 'Not available in production'], 403);
        }

        $userId = Auth::id();
        $notification = Notification::withTrashed()->find($id);

        if (!$notification) {
            return response()->json([
                'status' => 'not_found',
                'message' => "Notification ID {$id} does not exist in database",
                'requested_by_user' => $userId,
                'database_info' => [
                    'total_notifications' => Notification::count(),
                    'total_with_trashed' => Notification::withTrashed()->count(),
                    'max_id' => Notification::max('id'),
                ],
            ], 404);
        }

        $hasAccess = $notification->user_id === $userId || $notification->sender_id === $userId;

        return response()->json([
            'status' => $hasAccess ? 'accessible' : 'forbidden',
            'notification' => $notification,
            'access_info' => [
                'requested_by_user' => $userId,
                'notification_user_id' => $notification->user_id,
                'notification_sender_id' => $notification->sender_id,
                'has_access' => $hasAccess,
                'is_deleted' => $notification->deleted_at !== null,
            ],
        ], $hasAccess ? 200 : 403);
    }

    /**
     * POST /notifications/debug/create-test
     * Create a test notification for current user (development only)
     */
    public function createTestNotification()
    {
        if (!app()->isLocal()) {
            return response()->json(['message' => 'Not available in production'], 403);
        }

        $userId = Auth::id();
        $notification = Notification::create([
            'user_id' => $userId,
            'sender_id' => $userId,
            'title' => 'Test Notification - ' . now()->format('Y-m-d H:i:s'),
            'message' => 'This is a test notification for debugging purposes.',
            'type' => 'inbox',
            'category' => 'test',
            'is_read' => false,
        ]);

        CacheHelper::clearNotificationCache($userId);

        return response()->json([
            'message' => 'Test notification created successfully',
            'notification' => $notification,
            'endpoint_to_test' => "/api/notifications/{$notification->id}/read",
            'instructions' => [
                'Try marking this notification as read using the endpoint shown above',
                'URL: PUT /api/notifications/' . $notification->id . '/read'
            ]
        ], 201);
    }

    /**
     * POST /notifications/debug/create-test-scheduled
     * Create a test scheduled notification (development only)
     */
    public function createTestScheduledNotification()
    {
        if (!app()->isLocal()) {
            return response()->json(['message' => 'Not available in production'], 403);
        }

        $userId = Auth::id();

        // Schedule for 2 minutes from now
        $scheduledAt = now()->addMinutes(2);

        $notification = Notification::create([
            'user_id' => $userId,
            'sender_id' => $userId,
            'title' => 'Test Scheduled Notification - ' . now()->format('Y-m-d H:i:s'),
            'message' => 'This scheduled notification will be sent automatically in 2 minutes.',
            'recipient_ids' => [$userId], // Send to self for testing
            'scheduled_at' => $scheduledAt,
            'status' => 'pending',
            'type' => 'scheduled',
            'category' => 'test',
            'is_read' => true,
            'data' => [
                'send_email' => false,
                'created_as_scheduled' => true,
                'test_notification' => true,
            ]
        ]);

        CacheHelper::clearNotificationCache($userId);

        return response()->json([
            'message' => 'Test scheduled notification created successfully',
            'notification' => $notification,
            'scheduled_for' => $scheduledAt->format('Y-m-d H:i:s'),
            'instructions' => [
                'Wait 2 minutes, then run: php artisan notifications:process-scheduled',
                'Or run the scheduler manually: php artisan schedule:run',
                'Check your inbox for the notification after processing'
            ]
        ], 201);
    }

    /**
     * POST /api/notifications/save-scheduled
     * Save a scheduled notification
     */
    public function saveScheduled(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'recipient_ids' => 'required|array|min:1',
            'recipient_ids.*' => 'integer|exists:users,id',
            'scheduled_at' => 'required|date|after:now',
            'send_email' => 'boolean',
        ]);

        $userId = Auth::id();

        $notification = Notification::create([
            'user_id' => $userId,
            'sender_id' => $userId,
            'title' => $validated['title'],
            'message' => $validated['message'],
            'recipient_ids' => $validated['recipient_ids'],
            'scheduled_at' => $validated['scheduled_at'],
            'status' => 'pending',
            'type' => 'scheduled',
            'category' => 'scheduled',
            'is_read' => true, // Mark as read for sender
            'data' => [
                'send_email' => $validated['send_email'] ?? false,
                'created_as_scheduled' => true,
            ]
        ]);

        CacheHelper::clearNotificationCache($userId);

        return response()->json([
            'success' => true,
            'message' => 'Notification scheduled successfully',
            'data' => [
                'id' => $notification->id,
                'title' => $notification->title,
                'scheduled_at' => $notification->scheduled_at,
                'status' => $notification->status,
                'recipient_count' => count($notification->recipient_ids),
            ]
        ], 201);
    }

    /**
     * GET /api/notifications/scheduled
     * Get scheduled notifications for current user
     */
    public function getScheduled(Request $request)
    {
        $userId = Auth::id();

        $notifications = Notification::where('user_id', $userId)
            ->where('type', 'scheduled')
            ->whereIn('status', ['draft', 'pending'])
            ->orderBy('scheduled_at', 'asc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $notifications,
        ]);
    }

    /**
     * PUT /api/notifications/scheduled/{id}
     * Update a scheduled notification
     */
    public function updateScheduled(Request $request, $id)
    {
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'message' => 'sometimes|string',
            'recipient_ids' => 'sometimes|array|min:1',
            'recipient_ids.*' => 'integer|exists:users,id',
            'scheduled_at' => 'sometimes|date|after:now',
            'send_email' => 'boolean',
        ]);

        $userId = Auth::id();

        $notification = Notification::where('user_id', $userId)
            ->where('type', 'scheduled')
            ->findOrFail($id);

        // Can only edit if not sent yet
        if ($notification->status === 'sent') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot edit a notification that has already been sent'
            ], 422);
        }

        // Update data array for send_email
        $data = $notification->data ?? [];
        if (isset($validated['send_email'])) {
            $data['send_email'] = $validated['send_email'];
            unset($validated['send_email']);
        }

        $validated['data'] = $data;

        $notification->update($validated);

        CacheHelper::clearNotificationCache($userId);

        return response()->json([
            'success' => true,
            'message' => 'Scheduled notification updated successfully',
            'data' => $notification
        ]);
    }

    /**
     * DELETE /api/notifications/scheduled/{id}
     * Delete a scheduled notification
     */
    public function deleteScheduled($id)
    {
        $userId = Auth::id();

        $notification = Notification::where('user_id', $userId)
            ->where('type', 'scheduled')
            ->whereIn('status', ['draft', 'pending'])
            ->findOrFail($id);

        $notification->delete();

        CacheHelper::clearNotificationCache($userId);

        return response()->json([
            'success' => true,
            'message' => 'Scheduled notification deleted successfully'
        ]);
    }
}
