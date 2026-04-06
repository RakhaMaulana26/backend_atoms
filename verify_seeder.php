<?php
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;

$names = ['Aditya Huzairi P', 'Fajar Kusuma W', 'Andi Wibowo', 'Efried N.P.', 'Netty Septa C.'];
$users = User::whereIn('name', $names)->with('employee')->get();

echo "========== VERIFIKASI SEEDER REFACTORING ==========\n\n";
echo sprintf("%-20s | %-8s | %-15s | %-6s\n", "Name", "Grade", "Employee Type", "Group");
echo str_repeat("-", 70) . "\n";

foreach ($users as $user) {
    $emp = $user->employee;
    echo sprintf("%-20s | %-8s | %-15s | %-6s\n", 
        $user->name, 
        $user->grade,
        $emp->employee_type,
        $emp->group_number ?? '-'
    );
}

echo "\n========== SUMMARY ==========\n";
echo "✅ Aditya Huzairi P should be: CNS, grade 13\n";
echo "✅ Fajar Kusuma W should be: Support, grade 13\n";
echo "✅ Andi Wibowo should be: CNS, grade 15\n";
echo "✅ Efried N.P. should be: CNS, grade 15\n";
echo "✅ Netty Septa C. should be: CNS, grade 15\n";
