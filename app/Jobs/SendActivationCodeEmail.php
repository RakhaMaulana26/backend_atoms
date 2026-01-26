<?php

namespace App\Jobs;

use App\Mail\ActivationCodeEmail;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendActivationCodeEmail implements ShouldQueue
{
    use Queueable;

    public $tries = 3; // Retry up to 3 times
    public $timeout = 30; // Timeout after 30 seconds

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $userId,
        public string $token,
        public string $purpose,
        public string $expiredAt,
        public ?int $triggeredBy = null
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $user = User::find($this->userId);
            
            if (!$user) {
                Log::warning("User {$this->userId} not found for email sending");
                return;
            }

            Mail::to($user->email)->send(
                new ActivationCodeEmail(
                    $this->token,
                    $user->name,
                    $this->purpose,
                    $this->expiredAt
                )
            );

            if ($this->triggeredBy) {
                ActivityLog::create([
                    'user_id' => $this->triggeredBy,
                    'action' => 'send_activation_code',
                    'module' => 'user',
                    'reference_id' => $user->id,
                    'description' => "Sent activation code to {$user->email}",
                ]);
            }

            Log::info("Activation code sent successfully to {$user->email} via queue");
        } catch (\Exception $e) {
            Log::error("Failed to send activation code to user {$this->userId}: " . $e->getMessage());
            throw $e; // Re-throw to trigger retry
        }
    }
}
