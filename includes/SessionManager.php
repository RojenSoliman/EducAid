<?php
/**
 * Session Manager
 * Manages student login sessions and history
 */

require_once __DIR__ . '/UserAgentParser.php';

class SessionManager {
    private $connection;
    
    public function __construct($connection) {
        $this->connection = $connection;
    }
    
    /**
     * Log a successful student login
     */
    public function logLogin($studentId, $sessionId, $loginMethod = 'password') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $deviceInfo = UserAgentParser::parse($userAgent);
        
        // Insert login history
        pg_query_params($this->connection,
            "INSERT INTO student_login_history 
             (student_id, ip_address, user_agent, device_type, browser, os, login_method, status, session_id)
             VALUES ($1, $2, $3, $4, $5, $6, $7, 'success', $8)",
            [
                $studentId,
                $ip,
                $userAgent,
                $deviceInfo['device'],
                $deviceInfo['browser'],
                $deviceInfo['os'],
                $loginMethod,
                $sessionId
            ]
        );
        
        // Create active session
        $expiresAt = date('Y-m-d H:i:s', time() + (24 * 60 * 60)); // 24 hours from now
        
        pg_query_params($this->connection,
            "INSERT INTO student_active_sessions 
             (session_id, student_id, ip_address, user_agent, device_type, browser, os, expires_at, is_current)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8, TRUE)
             ON CONFLICT (session_id) DO UPDATE SET
             last_activity = NOW(),
             is_current = TRUE",
            [
                $sessionId,
                $studentId,
                $ip,
                $userAgent,
                $deviceInfo['device'],
                $deviceInfo['browser'],
                $deviceInfo['os'],
                $expiresAt
            ]
        );
        
        // Mark all other sessions as not current
        pg_query_params($this->connection,
            "UPDATE student_active_sessions 
             SET is_current = FALSE 
             WHERE student_id = $1 AND session_id != $2",
            [$studentId, $sessionId]
        );
    }
    
    /**
     * Log a failed login attempt
     */
    public function logFailedLogin($studentId, $reason = 'Invalid credentials') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $deviceInfo = UserAgentParser::parse($userAgent);
        
        pg_query_params($this->connection,
            "INSERT INTO student_login_history 
             (student_id, ip_address, user_agent, device_type, browser, os, status, failure_reason)
             VALUES ($1, $2, $3, $4, $5, $6, 'failed', $7)",
            [
                $studentId,
                $ip,
                $userAgent,
                $deviceInfo['device'],
                $deviceInfo['browser'],
                $deviceInfo['os'],
                $reason
            ]
        );
    }
    
    /**
     * Update session activity
     */
    public function updateActivity($sessionId) {
        pg_query_params($this->connection,
            "UPDATE student_active_sessions 
             SET last_activity = NOW() 
             WHERE session_id = $1",
            [$sessionId]
        );
    }
    
    /**
     * Log a logout
     */
    public function logLogout($sessionId) {
        // Update login history
        pg_query_params($this->connection,
            "UPDATE student_login_history 
             SET logout_time = NOW() 
             WHERE session_id = $1 AND logout_time IS NULL",
            [$sessionId]
        );
        
        // Remove from active sessions
        pg_query_params($this->connection,
            "DELETE FROM student_active_sessions 
             WHERE session_id = $1",
            [$sessionId]
        );
    }
    
    /**
     * Revoke a specific session
     */
    public function revokeSession($studentId, $sessionId) {
        // Verify session belongs to this student
        $result = pg_query_params($this->connection,
            "SELECT session_id FROM student_active_sessions 
             WHERE session_id = $1 AND student_id = $2",
            [$sessionId, $studentId]
        );
        
        if (pg_num_rows($result) > 0) {
            $this->logLogout($sessionId);
            return true;
        }
        
        return false;
    }
    
    /**
     * Revoke all sessions except current
     */
    public function revokeAllOtherSessions($studentId, $currentSessionId) {
        // Get all other session IDs
        $result = pg_query_params($this->connection,
            "SELECT session_id FROM student_active_sessions 
             WHERE student_id = $1 AND session_id != $2",
            [$studentId, $currentSessionId]
        );
        
        $count = 0;
        while ($row = pg_fetch_assoc($result)) {
            $this->logLogout($row['session_id']);
            $count++;
        }
        
        return $count;
    }
    
    /**
     * Get active sessions for a student
     */
    public function getActiveSessions($studentId) {
        $result = pg_query_params($this->connection,
            "SELECT * FROM student_active_sessions 
             WHERE student_id = $1 
             ORDER BY is_current DESC, last_activity DESC",
            [$studentId]
        );
        
        return pg_fetch_all($result) ?: [];
    }
    
    /**
     * Get login history for a student
     */
    public function getLoginHistory($studentId, $limit = 10) {
        $result = pg_query_params($this->connection,
            "SELECT * FROM student_login_history 
             WHERE student_id = $1 
             ORDER BY login_time DESC 
             LIMIT $2",
            [$studentId, $limit]
        );
        
        return pg_fetch_all($result) ?: [];
    }
    
    /**
     * Clean up expired sessions
     */
    public function cleanupExpiredSessions() {
        // Delete sessions inactive for more than 24 hours
        pg_query($this->connection,
            "DELETE FROM student_active_sessions 
             WHERE last_activity < NOW() - INTERVAL '24 hours'"
        );
    }
}
