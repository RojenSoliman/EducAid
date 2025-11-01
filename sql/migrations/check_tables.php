<?php
/**
 * Check if Phase 1 tables were created successfully
 */

require_once __DIR__ . '/../../config/database.php';

echo "=======================================================\n";
echo "Checking Phase 1 Table Creation\n";
echo "=======================================================\n\n";

// Check academic_years table
echo "1. Checking 'academic_years' table...\n";
$check1 = pg_query($connection, "
    SELECT EXISTS (
        SELECT FROM information_schema.tables 
        WHERE table_schema = 'public' 
        AND table_name = 'academic_years'
    ) as exists
");

if ($check1) {
    $row = pg_fetch_assoc($check1);
    if ($row['exists'] === 't') {
        echo "   ✓ Table 'academic_years' EXISTS\n";
        
        // Count records
        $count = pg_query($connection, "SELECT COUNT(*) as count FROM academic_years");
        if ($count) {
            $countRow = pg_fetch_assoc($count);
            echo "   ✓ Records: {$countRow['count']}\n";
            
            // Show sample data
            $sample = pg_query($connection, "SELECT year_code, status, is_current FROM academic_years ORDER BY year_code");
            echo "   Sample data:\n";
            while ($sampleRow = pg_fetch_assoc($sample)) {
                $current = $sampleRow['is_current'] === 't' ? ' (CURRENT)' : '';
                echo "   - {$sampleRow['year_code']}: {$sampleRow['status']}{$current}\n";
            }
        }
    } else {
        echo "   ✗ Table 'academic_years' DOES NOT EXIST\n";
    }
}
echo "\n";

// Check courses_mapping table
echo "2. Checking 'courses_mapping' table...\n";
$check2 = pg_query($connection, "
    SELECT EXISTS (
        SELECT FROM information_schema.tables 
        WHERE table_schema = 'public' 
        AND table_name = 'courses_mapping'
    ) as exists
");

if ($check2) {
    $row = pg_fetch_assoc($check2);
    if ($row['exists'] === 't') {
        echo "   ✓ Table 'courses_mapping' EXISTS\n";
        
        // Count records
        $count = pg_query($connection, "SELECT COUNT(*) as count FROM courses_mapping");
        if ($count) {
            $countRow = pg_fetch_assoc($count);
            echo "   ✓ Records: {$countRow['count']}\n";
            
            // Show sample data by category
            $sample = pg_query($connection, "
                SELECT course_category, COUNT(*) as count 
                FROM courses_mapping 
                GROUP BY course_category 
                ORDER BY count DESC
            ");
            echo "   Courses by category:\n";
            while ($sampleRow = pg_fetch_assoc($sample)) {
                echo "   - {$sampleRow['course_category']}: {$sampleRow['count']} courses\n";
            }
            
            // Show some example courses
            echo "\n   Example courses:\n";
            $examples = pg_query($connection, "
                SELECT normalized_course, program_duration, course_category 
                FROM courses_mapping 
                LIMIT 5
            ");
            while ($exampleRow = pg_fetch_assoc($examples)) {
                echo "   - {$exampleRow['normalized_course']} ({$exampleRow['program_duration']} years, {$exampleRow['course_category']})\n";
            }
        }
    } else {
        echo "   ✗ Table 'courses_mapping' DOES NOT EXIST\n";
    }
}
echo "\n";

// Check pg_trgm extension
echo "3. Checking 'pg_trgm' extension...\n";
$checkExt = pg_query($connection, "
    SELECT EXISTS (
        SELECT FROM pg_extension 
        WHERE extname = 'pg_trgm'
    ) as exists
");

if ($checkExt) {
    $row = pg_fetch_assoc($checkExt);
    if ($row['exists'] === 't') {
        echo "   ✓ Extension 'pg_trgm' is INSTALLED\n";
    } else {
        echo "   ✗ Extension 'pg_trgm' is NOT INSTALLED\n";
    }
}

echo "\n=======================================================\n";
echo "Check Complete\n";
echo "=======================================================\n";

pg_close($connection);
?>
