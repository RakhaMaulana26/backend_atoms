# Cache Strategy

## Overview
File-based caching dengan TTL untuk optimize API response times.

## Cached Endpoints

### 1. Roster List (`GET /rosters`)
- **Cache Key**: `rosters_{month}_{year}`
- **TTL**: 5 minutes (300 seconds)
- **Cleared**: Auto-clear saat create/update roster
- **Optimization**: 
  - Select specific columns only
  - Limit 12 results (1 year max)
  - Removed N+1 query (rosterDays eager loading)

### 2. Activity Logs - Recent (`GET /activity-logs/recent`)
- **Cache Key**: `activity_logs_recent`
- **TTL**: 2 minutes (120 seconds)
- **Cleared**: Auto-clear via ActivityLogObserver when new log created
- **Optimization**: Select specific columns, limit 10

### 3. Activity Logs - Statistics (`GET /activity-logs/statistics`)
- **Cache Key**: `activity_logs_statistics`
- **TTL**: 5 minutes (300 seconds)
- **Cleared**: Auto-clear via ActivityLogObserver
- **Optimization**: Cache all COUNT queries and aggregations

### 4. Notifications (`GET /notifications`)
- **Cache Key**: `notifications_user_{userId}_page_{page}_read_{filter}`
- **TTL**: 3 minutes (180 seconds)
- **Cleared**: Manual via CacheHelper when notification created/marked as read
- **Optimization**: Per-user caching with pagination

## Cache Management

### Auto-Clear (via Observers)
```php
ActivityLog::observe(ActivityLogObserver::class); // Auto-clear on new log
```

### Manual Clear (via Helper)
```php
use App\Helpers\CacheHelper;

CacheHelper::clearRosterCache();           // Clear roster cache
CacheHelper::clearActivityLogCache();       // Clear activity log cache
CacheHelper::clearNotificationCache($userId); // Clear user's notifications
CacheHelper::clearAll();                    // Nuclear option (use sparingly)
```

### Artisan Commands
```bash
php artisan cache:clear-api --type=all         # Clear all API caches
php artisan cache:clear-api --type=rosters     # Clear roster cache only
php artisan cache:clear-api --type=activity    # Clear activity log cache only
php artisan cache:clear                        # Laravel cache clear
```

## Performance Impact

**Before Optimization:**
- Rosters: 2.07s
- Notifications: 1.47s
- Activity Logs (recent): 2.63s
- Activity Logs (statistics): 3.17s

**After Optimization (Expected):**
- Rosters: ~100-200ms (cache hit), ~300-500ms (cache miss)
- Notifications: ~100-150ms (cache hit), ~400ms (cache miss)
- Activity Logs (recent): ~50-100ms (cache hit), ~200ms (cache miss)
- Activity Logs (statistics): ~100ms (cache hit), ~500ms (cache miss)

**Combined Improvements:**
- Database indexing: 20-50x faster WHERE clauses
- Query optimization: Removed N+1, select specific columns
- File caching: Near-instant response on cache hit

## Cache Driver
- **Current**: `file` (storage/framework/cache)
- **Alternative**: Switch to `redis` for better performance on high-traffic servers
- **Config**: `.env` → `CACHE_STORE=file`

## Notes
- Cache automatically expires after TTL
- First request after expiry rebuilds cache (slightly slower)
- Subsequent requests use cached data (very fast)
- File cache works well for single-server setups
- Consider Redis for multi-server / load-balanced deployments
