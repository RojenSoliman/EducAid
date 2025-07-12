<?php
// Start the session to store the unique_id
session_start();

// Check if the form is submitted
if (isset($_POST['generate'])) {
    // Generate a unique identifier (e.g., timestamp or random string)
    // The 'true' argument adds more entropy for better uniqueness.
    $unique_id = uniqid('educaid_qr_', true);

    // Store the unique ID in the session
    $_SESSION['unique_id'] = $unique_id;

    // Redirect back to qr_code.php to display the QR code
    // IMPORTANT: Make sure no output (like HTML or spaces) comes before this header!
    header('Location: qr_code.php');
    exit; // Always exit after a header redirect
} else {
    // Optional: If someone tries to access this page directly without submitting the form,
    // you might redirect them or show an error.
    // For now, let's just redirect them back to the main page.
    header('Location: qr_code.php');
    exit;
}
?>