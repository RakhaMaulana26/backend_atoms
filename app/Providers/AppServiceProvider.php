<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use App\Models\ActivityLog;
use App\Models\RosterPeriod;
use App\Models\Notification;
use App\Observers\ActivityLogObserver;
use App\Observers\RosterPeriodObserver;
use App\Observers\NotificationObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Disable default auth redirect for API-only app
        $this->app['redirect.setIntendedUrl'] = false;

        // Register observers for auto cache clearing
        ActivityLog::observe(ActivityLogObserver::class);
        RosterPeriod::observe(RosterPeriodObserver::class);
        Notification::observe(NotificationObserver::class);
    }
}
