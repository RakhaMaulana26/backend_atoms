<?php
require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$n = \App\Models\Notification::find(190);
echo 'ID 190 - Status: ' . $n->status . ', Error: ' . ($n->error_message ?? 'None') . PHP_EOL;

$n2 = \App\Models\Notification::find(189);
echo 'ID 189 - Status: ' . $n2->status . ', Error: ' . ($n2->error_message ?? 'None') . PHP_EOL;

// Check if inbox notifications were created
$inboxCount = \App\Models\Notification::where('type', 'inbox')->where('category', 'scheduled')->count();
echo 'Inbox notifications created: ' . $inboxCount . PHP_EOL;