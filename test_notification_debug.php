<?php

/**
 * Debug script untuk testing notification API
 * Jalankan dari command line: php artisan tinker < test_notification_debug.php
 * atau gunakan: php test_notification_debug.php
 */

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Notification;
use App\Models\User;

echo "=== DEBUGGING NOTIFICATION 404 ERROR ===\n\n";

// 1. Check if notification exists
echo "1. Checking if notification ID 2000010 exists...\n";
$notification = Notification::withTrashed()->find(2000010);

if ($notification) {
    echo "✓ Notification found!\n";
    echo "  - ID: " . $notification->id . "\n";
    echo "  - Title: " . $notification->title . "\n";
    echo "  - User ID: " . $notification->user_id . "\n";
    echo "  - Sender ID: " . $notification->sender_id . "\n";
    echo "  - Is Read: " . ($notification->is_read ? 'Yes' : 'No') . "\n";
    echo "  - Deleted At: " . ($notification->deleted_at ? $notification->deleted_at : 'Not deleted') . "\n";
} else {
    echo "✗ Notification NOT found in database!\n";
    echo "  This is why you're getting 404 error.\n\n";
    
    // Show notifications that DO exist
    echo "2. Available notifications in database:\n";
    $allNotifications = Notification::withTrashed()->limit(10)->get();
    
    if ($allNotifications->count() > 0) {
        foreach ($allNotifications as $notif) {
            echo "  - ID: {$notif->id}, User: {$notif->user_id}, Title: {$notif->title}\n";
        }
    } else {
        echo "  ✗ No notifications found in database at all!\n";
    }
    exit(1);
}

// 3. Check if user exists and has access
echo "\n3. Checking user access permissions...\n";
$currentUserId = auth()->id() ?? 1; // Default to user 1 if not authenticated

echo "  - Current User ID: " . $currentUserId . "\n";
echo "  - Has permission: " . (
    ($notification->user_id == $currentUserId || $notification->sender_id == $currentUserId) 
    ? 'YES' : 'NO'
) . "\n";

if ($notification->user_id != $currentUserId && $notification->sender_id != $currentUserId) {
    echo "  ✗ User does NOT have access to this notification!\n";
    echo "  Notification belongs to:\n";
    echo "    - User ID: {$notification->user_id}\n";
    echo "    - Sender ID: {$notification->sender_id}\n";
}

// 4. Test update operation
echo "\n4. Testing mark as read operation...\n";
try {
    $notification->is_read = true;
    $notification->read_at = now();
    $notification->save();
    echo "✓ Successfully updated notification!\n";
    echo "  - Is Read: " . ($notification->is_read ? 'Yes' : 'No') . "\n";
    echo "  - Read At: " . $notification->read_at . "\n";
} catch (Exception $e) {
    echo "✗ Error updating notification: " . $e->getMessage() . "\n";
}

echo "\n=== END DEBUG ===\n";
