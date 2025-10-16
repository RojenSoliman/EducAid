<?php
/**
 * Automatic Student Archiving Script
 * 
 * This script automatically archives students who meet criteria:
 * - Graduated (past expected graduation year)
 * - Inactive accounts (no login for 2+ years)
 * 
 * Usage:
 * - Run manually: php run_automatic_archiving.php
 * - Schedule yearly: Windows Task Scheduler or Cron
 * 
 * Recommended Schedule: Run annually after graduation season (July-August)
 */

// Check if running from command line
$isCLI = php_sapi_name() === 'cli';

if (!$isCLI) {
    // If running from web, require admin authentication
    session_start();
    if (!isset($_SESSION['admin_username']) || $_SESSION['admin_role'] !== 'super_admin') {
        http_response_code(403);
        die("Error: This script can only be run by super admins or via command line.");
    }
    
    // Set content type for web output
    header('Content-Type: text/plain; charset=utf-8');
}

// Output helper function
function output($message) {
    global $isCLI;
    echo $message . ($isCLI ? "\n" : "<br>\n");
    flush();
}

// Initialize database connection
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/services/AuditLogger.php';

if (!$connection) {
    output("ERROR: Database connection failed!");
    exit(1);
}

output("========================================");
output("  AUTOMATIC STUDENT ARCHIVING SCRIPT");
output("========================================");
output("");
output("Started at: " . date('Y-m-d H:i:s'));
output("");

// Initialize Audit Logger
$auditLogger = new AuditLogger($connection);

try {
    // Check if archiving system is set up
    $checkQuery = "
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'students' AND column_name = 'is_archived'
    ";
    $checkResult = pg_query($connection, $checkQuery);
    
    if (!$checkResult || pg_num_rows($checkResult) === 0) {
        output("ERROR: Archiving system not installed!");
        output("Please run sql/create_student_archiving_system.sql first.");
        exit(1);
    }
    
    output("✓ Archiving system detected");
    output("");
    
    // Get statistics before archiving
    output("Checking eligibility...");
    output("");
    
    $statsQuery = "
        SELECT 
            COUNT(*) as eligible_count,
            COUNT(CASE WHEN EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER > expected_graduation_year THEN 1 END) as graduated_count,
            COUNT(CASE WHEN last_login IS NOT NULL AND last_login < (CURRENT_DATE - INTERVAL '2 years') THEN 1 END) as inactive_login_count,
            COUNT(CASE WHEN last_login IS NULL AND application_date < (CURRENT_DATE - INTERVAL '2 years') THEN 1 END) as never_login_count
        FROM students
        WHERE is_archived = FALSE
          AND status NOT IN ('blacklisted')
          AND (
              EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER > expected_graduation_year
              OR (EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER = expected_graduation_year AND EXTRACT(MONTH FROM CURRENT_DATE) >= 6)
              OR (last_login IS NOT NULL AND last_login < (CURRENT_DATE - INTERVAL '2 years'))
              OR (last_login IS NULL AND application_date < (CURRENT_DATE - INTERVAL '2 years'))
          )
    ";
    
    $statsResult = pg_query($connection, $statsQuery);
    $stats = pg_fetch_assoc($statsResult);
    
    output("ELIGIBILITY SUMMARY:");
    output("  • Total eligible for archiving: " . $stats['eligible_count']);
    output("  • Graduated students: " . $stats['graduated_count']);
    output("  • Inactive (no login 2+ years): " . $stats['inactive_login_count']);
    output("  • Never logged in (registered 2+ years ago): " . $stats['never_login_count']);
    output("");
    
    if ($stats['eligible_count'] == 0) {
        output("No students eligible for automatic archiving.");
        output("Script completed successfully.");
        exit(0);
    }
    
    // Show detailed list of eligible students
    output("ELIGIBLE STUDENTS:");
    output("");
    
    $detailsQuery = "
        SELECT 
            s.student_id,
            s.first_name,
            s.last_name,
            s.email,
            s.expected_graduation_year,
            s.last_login,
            s.application_date,
            yl.name as year_level,
            CASE 
                WHEN EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER > s.expected_graduation_year THEN 'Graduated (past expected year)'
                WHEN EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER = s.expected_graduation_year AND EXTRACT(MONTH FROM CURRENT_DATE) >= 6 THEN 'Graduated (current year)'
                WHEN s.last_login IS NOT NULL AND s.last_login < (CURRENT_DATE - INTERVAL '2 years') THEN 'Inactive (no login 2+ years)'
                WHEN s.last_login IS NULL AND s.application_date < (CURRENT_DATE - INTERVAL '2 years') THEN 'Inactive (never logged in)'
                ELSE 'Other'
            END as reason
        FROM students s
        LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
        WHERE s.is_archived = FALSE
          AND s.status NOT IN ('blacklisted')
          AND (
              EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER > s.expected_graduation_year
              OR (EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER = s.expected_graduation_year AND EXTRACT(MONTH FROM CURRENT_DATE) >= 6)
              OR (s.last_login IS NOT NULL AND s.last_login < (CURRENT_DATE - INTERVAL '2 years'))
              OR (s.last_login IS NULL AND s.application_date < (CURRENT_DATE - INTERVAL '2 years'))
          )
        ORDER BY s.expected_graduation_year, s.last_name
    ";
    
    $detailsResult = pg_query($connection, $detailsQuery);
    $counter = 1;
    while ($student = pg_fetch_assoc($detailsResult)) {
        $name = $student['first_name'] . ' ' . $student['last_name'];
        $gradYear = $student['expected_graduation_year'] ?? 'N/A';
        output(sprintf(
            "  %d. %s (%s) - %s | Grad: %s | Last Login: %s",
            $counter++,
            $name,
            $student['student_id'],
            $student['reason'],
            $gradYear,
            $student['last_login'] ? date('Y-m-d', strtotime($student['last_login'])) : 'Never'
        ));
    }
    
    output("");
    output("========================================");
    
    // If running from CLI, ask for confirmation
    if ($isCLI) {
        output("Do you want to proceed with archiving these students? (yes/no): ");
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        $confirmation = trim(strtolower($line));
        fclose($handle);
        
        if ($confirmation !== 'yes' && $confirmation !== 'y') {
            output("");
            output("Archiving cancelled by user.");
            exit(0);
        }
    }
    
    output("");
    output("Proceeding with automatic archiving...");
    output("");
    
    // Execute archiving function
    $archiveResult = pg_query($connection, "SELECT * FROM archive_graduated_students()");
    
    if (!$archiveResult) {
        output("ERROR: Archiving function failed!");
        output("Error: " . pg_last_error($connection));
        exit(1);
    }
    
    $result = pg_fetch_assoc($archiveResult);
    $archivedCount = $result['archived_count'];
    $studentIds = $result['student_ids'];
    
    // Remove PostgreSQL array braces and split
    if ($studentIds) {
        $studentIds = trim($studentIds, '{}');
        $studentIdsArray = $studentIds ? explode(',', $studentIds) : [];
    } else {
        $studentIdsArray = [];
    }
    
    output("✓ Archiving completed successfully!");
    output("");
    output("RESULTS:");
    output("  • Students archived: " . $archivedCount);
    output("  • Timestamp: " . date('Y-m-d H:i:s'));
    output("");
    
    // Log to audit trail
    $executedBy = isset($_SESSION['admin_username']) ? $_SESSION['admin_username'] : null;
    $auditLogger->logBulkArchiving(
        $archivedCount,
        $studentIdsArray,
        $executedBy
    );
    
    output("✓ Audit trail updated");
    output("");
    
    // Generate summary report
    output("========================================");
    output("  SUMMARY REPORT");
    output("========================================");
    
    $summaryQuery = "
        SELECT 
            COUNT(*) as total_students,
            COUNT(CASE WHEN is_archived = TRUE THEN 1 END) as total_archived,
            COUNT(CASE WHEN is_archived = FALSE AND status = 'active' THEN 1 END) as active_students
        FROM students
    ";
    
    $summaryResult = pg_query($connection, $summaryQuery);
    $summary = pg_fetch_assoc($summaryResult);
    
    output("Database Status:");
    output("  • Total students: " . $summary['total_students']);
    output("  • Total archived: " . $summary['total_archived']);
    output("  • Active students: " . $summary['active_students']);
    output("  • Newly archived: " . $archivedCount);
    output("");
    
    // Recommendations
    output("RECOMMENDATIONS:");
    output("  1. Review archived students at: System Controls > Archived Students");
    output("  2. Check audit trail for detailed archiving logs");
    output("  3. Schedule this script to run annually after graduation");
    output("  4. Consider unarchiving any incorrectly archived students");
    output("");
    
    output("========================================");
    output("Script completed successfully at: " . date('Y-m-d H:i:s'));
    output("========================================");
    
} catch (Exception $e) {
    output("");
    output("FATAL ERROR: " . $e->getMessage());
    output("Stack trace: " . $e->getTraceAsString());
    exit(1);
}

pg_close($connection);
exit(0);
?>
