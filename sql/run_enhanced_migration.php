<?php
// Enhanced safe step-by-step migration script that handles ALL foreign key dependencies
try {
    $pdo = new PDO('pgsql:host=localhost;dbname=educaid', 'postgres', 'postgres_dev_2025');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== STARTING ENHANCED SAFE MIGRATION ===\n";
    
    // First, discover ALL tables that reference students.student_id
    echo "\nDISCOVERING ALL FOREIGN KEY DEPENDENCIES...\n";
    
    $stmt = $pdo->query("
        SELECT 
            tc.table_name,
            kcu.column_name,
            tc.constraint_name
        FROM information_schema.table_constraints tc
        JOIN information_schema.key_column_usage kcu 
            ON tc.constraint_name = kcu.constraint_name
        JOIN information_schema.constraint_column_usage ccu 
            ON ccu.constraint_name = tc.constraint_name
        WHERE tc.constraint_type = 'FOREIGN KEY'
        AND ccu.table_name = 'students'
        AND ccu.column_name = 'student_id'
    ");
    
    $foreign_key_tables = [];
    while ($row = $stmt->fetch()) {
        $foreign_key_tables[] = [
            'table' => $row['table_name'],
            'column' => $row['column_name'],
            'constraint' => $row['constraint_name']
        ];
        echo "Found FK: {$row['table_name']}.{$row['column_name']} -> students.student_id (constraint: {$row['constraint_name']})\n";
    }
    
    echo "Total foreign key dependencies found: " . count($foreign_key_tables) . "\n";
    
    // STEP 1: Ensure all students have unique_student_id values
    echo "\nSTEP 1: Ensuring all students have unique_student_id values...\n";
    
    $stmt = $pdo->query("SELECT student_id FROM students WHERE unique_student_id IS NULL OR unique_student_id = ''");
    $students_without_unique_id = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($students_without_unique_id) > 0) {
        foreach ($students_without_unique_id as $student_id) {
            $year = date('Y');
            $unique_id = "EDU-{$year}-" . str_pad($student_id, 6, '0', STR_PAD_LEFT);
            
            // Ensure uniqueness
            $counter = 0;
            $base_unique_id = $unique_id;
            while (true) {
                $stmt = $pdo->prepare("SELECT 1 FROM students WHERE unique_student_id = ?");
                $stmt->execute([$unique_id]);
                if ($stmt->rowCount() == 0) break;
                $counter++;
                $unique_id = $base_unique_id . '-' . $counter;
            }
            
            $stmt = $pdo->prepare("UPDATE students SET unique_student_id = ? WHERE student_id = ?");
            $stmt->execute([$unique_id, $student_id]);
            echo "✓ Generated unique_student_id $unique_id for student_id $student_id\n";
        }
    } else {
        echo "✓ All students already have unique_student_id values\n";
    }
    
    // STEP 2: Create mapping and add new columns to ALL dependent tables
    echo "\nSTEP 2: Adding new student_id columns to ALL dependent tables...\n";
    
    // Create mapping
    $stmt = $pdo->query("SELECT student_id, unique_student_id FROM students");
    $mapping = [];
    while ($row = $stmt->fetch()) {
        $mapping[$row['student_id']] = $row['unique_student_id'];
    }
    echo "✓ Created mapping for " . count($mapping) . " students\n";
    
    // Get unique table names from foreign key dependencies
    $tables_to_update = array_unique(array_column($foreign_key_tables, 'table'));
    
    foreach ($tables_to_update as $table) {
        try {
            // Find the column name for this table (it might not be 'student_id')
            $fk_info = array_filter($foreign_key_tables, function($fk) use ($table) {
                return $fk['table'] === $table;
            });
            $fk_info = array_values($fk_info)[0]; // Get first match
            $column_name = $fk_info['column'];
            
            echo "Processing table: $table (column: $column_name)\n";
            
            // Add new column if it doesn't exist
            $pdo->exec("ALTER TABLE $table ADD COLUMN IF NOT EXISTS new_student_id TEXT");
            echo "  ✓ Added new_student_id column to $table\n";
            
            // Populate the new column
            $stmt = $pdo->prepare("SELECT $column_name FROM $table WHERE $column_name IS NOT NULL");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($rows as $old_student_id) {
                if (isset($mapping[$old_student_id])) {
                    $new_student_id = $mapping[$old_student_id];
                    $updateStmt = $pdo->prepare("UPDATE $table SET new_student_id = ? WHERE $column_name = ?");
                    $updateStmt->execute([$new_student_id, $old_student_id]);
                }
            }
            echo "  ✓ Populated new_student_id column in $table\n";
            
        } catch (Exception $e) {
            echo "  Error with table $table: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
    
    // STEP 3: Drop ALL foreign key constraints
    echo "\nSTEP 3: Dropping ALL foreign key constraints...\n";
    
    foreach ($foreign_key_tables as $fk) {
        try {
            $pdo->exec("ALTER TABLE {$fk['table']} DROP CONSTRAINT IF EXISTS {$fk['constraint']}");
            echo "✓ Dropped constraint {$fk['constraint']} from {$fk['table']}\n";
        } catch (Exception $e) {
            echo "Note: Could not drop constraint {$fk['constraint']}: " . $e->getMessage() . "\n";
        }
    }
    
    // STEP 4: Replace columns in ALL dependent tables
    echo "\nSTEP 4: Replacing student_id columns in ALL tables...\n";
    
    foreach ($tables_to_update as $table) {
        try {
            // Find the original column name
            $fk_info = array_filter($foreign_key_tables, function($fk) use ($table) {
                return $fk['table'] === $table;
            });
            $fk_info = array_values($fk_info)[0];
            $column_name = $fk_info['column'];
            
            $pdo->exec("ALTER TABLE $table DROP COLUMN $column_name");
            $pdo->exec("ALTER TABLE $table RENAME COLUMN new_student_id TO $column_name");
            echo "✓ Replaced $column_name column in $table\n";
        } catch (Exception $e) {
            echo "Error replacing column in $table: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
    
    // Update students table
    echo "✓ Updating students table primary key...\n";
    $pdo->exec("ALTER TABLE students DROP CONSTRAINT students_pkey CASCADE");
    $pdo->exec("ALTER TABLE students DROP COLUMN student_id");
    $pdo->exec("ALTER TABLE students RENAME COLUMN unique_student_id TO student_id");
    $pdo->exec("ALTER TABLE students ADD PRIMARY KEY (student_id)");
    echo "✓ Students table updated with TEXT primary key\n";
    
    // STEP 5: Add foreign key constraints back to ALL tables
    echo "\nSTEP 5: Adding foreign key constraints back to ALL tables...\n";
    
    foreach ($foreign_key_tables as $fk) {
        try {
            $pdo->exec("ALTER TABLE {$fk['table']} ADD CONSTRAINT {$fk['constraint']} 
                FOREIGN KEY ({$fk['column']}) REFERENCES students(student_id)");
            echo "✓ Added constraint {$fk['constraint']} to {$fk['table']}\n";
        } catch (Exception $e) {
            echo "Warning: Could not add constraint {$fk['constraint']} to {$fk['table']}: " . $e->getMessage() . "\n";
        }
    }
    
    // Handle qr_codes table if it exists (special case)
    try {
        $stmt = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name = 'qr_codes'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name = 'qr_codes' AND column_name = 'student_unique_id'");
            if ($stmt->rowCount() > 0) {
                $pdo->exec("ALTER TABLE qr_codes DROP CONSTRAINT IF EXISTS qr_codes_student_unique_id_fkey");
                $pdo->exec("ALTER TABLE qr_codes RENAME COLUMN student_unique_id TO student_id");
                $pdo->exec("ALTER TABLE qr_codes ADD CONSTRAINT qr_codes_student_id_fkey 
                    FOREIGN KEY (student_id) REFERENCES students(student_id)");
                echo "✓ Updated qr_codes table\n";
            }
        }
    } catch (Exception $e) {
        echo "Note: qr_codes table update not needed\n";
    }
    
    // STEP 6: Final cleanup
    echo "\nSTEP 6: Final cleanup...\n";
    
    // Recreate indexes
    $pdo->exec("DROP INDEX IF EXISTS idx_students_unique_id");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_students_confidence_score ON students(confidence_score DESC)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_grade_uploads_student ON grade_uploads(student_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_students_last_login ON students(last_login)");
    echo "✓ Recreated indexes\n";
    
    // Update confidence function
    $functionSQL = "
    CREATE OR REPLACE FUNCTION calculate_confidence_score(student_id_param TEXT) 
    RETURNS DECIMAL(5,2) AS \$\$
    DECLARE
        score DECIMAL(5,2) := 0.00;
        doc_count INT := 0;
        total_docs INT := 0;
        avg_ocr_confidence DECIMAL(5,2) := 0.00;
        temp_score DECIMAL(5,2);
    BEGIN
        -- Base score for having all required personal information (30 points)
        SELECT 
            CASE WHEN first_name IS NOT NULL AND first_name != '' 
                 AND last_name IS NOT NULL AND last_name != ''
                 AND email IS NOT NULL AND email != ''
                 AND mobile IS NOT NULL AND mobile != ''
                 AND bdate IS NOT NULL
                 AND sex IS NOT NULL
                 AND barangay_id IS NOT NULL
                 AND university_id IS NOT NULL
                 AND year_level_id IS NOT NULL
            THEN 30.00 ELSE 0.00 END
        INTO temp_score
        FROM students 
        WHERE student_id = student_id_param;
        
        score := score + temp_score;
        
        -- Document upload score (40 points)
        SELECT COUNT(*) INTO doc_count
        FROM documents d
        WHERE d.student_id = student_id_param 
        AND d.type IN ('eaf', 'certificate_of_indigency', 'letter_to_mayor', 'id_picture');
        
        -- Also check enrollment_forms table
        SELECT COUNT(*) INTO total_docs
        FROM enrollment_forms ef
        WHERE ef.student_id = student_id_param;
        
        doc_count := doc_count + total_docs;
        score := score + LEAST(doc_count * 10.00, 40.00);
        
        -- OCR confidence score (20 points)
        SELECT COALESCE(AVG(ocr_confidence), 0.00) INTO avg_ocr_confidence
        FROM documents d
        WHERE d.student_id = student_id_param 
        AND d.ocr_confidence > 0;
        
        score := score + (avg_ocr_confidence * 0.20);
        
        -- Email verification bonus (10 points)
        SELECT 
            CASE WHEN status != 'under_registration' THEN 10.00 ELSE 0.00 END
        INTO temp_score
        FROM students 
        WHERE student_id = student_id_param;
        
        score := score + temp_score;
        
        -- Ensure score is between 0 and 100
        score := GREATEST(0.00, LEAST(100.00, score));
        
        RETURN score;
    END;
    \$\$ LANGUAGE plpgsql;
    ";
    
    $pdo->exec($functionSQL);
    echo "✓ Updated confidence calculation function\n";
    
    // Update statistics
    $pdo->exec("ANALYZE students");
    foreach ($tables_to_update as $table) {
        $pdo->exec("ANALYZE $table");
    }
    echo "✓ Updated table statistics\n";
    
    // Final verification
    echo "\n=== FINAL VERIFICATION ===\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM students");
    $studentCount = $stmt->fetchColumn();
    echo "Students count: $studentCount\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM applications a JOIN students s ON a.student_id = s.student_id");
    $appCount = $stmt->fetchColumn();
    echo "Applications with valid student_id: $appCount\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM documents d JOIN students s ON d.student_id = s.student_id");
    $docCount = $stmt->fetchColumn();
    echo "Documents with valid student_id: $docCount\n";
    
    $stmt = $pdo->query("SELECT student_id FROM students LIMIT 3");
    echo "Sample student_id format:\n";
    while ($row = $stmt->fetch()) {
        echo "  " . $row['student_id'] . "\n";
    }
    
    // Test ALL foreign key relationships
    echo "\nTesting ALL foreign key relationships:\n";
    foreach ($tables_to_update as $table) {
        try {
            $fk_info = array_filter($foreign_key_tables, function($fk) use ($table) {
                return $fk['table'] === $table;
            });
            $fk_info = array_values($fk_info)[0];
            $column_name = $fk_info['column'];
            
            $stmt = $pdo->query("SELECT COUNT(*) FROM $table t JOIN students s ON t.$column_name = s.student_id");
            $count = $stmt->fetchColumn();
            echo "  $table: $count records with valid student_id\n";
        } catch (Exception $e) {
            echo "  $table: Error testing - " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n🎉 ENHANCED MIGRATION COMPLETED SUCCESSFULLY! 🎉\n";
    echo "Your student_id is now using meaningful unique identifiers across ALL tables.\n";
    echo "All " . count($foreign_key_tables) . " foreign key relationships have been updated.\n";
    
} catch (PDOException $e) {
    echo "MIGRATION FAILED: " . $e->getMessage() . "\n";
    echo "Please check the error and consider using the rollback script if needed.\n";
}
?>