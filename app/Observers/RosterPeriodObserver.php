<?php

namespace App\Observers;

use App\Models\RosterPeriod;
use App\Helpers\CacheHelper;

class RosterPeriodObserver
{
    /**
     * Handle the RosterPeriod "created" event.
     */
    public function created(RosterPeriod $rosterPeriod): void
    {
        CacheHelper::clearRosterCache();
    }

    /**
     * Handle the RosterPeriod "updated" event.
     */
    public function updated(RosterPeriod $rosterPeriod): void
    {
        CacheHelper::clearRosterCache();
    }

    /**
     * Handle the RosterPeriod "deleted" event.
     */
    public function deleted(RosterPeriod $rosterPeriod): void
    {
        CacheHelper::clearRosterCache();
    }
}
