<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Http\Kernel');

// Create a fake request to /api/admin/users
$request = \Illuminate\Http\Request::create('/api/admin/users', 'GET');

// Set token if you have one (replace with a valid token)
// $request->headers->set('Authorization', 'Bearer YOUR_TOKEN_HERE');

$response = $kernel->handle($request);

echo "Status Code: " . $response->getStatusCode() . "\n\n";

$data = json_decode($response->getContent(), true);

if (isset($data['data'])) {
    echo "Total users returned: " . count($data['data']) . "\n\n";
    echo "Roles found:\n";
    $roles = array_count_values(array_column($data['data'], 'role'));
    foreach ($roles as $role => $count) {
        echo "  {$role}: {$count}\n";
    }
    
    echo "\n\nFirst 3 users:\n";
    foreach (array_slice($data['data'], 0, 3) as $user) {
        echo "  - {$user['name']} ({$user['role']})\n";
    }
} else {
    echo "Response: " . substr($response->getContent(), 0, 500) . "\n";
}

$kernel->terminate($request, $response);
