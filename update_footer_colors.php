<?php
/**
 * Update Footer Colors to Match how-it-works.php Design
 */

require_once 'config/database.php';

echo "=== Updating Footer Colors ===\n\n";

$sql = "
UPDATE footer_settings 
SET 
    footer_bg_color = '#0051f8',
    footer_text_color = '#ffffff',
    footer_heading_color = '#ffffff',
    footer_link_color = '#ffffff',
    footer_link_hover_color = '#fbbf24',
    footer_divider_color = '#ffffff',
    updated_at = NOW()
WHERE is_active = TRUE;
";

$result = pg_query($connection, $sql);

if ($result) {
    echo "✅ SUCCESS! Footer colors updated to match how-it-works.php design!\n\n";
    echo "Color Scheme Applied:\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Background Color:    #0051f8 (Vibrant Blue)\n";
    echo "Text Color:          #ffffff (White)\n";
    echo "Heading Color:       #ffffff (White)\n";
    echo "Link Color:          #ffffff (White)\n";
    echo "Link Hover Color:    #fbbf24 (Gold)\n";
    echo "Divider Color:       #ffffff (White)\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    // Verify the update
    $checkSql = "SELECT * FROM footer_settings WHERE is_active = TRUE LIMIT 1;";
    $checkResult = pg_query($connection, $checkSql);
    
    if ($checkResult && pg_num_rows($checkResult) > 0) {
        $settings = pg_fetch_assoc($checkResult);
        echo "Verified Settings:\n";
        echo "  - Background: " . $settings['footer_bg_color'] . "\n";
        echo "  - Text: " . $settings['footer_text_color'] . "\n";
        echo "  - Updated: " . $settings['updated_at'] . "\n\n";
    }
    
    echo "🎨 Your footer now matches the beautiful blue design from how-it-works.php!\n";
    echo "\nView the results:\n";
    echo "  - Landing Page: http://localhost/EducAid/website/landingpage.php\n";
    echo "  - Admin Panel: http://localhost/EducAid/modules/admin/footer_settings.php\n";
    
} else {
    echo "❌ ERROR: " . pg_last_error($connection) . "\n";
    exit(1);
}

pg_close($connection);
?>
