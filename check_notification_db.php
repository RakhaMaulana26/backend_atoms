<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== CHECKING NOTIFICATIONS DATABASE ===\n\n";

// Check total notifications
$total = DB::table('notifications')->count();
echo "Total notifications in database: {$total}\n\n";

// Check max ID
$maxId = DB::table('notifications')->max('id');
echo "Max notification ID: " . ($maxId ?? 'None') . "\n";

// Check if ID 2000010 exists
$notification = DB::table('notifications')->find(2000010);
if ($notification) {
    echo "\n✓ Notification ID 2000010 EXISTS\n";
    echo "User ID: " . $notification->user_id . "\n";
    echo "Title: " . $notification->title . "\n";
} else {
    echo "\n✗ Notification ID 2000010 NOT FOUND\n";
}

// Show recent notifications
echo "\n=== RECENT NOTIFICATIONS (Last 5) ===\n";
$recent = DB::table('notifications')
    ->select('id', 'user_id', 'sender_id', 'title', 'is_read', 'created_at')
    ->latest('id')
    ->limit(5)
    ->get();

foreach ($recent as $notif) {
    echo "ID: {$notif->id} | User: {$notif->user_id} | Title: {$notif->title} | Read: " . ($notif->is_read ? 'Yes' : 'No') . "\n";
}

echo "\n=== END CHECK ===\n";
?>
