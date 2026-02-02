#!/bin/bash

echo "Testing /api/admin/users endpoint..."
echo "======================================"
echo ""

# Get a token first (you need to update with actual credentials)
echo "If you have authentication token, please use it to test the API"
echo "Example: curl -H 'Authorization: Bearer YOUR_TOKEN' http://localhost:8000/api/admin/users | jq '.data[] | {name: .name, role: .role}'"
echo ""
echo "For now, checking database directly..."
echo ""

cd c:/projekflutter/backend_atoms
php -r "
require __DIR__ . '/vendor/autoload.php';
\$app = require_once __DIR__ . '/bootstrap/app.php';
\$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo \"Database Check:\n\";
echo \"===============\n\n\";
echo \"Total Users: \" . \App\Models\User::count() . \"\n\n\";
echo \"Users by Role:\n\";
foreach (\App\Models\User::select('role', \Illuminate\Support\Facades\DB::raw('count(*) as count'))
    ->groupBy('role')
    ->orderBy('role')
    ->get() as \$row) {
    printf(\"  %-20s: %d users\n\", \$row->role, \$row->count);
}

echo \"\n\nSample Users (showing first 3 from each role):\n\";
foreach (['Admin', 'Cns', 'Support', 'Manager Teknik'] as \$role) {
    \$users = \App\Models\User::where('role', \$role)->limit(3)->get();
    if (\$users->count() > 0) {
        echo \"\n  \$role:\n\";
        foreach (\$users as \$user) {
            echo \"    - {\$user->name} ({\$user->email})\n\";
        }
    }
}
"
