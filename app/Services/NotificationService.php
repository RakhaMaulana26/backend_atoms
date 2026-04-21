<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Mail\NotificationEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Create a notification and optionally send email
     */
    public function createNotification(
        User $user,
        string $title,
        string $message,
        bool $sendEmail = true
    ): Notification {
        // Create notification in database
        $notification = Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'message' => $message,
            'is_read' => false,
            'data' => [
                'send_email' => $sendEmail,
            ],
        ]);

        return $notification;
    }

    /**
     * Create notifications for multiple users
     */
    public function createBulkNotifications(
        array $userIds,
        string $title,
        string $message,
        bool $sendEmail = true
    ): array {
        $notifications = [];
        
        foreach ($userIds as $userId) {
            $user = User::find($userId);
            if ($user) {
                $notifications[] = $this->createNotification($user, $title, $message, $sendEmail);
            }
        }

        return $notifications;
    }

    /**
     * Resend email for a notification
     */
    public function resendEmail(Notification $notification): bool
    {
        $user = $notification->user;
        
        if (!$user || !$user->email) {
            return false;
        }

        try {
            Mail::to($user->email)->send(
                new NotificationEmail($notification, $user->name)
            );
            
            $notification->update([
                'email_sent' => true,
                'email_sent_at' => now(),
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to resend notification email", [
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
