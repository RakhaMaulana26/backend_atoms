<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Helpers\CacheHelper;

class ActivityLogObserver
{
    /**
     * Handle the ActivityLog "created" event.
     */
    public function created(ActivityLog $activityLog): void
    {
        // Clear activity log cache when new log is created
        CacheHelper::clearActivityLogCache();
    }
}
