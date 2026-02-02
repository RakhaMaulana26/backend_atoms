<?php

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$apiKey = $_ENV['GEMINI_API_KEY'] ?? '';

if (empty($apiKey)) {
    echo "ERROR: GEMINI_API_KEY not found in .env\n";
    exit(1);
}

echo "API Key: " . substr($apiKey, 0, 10) . "..." . substr($apiKey, -5) . "\n";

// Test multiple models
$models = [
    'gemini-2.5-flash',
    'gemini-2.5-pro', 
    'gemma-3-4b-it',
    'gemini-flash-latest',
];

foreach ($models as $model) {
    echo "\n=== Testing: $model ===\n";
    $url = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=" . $apiKey;

    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => 'Say hello']
                ]
            ]
        ]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "Status: $httpCode\n";
    
    if ($httpCode == 200) {
        $json = json_decode($response, true);
        $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? 'No response';
        echo "SUCCESS! Response: $text\n";
        break; // Found working model
    } else {
        $json = json_decode($response, true);
        $msg = $json['error']['message'] ?? 'Unknown error';
        echo "Error: " . substr($msg, 0, 100) . "...\n";
    }
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

echo "Testing Gemini API...\n";
