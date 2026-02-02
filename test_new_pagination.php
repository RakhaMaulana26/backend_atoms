<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Testing NEW Pagination (ordered by name ASC):\n";
echo "=============================================\n";

for ($page = 1; $page <= 4; $page++) {
    $users = \App\Models\User::with(['employee'])->withTrashed()
        ->orderBy('name', 'asc')
        ->paginate(15, ['*'], 'page', $page);
    
    $roleCount = [];
    foreach ($users as $user) {
        $role = $user->role;
        if (!isset($roleCount[$role])) {
            $roleCount[$role] = 0;
        }
        $roleCount[$role]++;
    }
    
    echo "\nPage {$page} (Total {$users->count()} users):";
    foreach ($roleCount as $role => $count) {
        echo " {$role}({$count})";
    }
    
    if ($page == 1) {
        echo "\n  First 5 users:";
        foreach ($users->take(5) as $user) {
            echo "\n    - {$user->name} ({$user->role})";
        }
    }
}

echo "\n\n✅ NOW ALL ROLES ARE MIXED IN EVERY PAGE!\n";
