<?php
/**
 * Setup Script for OCR-Driven Per-Subject Grade Validation
 * Initializes database schema and grading policies
 */

require_once __DIR__ . '/../config/database.php';

function setupGradingSystem() {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        echo "Setting up OCR-driven per-subject grade validation system...\n\n";
        
        // Read and execute the grading policy schema
        $schemaFile = __DIR__ . '/../sql/grading_policy_schema.sql';
        if (!file_exists($schemaFile)) {
            throw new Exception("Schema file not found: $schemaFile");
        }
        
        echo "1. Creating grading schema and tables...\n";
        $schemaSQL = file_get_contents($schemaFile);
        
        // Split SQL by semicolons and execute each statement
        $statements = array_filter(array_map('trim', explode(';', $schemaSQL)));
        
        foreach ($statements as $statement) {
            if (!empty($statement) && !preg_match('/^\s*--/', $statement)) {
                try {
                    $db->exec($statement);
                } catch (PDOException $e) {
                    // Ignore "already exists" errors
                    if (strpos($e->getMessage(), 'already exists') === false) {
                        throw $e;
                    }
                }
            }
        }
        
        echo "   ✓ Schema created successfully\n\n";
        
        // Verify setup
        echo "2. Verifying installation...\n";
        
        // Check grading policy table
        $stmt = $db->query("SELECT COUNT(*) as count FROM grading.university_passing_policy WHERE is_active = TRUE");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "   ✓ Active grading policies: " . $result['count'] . "\n";
        
        // Check grading function
        $stmt = $db->prepare("SELECT grading.grading_is_passing('BSU_MAIN', '2.50') as is_passing");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "   ✓ Grading function test: BSU_MAIN grade 2.50 -> " . 
             ($result['is_passing'] ? 'PASS' : 'FAIL') . "\n";
        
        // Test different university policies
        echo "\n3. Testing university grading policies...\n";
        
        $testCases = [
            ['BSU_MAIN', '2.50', true, '1-5 scale (lower better)'],
            ['BSU_MAIN', '3.25', false, '1-5 scale (lower better)'],
            ['DLSU_DASMARINAS', '2.50', true, '0-4 scale (higher better)'],
            ['DLSU_DASMARINAS', '0.75', false, '0-4 scale (higher better)'],
        ];
        
        foreach ($testCases as $case) {
            [$university, $grade, $expected, $description] = $case;
            
            $stmt = $db->prepare("SELECT grading.grading_is_passing(?, ?) as is_passing");
            $stmt->execute([$university, $grade]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $actual = $result['is_passing'];
            $status = ($actual === $expected) ? "✓" : "✗";
            
            echo sprintf("   %s %s grade %s -> %s (%s)\n", 
                $status, $university, $grade, 
                $actual ? 'PASS' : 'FAIL', 
                $description
            );
        }
        
        // Create temp directory for OCR processing
        echo "\n4. Creating directories...\n";
        $tempDir = __DIR__ . '/../temp';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
            echo "   ✓ Created temp directory: $tempDir\n";
        } else {
            echo "   ✓ Temp directory exists: $tempDir\n";
        }
        
        // Check for required extensions
        echo "\n5. Checking PHP extensions...\n";
        
        $requiredExtensions = ['pdo', 'pdo_pgsql', 'json'];
        $optionalExtensions = ['imagick', 'gd'];
        
        foreach ($requiredExtensions as $ext) {
            if (extension_loaded($ext)) {
                echo "   ✓ Required: $ext\n";
            } else {
                echo "   ✗ Missing required extension: $ext\n";
            }
        }
        
        foreach ($optionalExtensions as $ext) {
            if (extension_loaded($ext)) {
                echo "   ✓ Optional: $ext (for image preprocessing)\n";
            } else {
                echo "   - Optional: $ext not installed (image preprocessing limited)\n";
            }
        }
        
        // Check for Tesseract
        echo "\n6. Checking Tesseract OCR...\n";
        
        $tesseractCheck = shell_exec('tesseract --version 2>&1');
        if ($tesseractCheck && strpos($tesseractCheck, 'tesseract') !== false) {
            echo "   ✓ Tesseract OCR found\n";
            $version = trim(explode("\n", $tesseractCheck)[0]);
            echo "   ✓ Version: $version\n";
        } else {
            echo "   ✗ Tesseract OCR not found or not in PATH\n";
            echo "   → Install from: https://github.com/UB-Mannheim/tesseract/wiki\n";
        }
        
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "SETUP COMPLETED SUCCESSFULLY!\n";
        echo str_repeat("=", 60) . "\n";
        
        echo "\nNext steps:\n";
        echo "1. Test the system: php scripts/test_grade_validation.php\n";
        echo "2. Test OCR processing: scripts/test_ocr.bat <image_file>\n";
        echo "3. Use API endpoint: POST /api/eligibility/subject-check\n";
        echo "4. Enhanced validation is integrated into student registration\n\n";
        
        echo "University grading policies configured:\n";
        echo "• State Universities (1-5 scale): Lower grades are better (1.00 = highest, 3.00 = passing)\n";
        echo "• Private Universities (0-4 scale): Higher grades are better (4.00 = highest, 1.00 = passing)\n";
        echo "• Enhanced per-subject validation replaces legacy 3.00 threshold check\n\n";
        
    } catch (Exception $e) {
        echo "✗ Setup failed: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        exit(1);
    }
}

// Run setup
if (php_sapi_name() === 'cli') {
    setupGradingSystem();
} else {
    header('Content-Type: text/plain');
    setupGradingSystem();
}
?>