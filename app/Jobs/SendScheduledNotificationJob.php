<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendScheduledNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $notification;

    /**
     * Create a new job instance.
     */
    public function __construct(Notification $notification)
    {
        $this->notification = $notification;
    }

    /**
     * Execute the job.
     */
    public function handle(NotificationService $notificationService): void
    {
        try {
            Log::info("Processing scheduled notification ID: {$this->notification->id}");

            $notification = $this->notification;

            // Skip if not scheduled or already sent
            if (!$notification->scheduled_at || $notification->status !== 'pending') {
                Log::info("Skipping notification ID {$notification->id}: not scheduled or not pending");
                return;
            }

            // Check if it's time to send
            if ($notification->scheduled_at->isFuture()) {
                Log::info("Notification ID {$notification->id} is not due yet. Scheduled for: {$notification->scheduled_at}");
                return;
            }

            $recipientIds = $notification->recipient_ids ?? [];
            
            // Handle case where recipient_ids might be stored as JSON string
            if (is_string($recipientIds)) {
                $recipientIds = json_decode($recipientIds, true) ?? [];
            }
            $sentCount = 0;
            $failedCount = 0;

            // Send to all recipients
            foreach ($recipientIds as $userId) {
                try {
                    $user = User::find($userId);
                    if (!$user) {
                        Log::warning("User ID {$userId} not found for notification {$notification->id}");
                        $failedCount++;
                        continue;
                    }

                    // Create inbox notification for recipient
                    $user->notifications()->create([
                        'sender_id' => $notification->user_id, // Original sender
                        'title' => $notification->title,
                        'message' => $notification->message,
                        'type' => 'inbox',
                        'category' => 'scheduled',
                        'is_read' => false,
                        'data' => [
                            'original_scheduled_id' => $notification->id,
                            'sent_at' => now()->toISOString(),
                        ]
                    ]);

                    // Send email if requested
                    if ($notification->data && isset($notification->data['send_email']) && $notification->data['send_email']) {
                        try {
                            $notificationService->sendEmail($user, $notification->title, $notification->message);
                            Log::info("Email sent to user {$user->email} for notification {$notification->id}");
                        } catch (\Exception $emailException) {
                            Log::error("Failed to send email to user {$user->email}: " . $emailException->getMessage());
                        }
                    }

                    $sentCount++;

                } catch (\Exception $e) {
                    Log::error("Failed to send notification to user {$userId}: " . $e->getMessage());
                    $failedCount++;
                }
            }

            // Update status
            $notification->update([
                'status' => 'sent',
                'error_message' => $failedCount > 0 ? "Sent: {$sentCount}, Failed: {$failedCount}" : null,
            ]);

            Log::info("Scheduled notification {$notification->id} processed. Sent: {$sentCount}, Failed: {$failedCount}");

        } catch (\Exception $e) {
            Log::error("Failed to process scheduled notification {$this->notification->id}: " . $e->getMessage());

            $this->notification->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
