<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Database Verification\n";
echo "=====================\n\n";
echo "Total Users: " . \App\Models\User::count() . "\n\n";

echo "Users by Role:\n";
foreach (\App\Models\User::select('role', \Illuminate\Support\Facades\DB::raw('count(*) as count'))
    ->groupBy('role')
    ->orderBy('role')
    ->get() as $row) {
    printf("  %-20s: %d users\n", $row->role, $row->count);
}

echo "\n\nSample Users (first 2 from each role):\n";
foreach (['Admin', 'Cns', 'Support', 'Manager Teknik', 'General Manager'] as $role) {
    $users = \App\Models\User::where('role', $role)->limit(2)->get();
    if ($users->count() > 0) {
        echo "\n  {$role}:\n";
        foreach ($users as $user) {
            $empType = $user->employee ? $user->employee->employee_type : 'N/A';
            echo "    - {$user->name} ({$user->email}) - Employee Type: {$empType}\n";
        }
    }
}

echo "\n\n";
echo "Cache Status:\n";
echo "=============\n";

// Check if there's any cached users_list
$cacheDriver = \Illuminate\Support\Facades\Cache::getStore();
echo "Cache Driver: " . get_class($cacheDriver) . "\n";

// Try to show some sample cache keys (this depends on cache driver)
echo "\nNote: Cache has been cleared. All subsequent API calls should return fresh data.\n";
echo "If you still see only 'Support' users in the frontend:\n";
echo "  1. Refresh your browser (Ctrl+F5 or Cmd+Shift+R)\n";
echo "  2. Clear browser cache and localStorage\n";
echo "  3. Check browser console for any errors\n";
echo "  4. Verify network tab to see actual API response\n";
