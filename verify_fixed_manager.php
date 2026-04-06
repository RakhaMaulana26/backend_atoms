<?php
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Employee;

$fixed = Employee::where('is_fixed_manager', true)->with('user')->get();

echo "========== FIXED MANAGER VERIFICATION ==========\n\n";
echo sprintf("%-20s | %-15s | %-10s\n", "Name", "Employee Type", "Fixed");
echo str_repeat("-", 50) . "\n";

foreach ($fixed as $emp) {
    echo sprintf("%-20s | %-15s | %-10s\n", 
        $emp->user->name,
        $emp->employee_type,
        $emp->is_fixed_manager ? 'YES ✓' : 'NO'
    );
}

echo "\n========== TOTAL ==========\n";
echo "Fixed Managers: " . count($fixed) . "\n";
echo "Expected: 3 (Andi, Efried, Netty)\n";
