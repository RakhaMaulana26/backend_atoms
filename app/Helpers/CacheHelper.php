<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Cache;

class CacheHelper
{
    /**
     * Clear all notification caches for a specific user
     */
    public static function clearNotificationCache(int $userId): void
    {
        Cache::forget("notifications_unread_count_{$userId}");
        
        // Clear paginated caches (1-10 pages, both read/unread/all)
        for ($page = 1; $page <= 10; $page++) {
            Cache::forget("notifications_user_{$userId}_page_{$page}_read_all");
            Cache::forget("notifications_user_{$userId}_page_{$page}_read_1");
            Cache::forget("notifications_user_{$userId}_page_{$page}_read_0");
        }
    }

    /**
     * Clear roster list cache
     */
    public static function clearRosterCache(): void
    {
        // Clear main roster list cache
        Cache::forget('rosters_list');
        
        // Clear filtered caches
        foreach (['draft', 'published', 'archived'] as $status) {
            Cache::forget("rosters_list_status_{$status}");
        }
    }

    /**
     * Clear activity log caches
     */
    public static function clearActivityLogCache(): void
    {
        Cache::forget('activity_logs_recent');
        Cache::forget('activity_logs_statistics');
    }

    /**
     * Clear all caches (use sparingly)
     */
    public static function clearAll(): void
    {
        Cache::flush();
    }
}
