<?php
/**
 * Fast Gemini Chatbot - Single Model Only
 * Uses gemini-1.5-flash (fastest and most reliable)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/chatbot_errors.log');

// Load environment
$envBootstrap = __DIR__ . '/../config/env.php';
if(is_file($envBootstrap)){
  require_once $envBootstrap;
}

$API_KEY = getenv('GEMINI_API_KEY') ?: '';
if($API_KEY === ''){
  error_log('[FastChatbot] Missing API key');
  http_response_code(500);
  echo json_encode(['error' => 'API key missing']);
  exit;
}

// Get user message
$input = json_decode(file_get_contents('php://input'), true);
$userMessage = trim($input['message'] ?? '');

if ($userMessage === '') {
  http_response_code(400);
  echo json_encode(['error' => 'Empty message']);
  exit;
}

// Use gemini-2.0-flash-exp - Gen 2 model (fastest available)
$model = 'gemini-2.0-flash-exp';
$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . urlencode($API_KEY);

// Optimized prompt
$prompt = "You are EducAid Assistant for the scholarship program in General Trias, Cavite.\n\n" .
          "Your role:\n" .
          "- Answer questions about eligibility, requirements, documents, application process, and deadlines\n" .
          "- Help with general student concerns, academic guidance, and university/scholarship information\n" .
          "- Be conversational, helpful, and friendly for casual chat or greetings\n" .
          "- Keep responses concise (2-3 sentences for simple questions)\n\n" .
          "Student message: " . $userMessage;

// Payload with optimized generation config
$payload = [
    'contents' => [[
        'role' => 'user',
        'parts' => [['text' => $prompt]]
    ]],
    'generationConfig' => [
        'temperature' => 0.7,
        'topK' => 20,
        'topP' => 0.9,
        'maxOutputTokens' => 512,
        'candidateCount' => 1
    ]
];

$startTime = microtime(true);

// Single API call with fast timeouts
$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
  CURLOPT_POSTFIELDS => json_encode($payload),
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_CONNECTTIMEOUT => 5,
  CURLOPT_TIMEOUT => 15,
  CURLOPT_SSL_VERIFYPEER => true,
  CURLOPT_SSL_VERIFYHOST => 2,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

$endTime = microtime(true);
$responseTime = round(($endTime - $startTime) * 1000);

// Log response time for monitoring
error_log("[FastChatbot] Response time: {$responseTime}ms | HTTP: {$httpCode} | Model: {$model}");

// Handle cURL errors
if ($curlError) {
  error_log("[FastChatbot] cURL error: {$curlError}");
  http_response_code(500);
  echo json_encode([
    'error' => 'Connection error',
    'detail' => 'Failed to connect to AI service',
    'response_time' => $responseTime
  ]);
  exit;
}

// Handle non-200 responses
if ($httpCode !== 200) {
  error_log("[FastChatbot] HTTP {$httpCode} | Response: " . substr($response, 0, 200));
  http_response_code(500);
  echo json_encode([
    'error' => 'API error',
    'detail' => "HTTP {$httpCode}",
    'response_time' => $responseTime
  ]);
  exit;
}

// Parse response
$data = json_decode($response, true);

if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
  error_log("[FastChatbot] Invalid response structure: " . substr($response, 0, 200));
  http_response_code(500);
  echo json_encode([
    'error' => 'Invalid response',
    'detail' => 'Unexpected API response format',
    'response_time' => $responseTime
  ]);
  exit;
}

// Success! Return the response
$reply = trim($data['candidates'][0]['content']['parts'][0]['text']);

echo json_encode([
  'reply' => $reply,
  'model' => $model,
  'response_time_ms' => $responseTime,
  'success' => true
]);
