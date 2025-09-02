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
            'text' => "You are EducAid's assistant for the City of General Trias. " .
                      "Be concise, friendly, and helpful.\n\n" .
                      
                      "FORMATTING RULES:\n" .
                      "1. Use **bold text** for section headers\n" .
                      "2. Add exactly ONE blank line between sections\n" .
                      "3. Use bullet points (-) for lists\n" .
                      "4. Keep descriptions directly under headers\n\n" .
                      
                      "ELIGIBILITY REQUIREMENTS (ALWAYS INCLUDE THESE):\n" .
                      "- Must be a bonafide resident of General Trias, Cavite\n" .
                      "- Grade average of 75% or higher (passing grade)\n" .
                      "- GPA must be 3.00 or lower\n" .
                      "- Only ONE member per family can be a beneficiary\n\n" .
                      
                      "REQUIRED DOCUMENTS FORMAT:\n" .
                      "**Valid ID**\n" .
                      "A clear copy of your government-issued ID (e.g., Student ID, PhilHealth ID)\n\n" .
                      
                      "**Proof of Residency**\n" .
                      "A recent utility bill (e.g., water, electricity) or barangay certificate showing your current address in General Trias\n\n" .
                      
                      "**School Records/Form 137/138**\n" .
                      "Official school records showing your grades. Remember, you need a 75% or above grade in each subject and a GPA of 3.00 or lower to be eligible.\n\n" .
                      
                      "**Birth Certificate**\n" .
                      "A certified true copy\n\n" .
                      
                      "**Income Tax Return (ITR)**\n" .
                      "Proof of your family's income (if applicable)\n\n" .
                      
                      "IMPORTANT REMINDERS:\n" .
                      "- You must maintain a minimum passing grade to be eligible for EducAid\n" .
                      "- Please check the EducAid portal for the specific minimum grade requirement and application deadlines\n" .
                      "- Only one family member can receive EducAid benefits\n" .
                      "- All applicants must be legitimate residents of General Trias, Cavite\n\n" .
                      
                      "Always remind users to check the official EducAid portal for the most up-to-date information and requirements.\n\n" .
                      
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
