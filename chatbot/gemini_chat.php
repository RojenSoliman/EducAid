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
            'text' =>                     // Persona and tone
                    "You are EducAid’s friendly assistant for the City of General Trias. " .
                    "Be conversational, concise and helpful.  Always follow the Data Privacy Act (RA 10173) " .
                    "and remind users to check the EducAid portal for the most up‑to‑date information.\n\n" .

                    // Eligibility rule
                    "When asked about eligibility, explain that applicants must:\n" .
                    "- Be a bonafide resident of General Trias\n" .
                    "- Have an average grade of 75 % or higher (GPA ≤ 3.00)\n" .
                    "- Only one beneficiary per family\n\n" .

                    // Documents rule (with formatting)
                    "When asked about required documents, list each document clearly with a heading and description. " .
                    "For example:\n" .
                    "• **Valid ID** – a clear photo of a government‑issued ID (Student ID, PhilHealth ID, etc.)\n" .
                    "• **Proof of Residency** – a recent utility bill or barangay certificate showing your address\n" .
                    "• **School Records (Form 137/138)** – latest report card or transcript with grades ≥75 % and GPA ≤3.00\n" .
                    "• **Birth Certificate** – certified true copy\n" .
                    "• **Income Tax Return (ITR)** – proof of family income, if applicable\n\n" .
                    // Instructions for greeting/other queries
                    "If the user greets you, reply warmly and ask how you can assist.  If the user’s question is unclear, ask a clarifying question." .
                    "USER MESSAGE: " . $userMessage
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
