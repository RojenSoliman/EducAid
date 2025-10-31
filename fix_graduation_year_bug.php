<?php
// Fix graduation year calculation bug
require_once __DIR__ . '/config/database.php';

echo "=== Fixing Graduation Year Calculation ===\n\n";
echo "Bug: Was calculating registration_year + program_duration (ignoring year level)\n";
echo "Fix: Calculate registration_year + (program_duration - year_level + 1)\n\n";

try {
    pg_query($connection, "BEGIN");
    
    // 1. Drop old function and trigger
    echo "1. Dropping old trigger and function...\n";
    pg_query($connection, "DROP TRIGGER IF EXISTS trigger_calculate_graduation_year ON students");
    echo "   ✓ Trigger dropped\n";
    
    // 2. Create new fixed function
    echo "\n2. Creating fixed function...\n";
    $functionSql = <<<SQL
CREATE OR REPLACE FUNCTION calculate_expected_graduation_year()
RETURNS TRIGGER AS \$\$
DECLARE
    program_duration INTEGER;
    registration_year INTEGER;
    current_year_level INTEGER;
    remaining_years INTEGER;
BEGIN
    -- Only calculate if we have course and first_registered_academic_year
    IF NEW.course IS NOT NULL AND NEW.first_registered_academic_year IS NOT NULL THEN
        
        -- Get program duration from courses_mapping
        SELECT cm.program_duration INTO program_duration
        FROM courses_mapping cm
        WHERE cm.normalized_course = NEW.course
        LIMIT 1;
        
        -- If course found in mapping
        IF program_duration IS NOT NULL THEN
            -- Extract year from "2024-2025" format (take first year)
            registration_year := CAST(SPLIT_PART(NEW.first_registered_academic_year, '-', 1) AS INTEGER);
            
            -- Get current year level (default to 1 if not set)
            current_year_level := COALESCE(NEW.year_level_id, 1);
            
            -- Calculate remaining years: program_duration - current_year_level + 1
            -- Example: 4-year course, currently 3rd year = 4 - 3 + 1 = 2 years remaining
            remaining_years := program_duration - current_year_level + 1;
            
            -- Ensure remaining years is never negative (safety check)
            IF remaining_years < 0 THEN
                remaining_years := 0;
            END IF;
            
            -- Calculate graduation year: registration_year + remaining_years
            NEW.expected_graduation_year := registration_year + remaining_years;
        END IF;
    END IF;
    
    RETURN NEW;
END;
\$\$ LANGUAGE plpgsql;
SQL;
    
    $result = pg_query($connection, $functionSql);
    if (!$result) {
        throw new Exception("Failed to create function: " . pg_last_error($connection));
    }
    echo "   ✓ Function created with year level consideration\n";
    
    // 3. Create trigger (now includes year_level_id)
    echo "\n3. Creating trigger...\n";
    $triggerSql = <<<SQL
CREATE TRIGGER trigger_calculate_graduation_year
    BEFORE INSERT OR UPDATE OF course, first_registered_academic_year, year_level_id
    ON students
    FOR EACH ROW
    EXECUTE FUNCTION calculate_expected_graduation_year();
SQL;
    
    $result = pg_query($connection, $triggerSql);
    if (!$result) {
        throw new Exception("Failed to create trigger: " . pg_last_error($connection));
    }
    echo "   ✓ Trigger created (fires on course, academic_year, year_level_id changes)\n";
    
    // 4. Get count of students to update
    $countResult = pg_query($connection, 
        "SELECT COUNT(*) as count FROM students WHERE course IS NOT NULL AND first_registered_academic_year IS NOT NULL"
    );
    $countRow = pg_fetch_assoc($countResult);
    $totalStudents = $countRow['count'];
    
    echo "\n4. Recalculating graduation years for $totalStudents existing students...\n";
    
    // 5. Show before/after samples
    echo "\n5. Sample calculations (BEFORE fix):\n";
    echo "   " . str_repeat("-", 100) . "\n";
    printf("   %-25s %-15s %-10s %-15s %-15s\n", "Student ID", "Course", "Year Lvl", "Academic Year", "Old Grad Year");
    echo "   " . str_repeat("-", 100) . "\n";
    
    $sampleResult = pg_query($connection, 
        "SELECT student_id, course, year_level_id, first_registered_academic_year, expected_graduation_year 
         FROM students 
         WHERE expected_graduation_year IS NOT NULL 
         ORDER BY student_id LIMIT 5"
    );
    
    while ($row = pg_fetch_assoc($sampleResult)) {
        printf("   %-25s %-15s %-10s %-15s %-15s\n",
            $row['student_id'],
            substr($row['course'], 0, 15),
            $row['year_level_id'] ?? 'NULL',
            $row['first_registered_academic_year'],
            $row['expected_graduation_year']
        );
    }
    
    // 6. Update all students to trigger recalculation
    $updateResult = pg_query($connection, 
        "UPDATE students 
         SET year_level_id = COALESCE(year_level_id, 1) 
         WHERE course IS NOT NULL AND first_registered_academic_year IS NOT NULL"
    );
    
    if (!$updateResult) {
        throw new Exception("Failed to update students: " . pg_last_error($connection));
    }
    
    $updatedCount = pg_affected_rows($updateResult);
    echo "\n   ✓ Updated $updatedCount student records\n";
    
    // 7. Show after fix
    echo "\n6. Sample calculations (AFTER fix):\n";
    echo "   " . str_repeat("-", 100) . "\n";
    printf("   %-25s %-15s %-10s %-15s %-15s\n", "Student ID", "Course", "Year Lvl", "Academic Year", "New Grad Year");
    echo "   " . str_repeat("-", 100) . "\n";
    
    $sampleResult = pg_query($connection, 
        "SELECT student_id, course, year_level_id, first_registered_academic_year, expected_graduation_year 
         FROM students 
         WHERE expected_graduation_year IS NOT NULL 
         ORDER BY student_id LIMIT 5"
    );
    
    while ($row = pg_fetch_assoc($sampleResult)) {
        printf("   %-25s %-15s %-10s %-15s %-15s\n",
            $row['student_id'],
            substr($row['course'], 0, 15),
            $row['year_level_id'] ?? 'NULL',
            $row['first_registered_academic_year'],
            $row['expected_graduation_year']
        );
    }
    
    // 8. Show calculation example
    echo "\n7. Calculation Example:\n";
    $exampleResult = pg_query($connection,
        "SELECT 
            student_id,
            first_registered_academic_year,
            CAST(SPLIT_PART(first_registered_academic_year, '-', 1) AS INTEGER) AS reg_year,
            year_level_id,
            cm.program_duration,
            (cm.program_duration - COALESCE(year_level_id, 1) + 1) AS remaining_years,
            expected_graduation_year
         FROM students s
         LEFT JOIN courses_mapping cm ON cm.normalized_course = s.course
         WHERE expected_graduation_year IS NOT NULL
         LIMIT 1"
    );
    
    if ($row = pg_fetch_assoc($exampleResult)) {
        echo "   Student: {$row['student_id']}\n";
        echo "   Academic Year: {$row['first_registered_academic_year']} → Registration Year: {$row['reg_year']}\n";
        echo "   Course Duration: {$row['program_duration']} years\n";
        echo "   Current Year Level: {$row['year_level_id']}\n";
        echo "   Remaining Years: {$row['program_duration']} - {$row['year_level_id']} + 1 = {$row['remaining_years']}\n";
        echo "   Expected Graduation: {$row['reg_year']} + {$row['remaining_years']} = {$row['expected_graduation_year']}\n";
    }
    
    pg_query($connection, "COMMIT");
    
    echo "\n✅ Graduation year calculation fixed successfully!\n";
    echo "\nChanges:\n";
    echo "  ✓ Function now considers current year level\n";
    echo "  ✓ Formula: registration_year + (program_duration - year_level + 1)\n";
    echo "  ✓ Trigger fires on year_level_id changes\n";
    echo "  ✓ All existing student records updated\n";
    
} catch (Exception $e) {
    pg_query($connection, "ROLLBACK");
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
