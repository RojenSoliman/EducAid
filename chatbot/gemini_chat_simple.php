<?php
// Test with a smaller payload to see if size is the issue
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

$envBootstrap = __DIR__ . '/../config/env.php';
if(is_file($envBootstrap)){
  require_once $envBootstrap;
}

$API_KEY = getenv('GEMINI_API_KEY') ?: '';
if($API_KEY === ''){
  http_response_code(500);
  echo json_encode(['error' => 'API key missing']);
  exit;
}

// Read JSON POST
$input = json_decode(file_get_contents('php://input'), true);
$userMessage = trim($input['message'] ?? '');

if ($userMessage === '') {
  http_response_code(400);
  echo json_encode(['error' => 'Empty message']);
  exit;
}

// Use fastest model available
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-lite:generateContent?key=' . urlencode($API_KEY);

// Optimized prompt - broader and more conversational
$payload = [
    'contents' => [[
        'role' => 'user',
        'parts' => [[
            'text' => "You are EducAid Assistant for the scholarship program in General Trias, Cavite.\n\n" .
                     "Your role:\n" .
                     "- Answer questions about eligibility, requirements, documents, application process, and deadlines\n" .
                     "- Help with general student concerns, academic guidance, and university/scholarship information\n" .
                     "- Be conversational, helpful, and friendly for casual chat or greetings\n" .
                     "- Keep responses concise (2-3 sentences for simple questions)\n\n" .
                     "Student message: " . $userMessage
        ]]
    ]],
    'generationConfig' => [
        'temperature' => 0.7,
        'topK' => 20,
        'topP' => 0.9,
        'maxOutputTokens' => 512,
        'candidateCount' => 1
    ]
];

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
  CURLOPT_POSTFIELDS => json_encode($payload),
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_CONNECTTIMEOUT => 5,        // Faster connection timeout
  CURLOPT_TIMEOUT => 15,              // Faster overall timeout
  CURLOPT_SSL_VERIFYPEER => true,     // Enable for production security
  CURLOPT_SSL_VERIFYHOST => 2,
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