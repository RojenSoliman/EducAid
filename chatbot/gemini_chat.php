<?php
// gemini_chat.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');  // adjust if you want strict origin control
header('Access-Control-Allow-Headers: Content-Type');

$API_KEY = 'AIzaSyDzc8HJ7mpbjkftNtzTP3i1u-DeOXpxUUs'; // ← move to a secure include if you like

// Read JSON POST
$input = json_decode(file_get_contents('php://input'), true);
$userMessage = trim($input['message'] ?? '');

if ($userMessage === '') {
  http_response_code(400);
  echo json_encode(['error' => 'Empty message']);
  exit;
}

// Gemini REST endpoint (Flash = cheaper/faster; Pro = smarter)
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . urlencode($API_KEY);

// Build prompt with a system-style instruction + user message
$payload = [
  'contents' => [[
    'role' => 'user',
    'parts' => [[
      'text' => "You are EducAid’s assistant for the City of General Trias. Be concise, friendly, and helpful. ".
                "If asked about personal data, follow RA 10173 (Data Privacy Act). ".
                "If asked about schedules/requirements, remind users that official announcements appear on the EducAid portal.\n\n" .
                "USER: " . $userMessage
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
]);

$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
  http_response_code(500);
  echo json_encode(['error' => 'Curl error: '.$err]);
  exit;
}

$data = json_decode($response, true);

// Parse Gemini text
$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '(No response)';

echo json_encode(['reply' => $text]);
