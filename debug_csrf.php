<?php
// Debug CSRF Token Issue
session_start();
require_once '../../config/database.php';
require_once '../../includes/CSRFProtection.php';

function formatTokens($tokens) {
    if (empty($tokens)) {
        return 'MISSING!';
    }

    if (is_array($tokens)) {
        $preview = array_map(function ($token) {
            return substr($token, 0, 16) . '...';
        }, $tokens);
        return 'MULTI [' . implode(', ', $preview) . ']';
    }

    if (is_string($tokens)) {
        return substr($tokens, 0, 16) . '...';
    }

    return 'UNKNOWN FORMAT';
}

echo "<h2>CSRF Token Debug</h2>";
echo "<hr>";

// Show current session tokens
echo "<h3>Current Session Tokens:</h3>";
echo "<pre>";
print_r($_SESSION['csrf_tokens'] ?? 'No tokens in session');
echo "</pre>";

// Generate a test token
$test_token = CSRFProtection::generateToken('test_form');
echo "<h3>Generated Test Token:</h3>";
echo "<code>$test_token</code>";

// Show session after generation
echo "<h3>Session After Generation:</h3>";
echo "<pre>";
print_r($_SESSION['csrf_tokens']);
echo "</pre>";

// Test validation
echo "<h3>Validation Test:</h3>";
$valid = CSRFProtection::validateToken('test_form', $test_token);
echo "Valid: " . ($valid ? 'YES ✅' : 'NO ❌') . "<br>";

// Show session after validation
echo "<h3>Session After Validation:</h3>";
echo "<pre>";
print_r($_SESSION['csrf_tokens'] ?? 'No tokens in session');
echo "</pre>";

// Test the actual topbar form
echo "<hr>";
echo "<h3>Test Topbar Form:</h3>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<div style='background:#fff3cd;padding:10px;margin:10px 0;border:1px solid #ffc107;'>";
    echo "<strong>POST Data Received:</strong><br>";
    echo "CSRF Token from form: <code>" . htmlspecialchars($_POST['csrf_token'] ?? 'MISSING!') . "</code><br>";
    echo "Session Tokens: <code>" . htmlspecialchars(formatTokens($_SESSION['csrf_tokens']['topbar_settings'] ?? null)) . "</code><br>";
    
    $validation = CSRFProtection::validateToken('topbar_settings', $_POST['csrf_token'] ?? '');
    echo "<br><strong>Validation Result: " . ($validation ? '✅ VALID' : '❌ INVALID') . "</strong>";
    echo "</div>";
}

$token_for_form = CSRFProtection::generateToken('topbar_settings');
echo "<div style='background:#d1ecf1;padding:10px;margin:10px 0;border:1px solid #0dcaf0;'>";
echo "<strong>Generated token for form:</strong> <code>$token_for_form</code>";
echo "</div>";
?>

<form method="POST" style="background:#f8f9fa;padding:20px;margin:20px 0;border:1px solid #dee2e6;">
    <input type="hidden" name="csrf_token" value="<?= $token_for_form ?>">
    <input type="text" name="test_field" placeholder="Enter something" style="padding:5px;margin:10px 0;">
    <button type="submit" style="padding:5px 15px;background:#28a745;color:white;border:none;">Test Submit</button>
</form>

<hr>
<a href="?reset=1" style="padding:5px 15px;background:#dc3545;color:white;text-decoration:none;">Clear Session & Reload</a>

<?php
if (isset($_GET['reset'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
?>
