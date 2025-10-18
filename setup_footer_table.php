<?php
/**
 * Setup Footer Settings Table
 * Quick script to create the footer_settings table
 */

require_once 'config/database.php';

echo "=== Footer Settings Table Setup ===\n\n";

// Read the SQL file
$sqlFile = __DIR__ . '/sql/create_footer_settings.sql';

if (!file_exists($sqlFile)) {
    die("Error: SQL file not found at: $sqlFile\n");
}

$sql = file_get_contents($sqlFile);

if ($sql === false) {
    die("Error: Could not read SQL file\n");
}

echo "Executing SQL migration...\n\n";

try {
    // Execute the SQL
    $result = pg_query($connection, $sql);
    
    if ($result) {
        echo "✅ SUCCESS! Footer settings table created successfully!\n\n";
        
        // Insert default settings
        echo "Inserting default footer settings...\n";
        
        $insertSql = "
            INSERT INTO footer_settings (
                municipality_id,
                footer_bg_color,
                footer_text_color,
                footer_heading_color,
                footer_link_color,
                footer_link_hover_color,
                footer_divider_color,
                footer_title,
                footer_description,
                contact_address,
                contact_phone,
                contact_email,
                is_active
            ) VALUES (
                NULL,
                '#1e3a8a',
                '#cbd5e1',
                '#ffffff',
                '#e2e8f0',
                '#fbbf24',
                '#fbbf24',
                'EducAid',
                'Empowering students through accessible scholarship opportunities.',
                '123 Education Street, Academic City',
                '+1 (555) 123-4567',
                'info@educaid.com',
                TRUE
            )
            ON CONFLICT DO NOTHING;
        ";
        
        $insertResult = pg_query($connection, $insertSql);
        
        if ($insertResult) {
            echo "✅ Default settings inserted!\n\n";
        } else {
            echo "⚠️  Warning: Could not insert defaults (table might already have data)\n";
            echo "Error: " . pg_last_error($connection) . "\n\n";
        }
        
        // Verify the table
        echo "Verifying table structure...\n";
        $checkSql = "
            SELECT column_name, data_type, character_maximum_length 
            FROM information_schema.columns 
            WHERE table_name = 'footer_settings' 
            ORDER BY ordinal_position;
        ";
        
        $checkResult = pg_query($connection, $checkSql);
        
        if ($checkResult) {
            echo "\n📋 Table Columns:\n";
            echo str_repeat("-", 60) . "\n";
            printf("%-30s %-20s %s\n", "Column Name", "Data Type", "Max Length");
            echo str_repeat("-", 60) . "\n";
            
            while ($row = pg_fetch_assoc($checkResult)) {
                $maxLen = $row['character_maximum_length'] ?? 'N/A';
                printf("%-30s %-20s %s\n", 
                    $row['column_name'], 
                    $row['data_type'], 
                    $maxLen
                );
            }
            echo str_repeat("-", 60) . "\n\n";
        }
        
        // Check current settings
        echo "Current footer settings:\n";
        $selectSql = "SELECT * FROM footer_settings WHERE is_active = TRUE LIMIT 1;";
        $selectResult = pg_query($connection, $selectSql);
        
        if ($selectResult && pg_num_rows($selectResult) > 0) {
            $settings = pg_fetch_assoc($selectResult);
            echo "\n✅ Active footer settings found:\n";
            echo "   - Footer ID: " . $settings['footer_id'] . "\n";
            echo "   - Title: " . $settings['footer_title'] . "\n";
            echo "   - BG Color: " . $settings['footer_bg_color'] . "\n";
            echo "   - Text Color: " . $settings['footer_text_color'] . "\n";
            echo "   - Created: " . $settings['created_at'] . "\n\n";
        } else {
            echo "\n⚠️  No active footer settings found. You can add them via the admin panel.\n\n";
        }
        
        echo "=== Setup Complete! ===\n\n";
        echo "Next steps:\n";
        echo "1. Update your landing page footer (see FOOTER_CMS_SETUP.md)\n";
        echo "2. Visit admin panel: System Controls → Footer Settings\n";
        echo "3. Customize your footer colors and content!\n\n";
        
    } else {
        echo "❌ ERROR executing SQL:\n";
        echo pg_last_error($connection) . "\n\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . "\n";
    exit(1);
}

pg_close($connection);
?>
