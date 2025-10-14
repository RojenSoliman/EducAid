<?php
/**
 * Migration script for Document Archival System
 * Run this script to apply the database changes for the new document upload flow
 * 
 * SECURITY NOTE: This migration script should only be run by system administrators.
 * Direct browser access should be restricted in production environments.
 */

// Security check - ensure this is being run by an admin
session_start();
if (!isset($_SESSION['admin_username'])) {
    die("Access denied. Admin login required.");
}

include '../../config/database.php';

echo "<h2>Document Archival System Migration</h2>\n";
echo "<p>This migration adds support for archiving documents during distributions and managing upload requirements.</p>\n";

try {
    // Begin transaction
    pg_query($connection, "BEGIN");
    
    echo "<h3>Step 1: Creating document_archives table...</h3>\n";
    
    $sql1 = "
    CREATE TABLE IF NOT EXISTS document_archives (
        archive_id SERIAL PRIMARY KEY,
        student_id VARCHAR(50) NOT NULL,
        original_document_id INTEGER,
        document_type VARCHAR(50) NOT NULL,
        file_path TEXT NOT NULL,
        original_upload_date TIMESTAMP,
        archived_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        distribution_snapshot_id INTEGER,
        academic_year VARCHAR(20),
        semester VARCHAR(20),
        FOREIGN KEY (distribution_snapshot_id) REFERENCES distribution_snapshots(snapshot_id)
    );
    ";
    
    $result1 = pg_query($connection, $sql1);
    if ($result1) {
        echo "<p style='color: green;'>✓ document_archives table created successfully</p>\n";
    } else {
        throw new Exception("Failed to create document_archives table: " . pg_last_error($connection));
    }
    
    echo "<h3>Step 2: Adding indexes...</h3>\n";
    
    pg_query($connection, "CREATE INDEX IF NOT EXISTS idx_document_archives_student_id ON document_archives(student_id)");
    pg_query($connection, "CREATE INDEX IF NOT EXISTS idx_document_archives_distribution ON document_archives(distribution_snapshot_id)");
    echo "<p style='color: green;'>✓ Indexes created successfully</p>\n";
    
    echo "<h3>Step 3: Adding columns to students table...</h3>\n";
    
    $sql2 = "
    ALTER TABLE students 
    ADD COLUMN IF NOT EXISTS last_distribution_snapshot_id INTEGER,
    ADD COLUMN IF NOT EXISTS needs_document_upload BOOLEAN DEFAULT FALSE;
    ";
    
    $result2 = pg_query($connection, $sql2);
    if ($result2) {
        echo "<p style='color: green;'>✓ Columns added to students table</p>\n";
    } else {
        throw new Exception("Failed to add columns: " . pg_last_error($connection));
    }
    
    echo "<h3>Step 4: Adding foreign key constraint...</h3>\n";
    
    // Check if constraint already exists
    $constraint_check = pg_query($connection, "
        SELECT 1 FROM information_schema.table_constraints 
        WHERE constraint_name = 'fk_students_last_distribution' 
        AND table_name = 'students'
    ");
    
    if (pg_num_rows($constraint_check) == 0) {
        $sql3 = "
        ALTER TABLE students 
        ADD CONSTRAINT fk_students_last_distribution 
        FOREIGN KEY (last_distribution_snapshot_id) 
        REFERENCES distribution_snapshots(snapshot_id);
        ";
        
        $result3 = pg_query($connection, $sql3);
        if ($result3) {
            echo "<p style='color: green;'>✓ Foreign key constraint added</p>\n";
        } else {
            echo "<p style='color: orange;'>⚠ Foreign key constraint may already exist or failed: " . pg_last_error($connection) . "</p>\n";
        }
    } else {
        echo "<p style='color: orange;'>⚠ Foreign key constraint already exists</p>\n";
    }
    
    echo "<h3>Step 5: Updating existing students...</h3>\n";
    
    $sql4 = "
    UPDATE students 
    SET needs_document_upload = TRUE 
    WHERE status IN ('active', 'given', 'applicant') 
    AND application_date < (
        SELECT COALESCE(MAX(finalized_at), '1970-01-01'::timestamp) 
        FROM distribution_snapshots
    );
    ";
    
    $result4 = pg_query($connection, $sql4);
    if ($result4) {
        $affected = pg_affected_rows($result4);
        echo "<p style='color: green;'>✓ Updated $affected existing students to require document upload</p>\n";
    } else {
        throw new Exception("Failed to update existing students: " . pg_last_error($connection));
    }
    
    echo "<h3>Step 6: Creating trigger function...</h3>\n";
    
    $sql5 = "
    CREATE OR REPLACE FUNCTION set_document_upload_needs()
    RETURNS TRIGGER AS \$\$
    BEGIN
        -- New registrations after the last distribution don't need upload tab
        -- (they upload during registration)
        IF NEW.status = 'under_registration' OR NEW.application_date > (
            SELECT COALESCE(MAX(finalized_at), '1970-01-01'::timestamp) 
            FROM distribution_snapshots
        ) THEN
            NEW.needs_document_upload = FALSE;
        ELSE
            NEW.needs_document_upload = TRUE;
        END IF;
        
        RETURN NEW;
    END;
    \$\$ LANGUAGE plpgsql;
    ";
    
    $result5 = pg_query($connection, $sql5);
    if ($result5) {
        echo "<p style='color: green;'>✓ Trigger function created</p>\n";
    } else {
        throw new Exception("Failed to create trigger function: " . pg_last_error($connection));
    }
    
    echo "<h3>Step 7: Creating trigger...</h3>\n";
    
    // Drop trigger if it exists
    pg_query($connection, "DROP TRIGGER IF EXISTS trigger_set_document_upload_needs ON students");
    
    $sql6 = "
    CREATE TRIGGER trigger_set_document_upload_needs
        BEFORE INSERT OR UPDATE ON students
        FOR EACH ROW
        EXECUTE FUNCTION set_document_upload_needs();
    ";
    
    $result6 = pg_query($connection, $sql6);
    if ($result6) {
        echo "<p style='color: green;'>✓ Trigger created</p>\n";
    } else {
        throw new Exception("Failed to create trigger: " . pg_last_error($connection));
    }
    
    echo "<h3>Step 8: Creating archive function...</h3>\n";
    
    $sql7 = "
    CREATE OR REPLACE FUNCTION archive_student_documents(
        p_student_id VARCHAR(50),
        p_distribution_snapshot_id INTEGER,
        p_academic_year VARCHAR(20),
        p_semester VARCHAR(20)
    ) RETURNS VOID AS \$\$
    BEGIN
        -- Archive documents table entries
        INSERT INTO document_archives (
            student_id, original_document_id, document_type, file_path, 
            original_upload_date, distribution_snapshot_id, academic_year, semester
        )
        SELECT 
            d.student_id, d.document_id, d.type, d.file_path,
            d.upload_date, p_distribution_snapshot_id, p_academic_year, p_semester
        FROM documents d
        WHERE d.student_id = p_student_id;
        
        -- Archive grade uploads
        INSERT INTO document_archives (
            student_id, original_document_id, document_type, file_path,
            original_upload_date, distribution_snapshot_id, academic_year, semester
        )
        SELECT 
            g.student_id, g.upload_id, 'grades', g.file_path,
            g.upload_date, p_distribution_snapshot_id, p_academic_year, p_semester
        FROM grade_uploads g
        WHERE g.student_id = p_student_id;
    END;
    \$\$ LANGUAGE plpgsql;
    ";
    
    $result7 = pg_query($connection, $sql7);
    if ($result7) {
        echo "<p style='color: green;'>✓ Archive function created</p>\n";
    } else {
        throw new Exception("Failed to create archive function: " . pg_last_error($connection));
    }
    
    echo "<h3>Step 9: Adding comments...</h3>\n";
    
    pg_query($connection, "COMMENT ON TABLE document_archives IS 'Stores archived documents from previous distribution cycles'");
    pg_query($connection, "COMMENT ON COLUMN students.needs_document_upload IS 'TRUE if student needs to use Upload Documents tab (existing students), FALSE if documents come from registration (new students)'");
    pg_query($connection, "COMMENT ON COLUMN students.last_distribution_snapshot_id IS 'References the last distribution this student participated in'");
    
    echo "<p style='color: green;'>✓ Comments added</p>\n";
    
    // Commit transaction
    pg_query($connection, "COMMIT");
    
    echo "<h2 style='color: green;'>Migration completed successfully!</h2>\n";
    echo "<h3>Summary of changes:</h3>\n";
    echo "<ul>\n";
    echo "<li>✓ Created document_archives table for storing old documents</li>\n";
    echo "<li>✓ Added needs_document_upload and last_distribution_snapshot_id columns to students table</li>\n";
    echo "<li>✓ Created triggers to automatically manage document upload requirements</li>\n";
    echo "<li>✓ Created functions for archiving documents during distribution finalization</li>\n";
    echo "<li>✓ Updated existing students based on their registration date vs last distribution</li>\n";
    echo "</ul>\n";
    
    echo "<h3>What this enables:</h3>\n";
    echo "<ul>\n";
    echo "<li><strong>New registrants:</strong> Will NOT see Upload Documents tab (docs come from registration)</li>\n";
    echo "<li><strong>Existing students:</strong> Will see Upload Documents tab and must upload fresh documents</li>\n";
    echo "<li><strong>Document archival:</strong> Old documents are preserved when distributions are finalized</li>\n";
    echo "<li><strong>Admin review:</strong> Will check appropriate document source based on student type</li>\n";
    echo "</ul>\n";
    
} catch (Exception $e) {
    // Rollback on error
    pg_query($connection, "ROLLBACK");
    echo "<h2 style='color: red;'>Migration failed!</h2>\n";
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>\n";
    echo "<p>The database has been rolled back to its previous state.</p>\n";
}

pg_close($connection);
?>