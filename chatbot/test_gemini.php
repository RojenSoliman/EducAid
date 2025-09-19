<?php
// Simple test to check if Gemini API is working
$API_KEY = 'AIzaSyDzc8HJ7mpbjkftNtzTP3i1u-DeOXpxUUs';
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . urlencode($API_KEY);

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