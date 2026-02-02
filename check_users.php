<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Total users: " . \App\Models\User::count() . "\n\n";
echo "By Role:\n";
foreach (\App\Models\User::select('role', \Illuminate\Support\Facades\DB::raw('count(*) as count'))
    ->groupBy('role')
    ->get() as $r) {
    echo "  {$r->role}: {$r->count}\n";
}

echo "\n\nFirst 5 users:\n";
foreach (\App\Models\User::take(5)->get() as $user) {
    echo "  ID: {$user->id}, Name: {$user->name}, Role: {$user->role}, Employee Type: " . ($user->employee ? $user->employee->employee_type : 'N/A') . "\n";
}
