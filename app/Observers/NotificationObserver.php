<?php

namespace App\Observers;

use App\Models\Notification;
use App\Helpers\CacheHelper;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class NotificationObserver
{
    /**
     * Handle the Notification "created" event.
     */
    public function created(Notification $notification): void
    {
        CacheHelper::clearNotificationCache($notification->user_id);

        if ($notification->type === 'sent') {
            return;
        }

        if ($notification->email_sent) {
            return;
        }

        $user = $notification->user;
        if (!$user || !$user->email) {
            return;
        }

        $sendEmail = true;
        if (is_array($notification->data) && array_key_exists('send_email', $notification->data)) {
            $sendEmail = (bool) $notification->data['send_email'];
        }

        if (!$sendEmail) {
            return;
        }

        try {
            $service = app(NotificationService::class);
            $service->resendEmail($notification);
        } catch (\Exception $e) {
            Log::error('Failed to send notification email from observer', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the Notification "updated" event.
     */
    public function updated(Notification $notification): void
    {
        CacheHelper::clearNotificationCache($notification->user_id);
    }

    /**
     * Handle the Notification "deleted" event.
     */
    public function deleted(Notification $notification): void
    {
        CacheHelper::clearNotificationCache($notification->user_id);
    }
}
