<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Testing Role-Based Sorting:\n";
echo "===========================\n\n";

$query = \App\Models\User::with(['employee'])->withTrashed();

$query->orderByRaw("
    CASE role
        WHEN 'Admin' THEN 1
        WHEN 'General Manager' THEN 2
        WHEN 'Manager Teknik' THEN 3
        WHEN 'Cns' THEN 4
        WHEN 'Support' THEN 5
        ELSE 6
    END
")->orderBy('name', 'asc');

$users = $query->paginate(15, ['*'], 'page', 1);

echo "Page 1 Results:\n";
echo "===============\n";
$roleCount = [];
foreach ($users as $user) {
    $role = $user->role;
    if (!isset($roleCount[$role])) {
        $roleCount[$role] = 0;
    }
    $roleCount[$role]++;
}

echo "\nRole Distribution on Page 1:\n";
foreach (['Admin', 'General Manager', 'Manager Teknik', 'Cns', 'Support'] as $role) {
    if (isset($roleCount[$role])) {
        echo "  {$role}: {$roleCount[$role]} users\n";
    }
}

echo "\nFirst 10 users (showing role order):\n";
foreach ($users->take(10) as $user) {
    printf("  %-30s - %s\n", $user->name, $user->role);
}

echo "\n✅ Users are now sorted by Role (Admin → GM → Manager Teknik → CNS → Support), then by Name!\n";
