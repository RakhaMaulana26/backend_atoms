# Backend Requirements: Implement Scheduled Email Notifications

## 📌 Overview
Frontend sudah bisa menyimpan draft dan scheduled notifications. Backend harus implement **auto-sending** scheduled notifications saat waktu tiba.

---

## 🔧 Tasks untuk Backend Team

### 1️⃣ **Database Migration**
Buat table `scheduled_notifications` dengan struktur:

```sql
CREATE TABLE scheduled_notifications (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message LONGTEXT NOT NULL,
    recipient_ids JSON NOT NULL,  -- Array of user IDs [1, 2, 3]
    scheduled_at DATETIME NOT NULL,
    send_email BOOLEAN DEFAULT false,
    status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_scheduled_at (scheduled_at),
    INDEX idx_status (status),
    INDEX idx_user_id (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

---

### 2️⃣ **Create Model & Migration**
```bash
php artisan make:model ScheduledNotification -m
```

Model: `app/Models/ScheduledNotification.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduledNotification extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'message',
        'recipient_ids',
        'scheduled_at',
        'send_email',
        'status',
        'error_message'
    ];

    protected $casts = [
        'recipient_ids' => 'array',
        'send_email' => 'boolean',
        'scheduled_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

---

### 3️⃣ **Create API Endpoint - Save Scheduled Notification**

**POST** `/api/notifications/save-scheduled`

**Request Body:**
```json
{
    "title": "Meeting reminder",
    "message": "Don't forget the meeting at 2 PM",
    "recipient_ids": [1, 2, 3],
    "send_email": true,
    "scheduled_at": "2026-04-08T14:00:00"
}
```

**Response:**
```json
{
    "success": true,
    "message": "Notification scheduled successfully",
    "data": {
        "id": 1,
        "status": "pending",
        "scheduled_at": "2026-04-08T14:00:00"
    }
}
```

**Controller Logic:**
```php
// NotificationController.php
public function saveScheduled(Request $request)
{
    $validated = $request->validate([
        'title' => 'required|string|max:255',
        'message' => 'required|string',
        'recipient_ids' => 'required|array|min:1',
        'send_email' => 'boolean',
        'scheduled_at' => 'required|date|after:now',
    ]);

    $scheduled = ScheduledNotification::create([
        'user_id' => auth()->id(),
        'title' => $validated['title'],
        'message' => $validated['message'],
        'recipient_ids' => $validated['recipient_ids'],
        'send_email' => $validated['send_email'] ?? false,
        'scheduled_at' => $validated['scheduled_at'],
        'status' => 'pending',
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Notification scheduled successfully',
        'data' => $scheduled,
    ]);
}
```

---

### 4️⃣ **Create Queue Job**

```bash
php artisan make:job SendScheduledNotificationJob
```

**File:** `app/Jobs/SendScheduledNotificationJob.php`
```php
<?php

namespace App\Jobs;

use App\Models\ScheduledNotification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendScheduledNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $scheduledNotification;

    public function __construct(ScheduledNotification $scheduledNotification)
    {
        $this->scheduledNotification = $scheduledNotification;
    }

    public function handle()
    {
        try {
            $notification = $this->scheduledNotification;

            // Send to all recipient users
            foreach ($notification->recipient_ids as $userId) {
                $user = User::find($userId);
                if (!$user) continue;

                // Save to notifications table
                $user->notifications()->create([
                    'title' => $notification->title,
                    'message' => $notification->message,
                    'type' => 'scheduled',
                    'category' => 'inbox',
                ]);

                // Send email if enabled
                if ($notification->send_email) {
                    Mail::to($user->email)->queue(new SendNotificationMail(
                        $notification->title,
                        $notification->message
                    ));
                }
            }

            // Update status
            $notification->update([
                'status' => 'sent',
                'updated_at' => now(),
            ]);

        } catch (\Exception $e) {
            $this->scheduledNotification->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
```

---

### 5️⃣ **Create Scheduler Command**

```bash
php artisan make:command ProcessScheduledNotifications
```

**File:** `app/Console/Commands/ProcessScheduledNotifications.php`
```php
<?php

namespace App\Console\Commands;

use App\Jobs\SendScheduledNotificationJob;
use App\Models\ScheduledNotification;
use Illuminate\Console\Command;

class ProcessScheduledNotifications extends Command
{
    protected $signature = 'notifications:process-scheduled';
    protected $description = 'Process and send scheduled notifications that are due';

    public function handle()
    {
        $this->info('Processing scheduled notifications...');

        // Get all pending notifications where scheduled_at <= now
        $dueNotifications = ScheduledNotification::where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->get();

        $this->info("Found {$dueNotifications->count()} notifications to send");

        foreach ($dueNotifications as $notification) {
            SendScheduledNotificationJob::dispatch($notification);
            $this->info("Dispatched job for notification ID: {$notification->id}");
        }

        $this->info('Done!');
    }
}
```

---

### 6️⃣ **Setup Scheduler (Kernel.php)**

**File:** `app/Console/Kernel.php`

```php
protected function scheduleTimezoneAware()
{
    return 'UTC';
}

protected function schedule(Schedule $schedule)
{
    // Run every 5 minutes to check and send scheduled notifications
    $schedule->command('notifications:process-scheduled')
        ->everyFiveMinutes()
        ->description('Process scheduled notifications');

    // Alternative: Run every 1 minute for more frequent checks
    // $schedule->command('notifications:process-scheduled')
    //     ->everyMinute()
    //     ->description('Process scheduled notifications');
}
```

---

### 7️⃣ **Run Queue Worker (Production)**

You have 2 options:

**Option A: Async Queue (Recommended)**
```bash
# Development
php artisan queue:work

# Production (with supervisor)
# Create file: /etc/supervisor/conf.d/laravel-queue.conf
[program:laravel-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/laravel/artisan queue:work
numprocs=4
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/path/to/laravel/storage/logs/queue.log
```

**Option B: Scheduler Only (Simpler)**
```bash
# Add to crontab
* * * * * php /path/to/laravel/artisan schedule:run >> /dev/null 2>&1
```

---

### 8️⃣ **Create API Endpoint - Get Scheduled Notifications**

**GET** `/api/notifications/scheduled`

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "title": "Team meeting",
            "message": "Don't forget...",
            "scheduled_at": "2026-04-08T14:00:00",
            "status": "pending",
            "recipients_count": 3
        }
    ]
}
```

---

### 9️⃣ **Create API Endpoint - Delete Scheduled Notification**

**DELETE** `/api/notifications/scheduled/{id}`

```php
public function deleteScheduled($id)
{
    $notification = ScheduledNotification::findOrFail($id);
    
    // Only allow user who created it to delete
    if ($notification->user_id !== auth()->id()) {
        return response()->json(['error' => 'Unauthorized'], 403);
    }

    $notification->delete();

    return response()->json([
        'success' => true,
        'message' => 'Scheduled notification deleted'
    ]);
}
```

---

### 🔟 **Create API Endpoint - Edit Scheduled Notification**

**PUT** `/api/notifications/scheduled/{id}`

```php
public function updateScheduled($id, Request $request)
{
    $notification = ScheduledNotification::findOrFail($id);
    
    if ($notification->user_id !== auth()->id()) {
        return response()->json(['error' => 'Unauthorized'], 403);
    }

    // Can only edit pending notifications
    if ($notification->status !== 'pending') {
        return response()->json([
            'error' => 'Can only edit pending notifications'
        ], 422);
    }

    $validated = $request->validate([
        'title' => 'string|max:255',
        'message' => 'string',
        'recipient_ids' => 'array|min:1',
        'send_email' => 'boolean',
        'scheduled_at' => 'date|after:now',
    ]);

    $notification->update($validated);

    return response()->json([
        'success' => true,
        'data' => $notification
    ]);
}
```

---

## 📦 Routes Setup

**File:** `routes/api.php`

```php
Route::middleware('auth:sanctum')->group(function () {
    // Scheduled Notifications
    Route::post('/notifications/save-scheduled', [NotificationController::class, 'saveScheduled']);
    Route::get('/notifications/scheduled', [NotificationController::class, 'getScheduled']);
    Route::put('/notifications/scheduled/{id}', [NotificationController::class, 'updateScheduled']);
    Route::delete('/notifications/scheduled/{id}', [NotificationController::class, 'deleteScheduled']);
});
```

---

## ✅ Testing Checklist

- [ ] Database migration berjalan tanpa error
- [ ] Model ScheduledNotification bisa dibuat
- [ ] API endpoint `/notifications/save-scheduled` bisa menerima data
- [ ] Notifications disimpan ke database
- [ ] Command `php artisan notifications:process-scheduled` bisa dijalankan manual
- [ ] Notification terkirim ke user saat scheduled_at tercapai
- [ ] Email terkirim jika `send_email = true`
- [ ] Status berubah menjadi "sent" setelah kirim
- [ ] Queue worker berjalan di background (jika pakai async)
- [ ] Cron job berjalan setiap 5 menit (jika pakai scheduler)

---

## 🚀 Deployment Checklist

- [ ] Migration sudah di-run di production
- [ ] Queue worker running dengan supervisor (jika pakai async)
- [ ] Cron job added to server crontab (jika pakai scheduler)
- [ ] Email configuration sudah benar (untuk send_email)
- [ ] Logs bisa diakses untuk debugging
- [ ] Error handling implemented untuk failed notifications

---

## 📞 Frontend Integration

Frontend sudah handle:
- ✅ UI untuk schedule notifications
- ✅ Save draft & scheduled ke localStorage
- ✅ Sidebar category untuk drafts & scheduled

Frontend butuh panggil endpoint:
- `POST /api/notifications/save-scheduled` - Saat user click "Schedule"
- `GET /api/notifications/scheduled` - Untuk pull list dari backend
- `PUT /api/notifications/scheduled/{id}` - Untuk edit
- `DELETE /api/notifications/scheduled/{id}` - Untuk cancel

---

**Prepared by:** Frontend Team  
**Date:** April 7, 2026
