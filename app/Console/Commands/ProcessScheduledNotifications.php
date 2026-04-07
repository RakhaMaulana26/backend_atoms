<?php

namespace App\Console\Commands;

use App\Jobs\SendScheduledNotificationJob;
use App\Models\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessScheduledNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:process-scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process and send scheduled notifications that are due';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Checking for scheduled notifications...');

        // Get all pending notifications where scheduled_at <= now
        $dueNotifications = Notification::where('status', 'pending')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        $count = $dueNotifications->count();

        if ($count === 0) {
            $this->info('✅ No scheduled notifications due at this time.');
            return;
        }

        $this->info("📅 Found {$count} notification(s) ready to send:");

        $processed = 0;
        $failed = 0;

        foreach ($dueNotifications as $notification) {
            try {
                $this->line("  📤 Dispatching notification ID: {$notification->id} - '{$notification->title}'");

                // Dispatch job to send notification
                SendScheduledNotificationJob::dispatch($notification);

                $processed++;

                Log::info("Dispatched scheduled notification job for ID: {$notification->id}");

            } catch (\Exception $e) {
                $this->error("  ❌ Failed to dispatch notification ID {$notification->id}: " . $e->getMessage());
                $failed++;

                Log::error("Failed to dispatch scheduled notification {$notification->id}: " . $e->getMessage());
            }
        }

        $this->info("✅ Processing complete:");
        $this->info("  📤 Jobs dispatched: {$processed}");
        if ($failed > 0) {
            $this->error("  ❌ Failed to dispatch: {$failed}");
        }

        $this->info('🎯 Scheduled notifications processed successfully!');
    }
}
