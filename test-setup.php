<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Test Database Connection
echo "🔍 Testing Database Connection...\n";
try {
    DB::connection()->getPdo();
    echo "✅ Database connected successfully!\n";
    echo "   Database: " . DB::connection()->getDatabaseName() . "\n\n";
} catch (\Exception $e) {
    echo "❌ Database connection failed!\n";
    echo "   Error: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Check Tables
echo "🔍 Checking Tables...\n";
$tables = ['users', 'employees', 'shifts', 'roster_periods', 'account_tokens', 'notifications'];
foreach ($tables as $table) {
    if (Schema::hasTable($table)) {
        $count = DB::table($table)->count();
        echo "✅ Table '{$table}' exists ({$count} rows)\n";
    } else {
        echo "❌ Table '{$table}' not found!\n";
    }
}

echo "\n🔍 Checking Admin User...\n";
$admin = DB::table('users')->where('email', 'admin@airnav.com')->first();
if ($admin) {
    echo "✅ Admin user exists\n";
    echo "   Email: {$admin->email}\n";
    echo "   Name: {$admin->name}\n";
    echo "   Role: {$admin->role}\n";
} else {
    echo "❌ Admin user not found!\n";
}

echo "\n🔍 Checking Shifts...\n";
$shifts = DB::table('shifts')->get();
if ($shifts->count() > 0) {
    echo "✅ Shifts found: " . $shifts->count() . "\n";
    foreach ($shifts as $shift) {
        echo "   - {$shift->name}\n";
    }
} else {
    echo "❌ No shifts found!\n";
}

echo "\n✅ All checks completed!\n";
