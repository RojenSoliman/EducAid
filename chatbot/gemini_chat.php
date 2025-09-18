<?php
// gemini_chat.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');  // adjust if you want strict origin control
header('Access-Control-Allow-Headers: Content-Type');

// Enable error logging for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/chatbot_errors.log');

$API_KEY = 'AIzaSyDzc8HJ7mpbjkftNtzTP3i1u-DeOXpxUUs'; // ← move to a secure include if you like

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

$API_KEY = 'AIzaSyCU7iNNvWUQv9-dBflcV-VlpIpBeTiB5dI';

// Read JSON POST data
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
            'text' =>
                    "You are EducAid Assistant, the official AI helper for the EducAid scholarship program in General Trias City, Cavite. " .
                    "Your role is to provide accurate, helpful, and friendly assistance to students and families seeking information about educational financial assistance.\n\n" .

                    "**CORE GUIDELINES:**\n" .
                    "- Be conversational, warm, and encouraging\n" .
                    "- Keep responses concise but comprehensive\n" .
                    "- Always respect data privacy (RA 10173)\n" .
                    "- Direct users to official channels for sensitive matters\n" .
                    "- Remind users to verify information on the official EducAid portal\n\n" .

                    "**SCHOLARSHIP ELIGIBILITY REQUIREMENTS:**\n" .
                    "When asked about eligibility, clearly explain:\n" .
                    "1. **Residency**: Must be a bonafide resident of General Trias City, Cavite\n" .
                    "2. **Academic Performance**: Minimum average grade of 75% or higher (GPA ≤ 3.00)\n" .
                    "3. **Family Limit**: Only one beneficiary per family\n" .
                    "4. **Enrollment**: Must be enrolled or planning to enroll in an accredited educational institution\n\n" .

                    "**REQUIRED DOCUMENTS:**\n" .
                    "When asked about documents, format the response clearly:\n" .
                    "**📋 Required Documents for EducAid Application:**\n\n" .
                    "• **Valid Government ID** – Clear photo/scan of student ID, PhilHealth ID, postal ID, etc.\n" .
                    "• **Proof of Residency** – Recent utility bill, barangay certificate, or lease agreement\n" .
                    "• **Academic Records** – Form 137/138, transcript, or latest report card showing grades ≥75%\n" .
                    "• **Birth Certificate** – PSA-issued certified true copy\n" .
                    "• **Income Documentation** – Family ITR, certificate of income, or employment records\n" .
                    "• **Enrollment Certificate** – Proof of current or planned enrollment\n\n" .

                    "**COMMON TOPICS TO ADDRESS:**\n" .
                    "- Application deadlines and slot availability\n" .
                    "- Step-by-step application process\n" .
                    "- Contact information for EducAid office\n" .
                    "- Scholarship coverage and benefits\n" .
                    "- Appeal or reapplication procedures\n\n" .

                    "**RESPONSE STYLE:**\n" .
                    "- Start with a friendly acknowledgment\n" .
                    "- Use bullet points and headers for clarity\n" .
                    "- End with next steps or helpful suggestions\n" .
                    "- Include relevant contact info when appropriate\n\n" .

                    "**CONTACT INFORMATION TO SHARE:**\n" .
                    "- Email: educaid@generaltrias.gov.ph\n" .
                    "- Phone: (046) 509-5555\n" .
                    "- Office: General Trias City Hall\n\n" .

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
  CURLOPT_SSL_VERIFYPEER => false,  // Add this for SSL issues
  CURLOPT_SSL_VERIFYHOST => false,  // Add this for SSL issues
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

// Log the raw response for debugging
error_log("HTTP Code: " . $httpCode);
error_log("Raw Response: " . $response);

if ($err) {
  error_log("CURL Error: " . $err);
  http_response_code(500);
  echo json_encode(['error' => 'Connection error: '.$err]);
  exit;
}

if ($httpCode !== 200) {
  error_log("HTTP Error: " . $httpCode . " - Response: " . $response);
  http_response_code(500);
  echo json_encode(['error' => 'API Error: HTTP ' . $httpCode]);
  exit;
}

$data = json_decode($response, true);

if (!$data) {
  error_log("JSON Decode Error: " . json_last_error_msg());
  http_response_code(500);
  echo json_encode(['error' => 'Invalid response format']);
  exit;
}

// Check for API errors in response
if (isset($data['error'])) {
  error_log("API Error: " . json_encode($data['error']));
  http_response_code(500);
  echo json_encode(['error' => 'API Error: ' . ($data['error']['message'] ?? 'Unknown error')]);
  exit;
}

// Parse Gemini text
$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '(No response)';

echo json_encode(['reply' => $text]);