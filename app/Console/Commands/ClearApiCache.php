<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\CacheHelper;

class ClearApiCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:clear-api {--type=all : Type of cache to clear (all|rosters|notifications|activity)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear API response caches';

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
                $this->info('⚠ Notification cache is per-user. Use CacheHelper::clearNotificationCache($userId)');
                break;
            case 'all':
                CacheHelper::clearAll();
                $this->info('✓ All caches cleared');
                break;
            default:
                $this->error('Invalid cache type. Use: all, rosters, notifications, or activity');
                return 1;
        }

        return 0;
    }
}
