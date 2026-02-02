<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "User Distribution by ID and Role:\n";
echo "==================================\n\n";

$users = \App\Models\User::orderBy('id', 'asc')->get();

echo "Total Users: " . $users->count() . "\n\n";

echo "ID Range by Role:\n";
foreach (['Admin', 'Manager Teknik', 'Cns', 'Support'] as $role) {
    $roleUsers = $users->where('role', $role);
    if ($roleUsers->count() > 0) {
        $minId = $roleUsers->min('id');
        $maxId = $roleUsers->max('id');
        printf("  %-20s: ID %2d - %2d (%d users)\n", $role, $minId, $maxId, $roleUsers->count());
    }
}

echo "\n\nNow testing pagination (ordered by created_at DESC):\n";
echo "=====================================================\n";

for ($page = 1; $page <= 4; $page++) {
    $users = \App\Models\User::with(['employee'])->withTrashed()
        ->orderBy('created_at', 'desc')
        ->paginate(15, ['*'], 'page', $page);
    
    $roleCount = [];
    foreach ($users as $user) {
        $role = $user->role;
        if (!isset($roleCount[$role])) {
            $roleCount[$role] = 0;
        }
        $roleCount[$role]++;
    }
    
    echo "\nPage {$page}:";
    foreach ($roleCount as $role => $count) {
        echo " {$role}({$count})";
    }
}

echo "\n\n";
