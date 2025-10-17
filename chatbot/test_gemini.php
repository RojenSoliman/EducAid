<?php
// Simple test to check if Gemini API is working
$envBootstrap = __DIR__ . '/../config/env.php';
if (is_file($envBootstrap)) {
    require_once $envBootstrap;
}

$API_KEY = getenv('GEMINI_API_KEY') ?: '';
if ($API_KEY === '') {
    echo "Missing GEMINI_API_KEY in environment\n";
    exit(1);
}

$url = 'https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=' . urlencode($API_KEY);

$payload = [
    'contents' => [[
        'role' => 'user',
        'parts' => [[
            'text' => 'Hello, are you working?'
        ]]
    ]]
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => false,  // Add this in case of SSL issues
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

echo "HTTP Code: " . $httpCode . "\n";
echo "CURL Error: " . $err . "\n";
echo "Response: " . $response . "\n";

if ($response) {
    $data = json_decode($response, true);
    if ($data && isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        echo "SUCCESS: " . $data['candidates'][0]['content']['parts'][0]['text'] . "\n";
    } else {
        echo "FAILED: Invalid response structure\n";
        print_r($data);
    }
}
?>