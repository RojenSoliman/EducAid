<?php
session_start();
require_once '../config/database.php';
require_once '../includes/permissions.php';

// Only allow super admin
if (!isset($_SESSION['admin_id'])) {
    die('Access denied - Please login as super admin');
}

$role = getCurrentAdminRole($connection);
if ($role !== 'super_admin') {
    die('Super admin only');
}

echo "<h2>Populating Contact Content Blocks</h2>";
echo "<pre>";

// Default blocks data
$blocks = [
    // Hero Section
    ['hero_title', 'Contact'],
    ['hero_subtitle', "We're here to assist with application issues, document submission, schedules, QR release, and portal access concerns."],
    
    // Contact Cards
    ['visit_title', 'Visit Us'],
    ['visit_address', 'City Government of General Trias, Cavite'],
    ['visit_hours', 'Mon–Fri • 8:00 AM – 5:00 PM<br/>(excluding holidays)'],
    
    ['call_title', 'Call Us'],
    ['call_primary', '(046) 886-4454'],
    ['call_secondary', '(046) 509-5555 (Operator)'],
    
    ['email_title', 'Email Us'],
    ['email_primary', 'educaid@generaltrias.gov.ph'],
    ['email_secondary', 'support@ (coming soon)'],
    
    // Form Section
    ['form_title', 'Send an Inquiry'],
    ['form_subtitle', "Have a question? Fill out the form below and we'll get back to you."],
    
    // Help Section
    ['help_title', 'Before You Contact'],
    ['help_intro', 'Many common questions can be answered quickly through our self-help resources:'],
    
    // Response Time
    ['response_time_title', 'Response Time'],
    ['response_time_text', 'We aim to respond to inquiries within 1-2 business days during office hours (Mon-Fri, 8:00 AM - 5:00 PM).'],
    
    // Offices & Topics
    ['offices_title', 'Program Offices'],
    ['topics_title', 'Common Topics']
];

$inserted = 0;
$skipped = 0;

foreach ($blocks as $block) {
    $key = pg_escape_string($connection, $block[0]);
    $html = pg_escape_string($connection, $block[1]);
    
    // Check if block already exists
    $check = pg_query($connection, "SELECT id FROM contact_content_blocks WHERE municipality_id=1 AND block_key='$key'");
    
    if (pg_num_rows($check) > 0) {
        echo "SKIPPED: $key (already exists)\n";
        $skipped++;
        pg_free_result($check);
        continue;
    }
    pg_free_result($check);
    
    // Insert new block
    $query = "INSERT INTO contact_content_blocks (municipality_id, block_key, html, updated_by) 
              VALUES (1, '$key', '$html', {$_SESSION['admin_id']})";
    
    $result = pg_query($connection, $query);
    
    if ($result) {
        echo "INSERTED: $key\n";
        $inserted++;
    } else {
        echo "ERROR: $key - " . pg_last_error($connection) . "\n";
    }
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "SUMMARY:\n";
echo "Inserted: $inserted blocks\n";
echo "Skipped: $skipped blocks\n";
echo "Total: " . count($blocks) . " blocks\n";
echo "\n";
echo "✓ Done! You can now visit contact.php?edit=1\n";
echo "</pre>";

echo '<p><a href="contact.php?edit=1">→ Go to Contact Page (Edit Mode)</a></p>';
echo '<p><a href="debug_contact_blocks.php">→ View All Blocks</a></p>';
?>
