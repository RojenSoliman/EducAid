<?php
/**
 * AuditLogger Service
 * Comprehensive audit trail logging for EducAid system
 * 
 * Tracks all major events including:
 * - Authentication (login/logout)
 * - Slot management
 * - Applicant management
 * - Payroll and schedule operations
 * - Profile changes
 * - Distribution lifecycle
 * 
 * @author EducAid Team
 * @version 1.0
 * @created 2025-10-15
 */

class AuditLogger {
    private $connection;
    
    /**
     * Initialize AuditLogger with database connection
     */
    public function __construct($dbConnection) {
        $this->connection = $dbConnection;
    }
    
    /**
     * Log any audit event
     * 
     * @param string $eventType Specific event type (e.g., 'admin_login', 'slot_opened')
     * @param string $eventCategory Category grouping (e.g., 'authentication', 'slot_management')
     * @param string $actionDescription Human-readable description
     * @param array $options Additional options:
     *   - user_id: ID of user
     *   - user_type: 'admin', 'student', or 'system'
     *   - username: Username for reference
     *   - status: 'success', 'failure', 'warning' (default: 'success')
     *   - affected_table: Table name affected
     *   - affected_record_id: ID of affected record
     *   - old_values: Array of old values (for updates)
     *   - new_values: Array of new values
     *   - metadata: Array of additional context
     * @return bool Success status
     */
    public function logEvent($eventType, $eventCategory, $actionDescription, $options = []) {
        try {
            // Extract options with defaults
            $userId = $options['user_id'] ?? null;
            $userType = $options['user_type'] ?? 'system';
            $username = $options['username'] ?? null;
            $status = $options['status'] ?? 'success';
            $affectedTable = $options['affected_table'] ?? null;
            $affectedRecordId = $options['affected_record_id'] ?? null;
            $oldValues = isset($options['old_values']) ? json_encode($options['old_values']) : null;
            $newValues = isset($options['new_values']) ? json_encode($options['new_values']) : null;
            $metadata = isset($options['metadata']) ? json_encode($options['metadata']) : null;
            
            // Get request context
            $ipAddress = $this->getClientIP();
            $userAgent = $this->getUserAgent();
            $requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
            $requestUri = $_SERVER['REQUEST_URI'] ?? null;
            $sessionId = session_id() ?: null;
            
            // Insert audit log
            $query = "
                INSERT INTO audit_logs (
                    user_id, user_type, username,
                    event_type, event_category, action_description,
                    status, ip_address, user_agent,
                    request_method, request_uri,
                    affected_table, affected_record_id,
                    old_values, new_values, metadata,
                    session_id
                ) VALUES (
                    $1, $2, $3,
                    $4, $5, $6,
                    $7, $8, $9,
                    $10, $11,
                    $12, $13,
                    $14, $15, $16,
                    $17
                )
            ";
            
            $result = pg_query_params($this->connection, $query, [
                $userId, $userType, $username,
                $eventType, $eventCategory, $actionDescription,
                $status, $ipAddress, $userAgent,
                $requestMethod, $requestUri,
                $affectedTable, $affectedRecordId,
                $oldValues, $newValues, $metadata,
                $sessionId
            ]);
            
            if (!$result) {
                error_log("AuditLogger: Failed to log event - " . pg_last_error($this->connection));
                return false;
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("AuditLogger Exception: " . $e->getMessage());
            return false;
        }
    }
    
    // =============================================
    // AUTHENTICATION EVENTS
    // =============================================
    
    /**
     * Log successful login
     */
    public function logLogin($userId, $userType, $username) {
        return $this->logEvent(
            $userType . '_login',
            'authentication',
            ucfirst($userType) . " '{$username}' logged in successfully",
            [
                'user_id' => $userId,
                'user_type' => $userType,
                'username' => $username,
                'status' => 'success',
                'metadata' => [
                    'login_time' => date('Y-m-d H:i:s'),
                    'session_id' => session_id()
                ]
            ]
        );
    }
    
    /**
     * Log failed login attempt
     */
    public function logLoginFailure($username, $userType, $reason = 'Invalid credentials') {
        return $this->logEvent(
            'login_failed',
            'authentication',
            "Failed login attempt for {$userType} '{$username}'",
            [
                'user_id' => null,
                'user_type' => $userType,
                'username' => $username,
                'status' => 'failure',
                'metadata' => [
                    'reason' => $reason,
                    'attempt_time' => date('Y-m-d H:i:s')
                ]
            ]
        );
    }
    
    /**
     * Log logout
     */
    public function logLogout($userId, $userType, $username) {
        return $this->logEvent(
            $userType . '_logout',
            'authentication',
            ucfirst($userType) . " '{$username}' logged out",
            [
                'user_id' => $userId,
                'user_type' => $userType,
                'username' => $username,
                'status' => 'success',
                'metadata' => [
                    'logout_time' => date('Y-m-d H:i:s')
                ]
            ]
        );
    }
    
    // =============================================
    // SLOT MANAGEMENT EVENTS
    // =============================================
    
    /**
     * Log slot opened
     */
    public function logSlotOpened($adminId, $adminUsername, $slotId, $slotData) {
        return $this->logEvent(
            'slot_opened',
            'slot_management',
            "Admin '{$adminUsername}' opened signup slot: {$slotData['academic_year']} {$slotData['semester']}",
            [
                'user_id' => $adminId,
                'user_type' => 'admin',
                'username' => $adminUsername,
                'affected_table' => 'signup_slots',
                'affected_record_id' => $slotId,
                'new_values' => $slotData,
                'metadata' => [
                    'slot_id' => $slotId,
                    'max_applicants' => $slotData['max_applicants'] ?? null
                ]
            ]
        );
    }
    
    /**
     * Log slot closed
     */
    public function logSlotClosed($adminId, $adminUsername, $slotId, $slotData) {
        return $this->logEvent(
            'slot_closed',
            'slot_management',
            "Admin '{$adminUsername}' closed signup slot: {$slotData['academic_year']} {$slotData['semester']}",
            [
                'user_id' => $adminId,
                'user_type' => 'admin',
                'username' => $adminUsername,
                'affected_table' => 'signup_slots',
                'affected_record_id' => $slotId,
                'old_values' => ['is_active' => true],
                'new_values' => ['is_active' => false],
                'metadata' => [
                    'slot_id' => $slotId,
                    'total_applicants' => $slotData['total_applicants'] ?? 0
                ]
            ]
        );
    }
    
    // =============================================
    // APPLICANT MANAGEMENT EVENTS
    // =============================================
    
    /**
     * Log new applicant registration
     */
    public function logApplicantRegistered($studentId, $studentData) {
        return $this->logEvent(
            'applicant_registered',
            'applicant_management',
            "New applicant registered: {$studentData['first_name']} {$studentData['last_name']} ({$studentData['email']})",
            [
                'user_id' => $studentId,
                'user_type' => 'student',
                'username' => $studentData['email'] ?? 'unknown',
                'affected_table' => 'students',
                'affected_record_id' => $studentId,
                'new_values' => [
                    'email' => $studentData['email'] ?? null,
                    'first_name' => $studentData['first_name'] ?? null,
                    'last_name' => $studentData['last_name'] ?? null,
                    'status' => 'applicant'
                ],
                'metadata' => [
                    'slot_id' => $studentData['slot_id'] ?? null,
                    'university' => $studentData['university'] ?? null
                ]
            ]
        );
    }
    
    /**
     * Log applicant approved
     */
    public function logApplicantApproved($adminId, $adminUsername, $studentId, $studentData) {
        return $this->logEvent(
            'applicant_approved',
            'applicant_management',
            "Admin '{$adminUsername}' approved applicant: {$studentData['first_name']} {$studentData['last_name']}",
            [
                'user_id' => $adminId,
                'user_type' => 'admin',
                'username' => $adminUsername,
                'affected_table' => 'students',
                'affected_record_id' => $studentId,
                'old_values' => ['status' => 'applicant'],
                'new_values' => ['status' => 'active'],
                'metadata' => [
                    'student_id' => $studentId,
                    'student_email' => $studentData['email'] ?? null
                ]
            ]
        );
    }
    
    /**
     * Log applicant rejected
     */
    public function logApplicantRejected($adminId, $adminUsername, $studentId, $studentData, $reason = null) {
        return $this->logEvent(
            'applicant_rejected',
            'applicant_management',
            "Admin '{$adminUsername}' rejected applicant: {$studentData['first_name']} {$studentData['last_name']}",
            [
                'user_id' => $adminId,
                'user_type' => 'admin',
                'username' => $adminUsername,
                'affected_table' => 'students',
                'affected_record_id' => $studentId,
                'old_values' => ['status' => 'applicant'],
                'new_values' => ['status' => 'rejected'],
                'metadata' => [
                    'student_id' => $studentId,
                    'student_email' => $studentData['email'] ?? null,
                    'rejection_reason' => $reason
                ]
            ]
        );
    }
    
    /**
     * Log applicant verification
     */
    public function logApplicantVerified($adminId, $adminUsername, $studentId, $studentData) {
        return $this->logEvent(
            'applicant_verified',
            'applicant_management',
            "Admin '{$adminUsername}' verified applicant documents: {$studentData['first_name']} {$studentData['last_name']}",
            [
                'user_id' => $adminId,
                'user_type' => 'admin',
                'username' => $adminUsername,
                'affected_table' => 'students',
                'affected_record_id' => $studentId,
                'metadata' => [
                    'student_id' => $studentId,
                    'verification_status' => 'verified'
                ]
            ]
        );
    }
    
    // =============================================
    // PAYROLL & SCHEDULE EVENTS
    // =============================================
    
    /**
     * Log payroll generation
     */
    public function logPayrollGenerated($adminId, $adminUsername, $totalGenerated, $metadata = []) {
        return $this->logEvent(
            'payroll_generated',
            'payroll',
            "Admin '{$adminUsername}' generated {$totalGenerated} payroll numbers",
            [
                'user_id' => $adminId,
                'user_type' => 'admin',
                'username' => $adminUsername,
                'affected_table' => 'students',
                'metadata' => array_merge([
                    'total_generated' => $totalGenerated,
                    'generation_time' => date('Y-m-d H:i:s')
                ], $metadata)
            ]
        );
    }
    
    /**
     * Log payroll number change
     */
    public function logPayrollNumberChanged($adminId, $adminUsername, $studentId, $oldPayroll, $newPayroll) {
        return $this->logEvent(
            'payroll_number_changed',
            'payroll',
            "Admin '{$adminUsername}' changed payroll number from {$oldPayroll} to {$newPayroll}",
            [
                'user_id' => $adminId,
                'user_type' => 'admin',
                'username' => $adminUsername,
                'affected_table' => 'students',
                'affected_record_id' => $studentId,
                'old_values' => ['payroll_no' => $oldPayroll],
                'new_values' => ['payroll_no' => $newPayroll],
                'metadata' => [
                    'student_id' => $studentId
                ]
            ]
        );
    }
    
    /**
     * Log schedule creation
     */
    public function logScheduleCreated($adminId, $adminUsername, $scheduleData) {
        return $this->logEvent(
            'schedule_created',
            'schedule',
            "Admin '{$adminUsername}' created distribution schedule: {$scheduleData['start_date']} to {$scheduleData['end_date']}",
            [
                'user_id' => $adminId,
                'user_type' => 'admin',
                'username' => $adminUsername,
                'affected_table' => 'schedules',
                'new_values' => $scheduleData,
                'metadata' => [
                    'total_students' => $scheduleData['total_students'] ?? 0,
                    'location' => $scheduleData['location'] ?? null,
                    'batches' => $scheduleData['batches'] ?? 0
                ]
            ]
        );
    }
    
    /**
     * Log schedule published
     */
    public function logSchedulePublished($adminId, $adminUsername) {
        return $this->logEvent(
            'schedule_published',
            'schedule',
            "Admin '{$adminUsername}' published distribution schedule to students",
            [
                'user_id' => $adminId,
                'user_type' => 'admin',
                'username' => $adminUsername,
                'affected_table' => 'schedules',
                'metadata' => [
                    'published_at' => date('Y-m-d H:i:s')
                ]
            ]
        );
    }
    
    /**
     * Log schedule cleared
     */
    public function logScheduleCleared($adminId, $adminUsername, $totalDeleted) {
        return $this->logEvent(
            'schedule_cleared',
            'schedule',
            "Admin '{$adminUsername}' cleared all schedule data ({$totalDeleted} records deleted)",
            [
                'user_id' => $adminId,
                'user_type' => 'admin',
                'username' => $adminUsername,
                'affected_table' => 'schedules',
                'status' => 'warning',
                'metadata' => [
                    'total_deleted' => $totalDeleted,
                    'cleared_at' => date('Y-m-d H:i:s')
                ]
            ]
        );
    }
    
    // =============================================
    // PROFILE CHANGE EVENTS
    // =============================================
    
    /**
     * Log email change
     */
    public function logEmailChanged($userId, $userType, $username, $oldEmail, $newEmail) {
        return $this->logEvent(
            'email_changed',
            'profile',
            ucfirst($userType) . " '{$username}' changed email from {$oldEmail} to {$newEmail}",
            [
                'user_id' => $userId,
                'user_type' => $userType,
                'username' => $username,
                'affected_table' => $userType === 'admin' ? 'admins' : 'students',
                'affected_record_id' => $userId,
                'old_values' => ['email' => $oldEmail],
                'new_values' => ['email' => $newEmail],
                'metadata' => [
                    'change_time' => date('Y-m-d H:i:s')
                ]
            ]
        );
    }
    
    /**
     * Log password change
     */
    public function logPasswordChanged($userId, $userType, $username) {
        return $this->logEvent(
            'password_changed',
            'profile',
            ucfirst($userType) . " '{$username}' changed their password",
            [
                'user_id' => $userId,
                'user_type' => $userType,
                'username' => $username,
                'affected_table' => $userType === 'admin' ? 'admins' : 'students',
                'affected_record_id' => $userId,
                'metadata' => [
                    'change_time' => date('Y-m-d H:i:s')
                ]
            ]
        );
    }
    
    // =============================================
    // DISTRIBUTION LIFECYCLE EVENTS
    // =============================================
    
    /**
     * Log distribution started
     */
    public function logDistributionStarted($adminId, $adminUsername, $distributionData) {
        return $this->logEvent(
            'distribution_started',
            'distribution',
            "Admin '{$adminUsername}' started new distribution cycle",
            [
                'user_id' => $adminId,
                'user_type' => 'admin',
                'username' => $adminUsername,
                'affected_table' => 'config',
                'new_values' => $distributionData,
                'metadata' => [
                    'academic_year' => $distributionData['academic_year'] ?? null,
                    'semester' => $distributionData['semester'] ?? null,
                    'documents_deadline' => $distributionData['documents_deadline'] ?? null
                ]
            ]
        );
    }
    
    /**
     * Log distribution activated
     */
    public function logDistributionActivated($adminId, $adminUsername) {
        return $this->logEvent(
            'distribution_activated',
            'distribution',
            "Admin '{$adminUsername}' activated distribution (moved to active state)",
            [
                'user_id' => $adminId,
                'user_type' => 'admin',
                'username' => $adminUsername,
                'affected_table' => 'config',
                'old_values' => ['distribution_status' => 'preparing'],
                'new_values' => ['distribution_status' => 'active']
            ]
        );
    }
    
    /**
     * Log distribution completed
     */
    public function logDistributionCompleted($adminId, $adminUsername, $snapshotData) {
        return $this->logEvent(
            'distribution_completed',
            'distribution',
            "Admin '{$adminUsername}' completed distribution cycle",
            [
                'user_id' => $adminId,
                'user_type' => 'admin',
                'username' => $adminUsername,
                'affected_table' => 'distribution_snapshots',
                'affected_record_id' => $snapshotData['snapshot_id'] ?? null,
                'metadata' => [
                    'total_students' => $snapshotData['total_students'] ?? 0,
                    'academic_year' => $snapshotData['academic_year'] ?? null,
                    'semester' => $snapshotData['semester'] ?? null,
                    'distribution_date' => $snapshotData['distribution_date'] ?? null
                ]
            ]
        );
    }
    
    // =============================================
    // HELPER METHODS
    // =============================================
    
    /**
     * Get client IP address (handles proxies)
     */
    private function getClientIP() {
        $ipKeys = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];
        
        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER)) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, 
                        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    /**
     * Get user agent string
     */
    private function getUserAgent() {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    }
    
    /**
     * Get recent audit logs
     */
    public function getRecentLogs($limit = 100, $filters = []) {
        $conditions = [];
        $params = [];
        $paramCount = 1;
        
        if (!empty($filters['user_type'])) {
            $conditions[] = "user_type = $" . $paramCount++;
            $params[] = $filters['user_type'];
        }
        
        if (!empty($filters['event_category'])) {
            $conditions[] = "event_category = $" . $paramCount++;
            $params[] = $filters['event_category'];
        }
        
        if (!empty($filters['status'])) {
            $conditions[] = "status = $" . $paramCount++;
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['date_from'])) {
            $conditions[] = "created_at >= $" . $paramCount++;
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $conditions[] = "created_at <= $" . $paramCount++;
            $params[] = $filters['date_to'];
        }
        
        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
        
        $query = "
            SELECT * FROM audit_logs
            {$whereClause}
            ORDER BY created_at DESC
            LIMIT {$limit}
        ";
        
        return pg_query_params($this->connection, $query, $params);
    }
}
?>
