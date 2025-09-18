<?php
// Test with a smaller payload to see if size is the issue
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

$API_KEY = 'AIzaSyCU7iNNvWUQv9-dBflcV-VlpIpBeTiB5dI';

// Read JSON POST
$input = json_decode(file_get_contents('php://input'), true);
$userMessage = trim($input['message'] ?? '');

if ($userMessage === '') {
  http_response_code(400);
  echo json_encode(['error' => 'Empty message']);
  exit;
}

$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . urlencode($API_KEY);

// Simple, shorter prompt for testing
$payload = [
    'contents' => [[
        'role' => 'user',
        'parts' => [[
            'text' => "You are EducAid Assistant for General Trias City. Help with scholarship questions. USER MESSAGE: " . $userMessage
        ]]
    ]],
];

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
  CURLOPT_POSTFIELDS => json_encode($payload),
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 30,
  CURLOPT_SSL_VERIFYPEER => false,
  CURLOPT_SSL_VERIFYHOST => false,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
  http_response_code(500);
  echo json_encode(['error' => 'Connection error: '.$err]);
  exit;
}

if ($httpCode !== 200) {
  http_response_code(500);
  echo json_encode(['error' => 'API Error: HTTP ' . $httpCode]);
  exit;
}

$data = json_decode($response, true);

if (!$data || !isset($data['candidates'][0]['content']['parts'][0]['text'])) {
  http_response_code(500);
  echo json_encode(['error' => 'Invalid response format']);
  exit;
}

$text = $data['candidates'][0]['content']['parts'][0]['text'];
echo json_encode(['reply' => $text]);
?>