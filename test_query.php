<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Testing Query WITHOUT Filters\n";
echo "================================\n\n";

$query = \App\Models\User::with(['employee' => function($q) {
    $q->withTrashed();
}])->withTrashed();

$query->orderBy('created_at', 'desc');

$users = $query->paginate(15);

echo "Total Users: " . $users->total() . "\n";
echo "Current Page: " . $users->currentPage() . "\n";
echo "Per Page: " . $users->perPage() . "\n";
echo "Returned: " . $users->count() . "\n\n";

echo "Roles in current page:\n";
$roleCount = [];
foreach ($users as $user) {
    $role = $user->role;
    if (!isset($roleCount[$role])) {
        $roleCount[$role] = 0;
    }
    $roleCount[$role]++;
}

foreach ($roleCount as $role => $count) {
    echo "  {$role}: {$count}\n";
}

echo "\n\nFirst 5 users:\n";
foreach ($users->take(5) as $user) {
    $empType = $user->employee ? $user->employee->employee_type : 'N/A';
    echo "  - ID: {$user->id}, {$user->name} ({$user->role}) - Employee Type: {$empType}\n";
}

echo "\n\n";
echo "Testing Query WITH employee_type=Support Filter\n";
echo "================================================\n\n";

$query2 = \App\Models\User::with(['employee' => function($q) {
    $q->withTrashed();
}])->withTrashed();

$query2->whereHas('employee', function($q) {
    $q->where('employee_type', 'Support');
});

$query2->orderBy('created_at', 'desc');

$users2 = $query2->paginate(15);

echo "Total Users: " . $users2->total() . "\n";
echo "Returned: " . $users2->count() . "\n\n";

echo "All returned users should be 'Support':\n";
foreach ($users2->take(5) as $user) {
    echo "  - {$user->name} ({$user->role})\n";
}
