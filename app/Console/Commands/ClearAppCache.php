<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\CacheHelper;

class ClearAppCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:clear-cache {--type=all : Type of cache to clear (all|rosters|notifications|activity|redis)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear application caches (rosters, notifications, activity logs)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $type = $this->option('type');

        switch ($type) {
            case 'rosters':
                CacheHelper::clearRosterCache();
                $this->info('✓ Roster cache cleared');
                break;

            case 'activity':
                CacheHelper::clearActivityLogCache();
                $this->info('✓ Activity log cache cleared');
                break;

            case 'notifications':
                $this->warn('⚠ Notification cache is per-user. Use CacheHelper::clearNotificationCache($userId) in code.');
                break;

            case 'redis':
            case 'all':
                CacheHelper::clearAll();
                $this->info('✓ All Redis caches cleared');
                break;

            default:
                $this->error('Invalid cache type. Use: all, rosters, notifications, activity, redis');
                return 1;
        }

        return 0;
    }
}
