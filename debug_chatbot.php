<?php
// Simple test to debug chatbot issue
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing chatbot endpoint...\n";

// Simulate POST data
$_POST['test'] = 'true';
$postData = json_encode(['message' => 'hello']);

// Set up environment
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';

// Temporarily override php://input
file_put_contents('php://temp', $postData);

echo "POST data: " . $postData . "\n";

// Try to include the chatbot file
try {
    // Capture output
    ob_start();
    include 'chatbot/gemini_chat.php';
    $output = ob_get_clean();
    echo "Chatbot output: " . $output . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
} catch (Error $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
}
?>