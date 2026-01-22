<?php

namespace App\Observers;

use App\Models\Notification;
use App\Helpers\CacheHelper;

class NotificationObserver
{
    /**
     * Handle the Notification "created" event.
     */
    public function created(Notification $notification): void
    {
        CacheHelper::clearNotificationCache($notification->user_id);
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
