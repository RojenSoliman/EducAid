<?php
// Workflow Control System
// This file manages the navigation flow and access control for admin features

/**
 * Check if payroll numbers and QR codes have been generated
 */
function hasPayrollAndQR($connection) {
    // Check if there are active students with payroll numbers and QR codes
    $query = "
        SELECT COUNT(*) as total,
               COUNT(CASE WHEN s.payroll_no > 0 THEN 1 END) as with_payroll,
               COUNT(q.qr_id) as with_qr
        FROM students s
        LEFT JOIN qr_codes q ON q.student_id = s.student_id
        WHERE s.status = 'active'
    ";
    
    $result = pg_query($connection, $query);
    $data = pg_fetch_assoc($result);
    
    if ($data && $data['total'] > 0) {
        return ($data['with_payroll'] > 0 && $data['with_qr'] > 0);
    }
    
    return false;
}

/**
 * Check if schedules have been created
 */
function hasSchedules($connection) {
    $query = "SELECT COUNT(*) as count FROM schedules";
    $result = pg_query($connection, $query);
    $data = pg_fetch_assoc($result);
    
    return ($data && $data['count'] > 0);
}

/**
 * Check if student list is finalized
 */
function isStudentListFinalized($connection) {
    $query = "SELECT value FROM config WHERE key = 'student_list_finalized'";
    $result = pg_query($connection, $query);
    $data = pg_fetch_assoc($result);
    
    return ($data && $data['value'] === '1');
}

/**
 * Check current distribution status
 */
function getDistributionStatus($connection) {
    $query = "SELECT value FROM config WHERE key = 'distribution_status'";
    $result = pg_query($connection, $query);
    $data = pg_fetch_assoc($result);
    
    // States: inactive, preparing, active, finalizing, finalized
    return ($data && $data['value']) ? $data['value'] : 'inactive';
}

/**
 * Check if slots are open for registration
 */
function areSlotsOpen($connection) {
    $query = "SELECT value FROM config WHERE key = 'slots_open'";
    $result = pg_query($connection, $query);
    $data = pg_fetch_assoc($result);
    
    return ($data && $data['value'] === '1');
}

/**
 * Check if document uploads are enabled
 */
function areUploadsEnabled($connection) {
    $query = "SELECT value FROM config WHERE key = 'uploads_enabled'";
    $result = pg_query($connection, $query);
    $data = pg_fetch_assoc($result);
    
    return ($data && $data['value'] === '1');
}

/**
 * Get workflow status for navigation control
 */
function getWorkflowStatus($connection) {
    $distributionStatus = getDistributionStatus($connection);
    
    return [
        'list_finalized' => isStudentListFinalized($connection),
        'has_payroll_qr' => hasPayrollAndQR($connection),
        'has_schedules' => hasSchedules($connection),
        'can_schedule' => hasPayrollAndQR($connection),
        'can_scan_qr' => hasPayrollAndQR($connection),
        'can_revert_payroll' => hasPayrollAndQR($connection) && !hasSchedules($connection),
        'distribution_status' => $distributionStatus,
        'slots_open' => areSlotsOpen($connection),
        'uploads_enabled' => areUploadsEnabled($connection),
        'can_start_distribution' => $distributionStatus === 'inactive' || $distributionStatus === 'finalized',
        'can_open_slots' => in_array($distributionStatus, ['preparing', 'active']),
        'can_finalize_distribution' => $distributionStatus === 'active'
    ];
}

/**
 * Get student counts for workflow decisions
 */
function getStudentCounts($connection) {
    $query = "
        SELECT 
            COUNT(CASE WHEN status = 'active' THEN 1 END) as active_count,
            COUNT(CASE WHEN status = 'active' AND payroll_no > 0 THEN 1 END) as with_payroll_count,
            COUNT(CASE WHEN status = 'applicant' THEN 1 END) as applicant_count
        FROM students
    ";
    
    $result = pg_query($connection, $query);
    return pg_fetch_assoc($result);
}
?>
