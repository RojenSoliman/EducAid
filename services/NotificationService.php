<?php

class NotificationService {
    private $connection;
    private $admin_id;
    
    public function __construct($connection, $admin_id) {
        $this->connection = $connection;
        $this->admin_id = $admin_id;
    }
    
    /**
     * Send email notification for visual system changes
     */
    public function sendVisualChangeNotification($changes, $admin_info) {
        try {
            // Include PHPMailer
            require_once __DIR__ . '/../phpmailer/vendor/autoload.php';
            
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Server settings
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com'; // Configure as needed
            $mail->SMTPAuth   = true;
            $mail->Username   = 'your-email@gmail.com'; // Configure in env
            $mail->Password   = 'your-app-password'; // Configure in env
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            
            // Recipients
            $mail->setFrom('noreply@educaid.gov.ph', 'EducAid System');
            $mail->addAddress($admin_info['email'], $admin_info['full_name']);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'EducAid System: Visual Changes Made to Topbar Settings';
            
            // Generate email body
            // Normalize changes so email generator gets consistent shape
            $normalized = $this->normalizeChanges($changes);
            $mail->Body = $this->generateEmailBody($normalized, $admin_info);
            $mail->AltBody = $this->generatePlainTextBody($normalized, $admin_info);
            
            $mail->send();
            
            // Log successful email
            $this->logNotification('email_sent', $admin_info['email'], $normalized);
            
            return true;
            
        } catch (Exception $e) {
            // Log email failure
            $this->logNotification('email_failed', $admin_info['email'], [
                'error' => $e->getMessage(),
                'changes_raw' => $changes
            ]);
            
            error_log("Email notification failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create bell notification for admin panel
     */
    public function createBellNotification($changes, $admin_info) {
        try {
            $normalized = $this->normalizeChanges($changes);
            $notification_title = 'Visual Settings Modified';
            $notification_message = $this->generateBellMessage($normalized, $admin_info);
            // Detect legacy schema (no admin_id / no title columns) and adapt
            $columnsRes = pg_query($this->connection, "SELECT column_name FROM information_schema.columns WHERE table_name='admin_notifications'");
            $colSet = [];
            if ($columnsRes) { while($r = pg_fetch_assoc($columnsRes)) { $colSet[$r['column_name']] = true; } }
            $hasAdminId = isset($colSet['admin_id']);
            $hasTitle = isset($colSet['title']);
            $hasCategory = isset($colSet['category']);
            $hasPriority = isset($colSet['priority']);
            $hasType = isset($colSet['type']);
            $hasIsRead = isset($colSet['is_read']);
            $hasActionUrl = isset($colSet['action_url']);

            $result = false;
            if ($hasAdminId && $hasTitle && $hasType && $hasIsRead) {
                // Modern schema path
                $query = "INSERT INTO admin_notifications (
                            admin_id, title, message, type, is_read, created_at" .
                            ($hasActionUrl ? ", action_url" : "") .
                            ($hasPriority ? ", priority" : "") .
                            ($hasCategory ? ", category" : "") .
                          ") VALUES ($1, $2, $3, $4, FALSE, CURRENT_TIMESTAMP" .
                            ($hasActionUrl ? ", $5" : "") .
                            ($hasPriority ? ", $6" : "") .
                            ($hasCategory ? ", $7" : "") .
                          ")";
                $params = [$this->admin_id, $notification_title, $notification_message, 'visual_change'];
                if ($hasActionUrl) $params[] = 'modules/admin/topbar_settings.php';
                if ($hasPriority) $params[] = 'high';
                if ($hasCategory) $params[] = 'system_settings';
                $result = pg_query_params($this->connection, $query, $params);
            } else {
                // Legacy simple table path (assume columns: message, created_at, is_read maybe)
                $legacyMsg = $notification_title . ': ' . $notification_message;
                if ($hasIsRead && isset($colSet['created_at'])) {
                    $result = pg_query_params($this->connection,
                        "INSERT INTO admin_notifications (message, created_at, is_read) VALUES ($1, CURRENT_TIMESTAMP, FALSE)",
                        [$legacyMsg]
                    );
                } elseif (isset($colSet['created_at'])) {
                    $result = pg_query_params($this->connection,
                        "INSERT INTO admin_notifications (message, created_at) VALUES ($1, CURRENT_TIMESTAMP)",
                        [$legacyMsg]
                    );
                } else {
                    $result = pg_query_params($this->connection,
                        "INSERT INTO admin_notifications (message) VALUES ($1)",
                        [$legacyMsg]
                    );
                }
            }
            
            if ($result) {
                // Also create notification for all super admins (only if admin_id column exists)
                if ($hasAdminId) {
                    $this->notifyAllSuperAdmins($notification_title, $notification_message, $admin_info);
                }
                
                $this->logNotification('bell_created', $admin_info['email'], $normalized);
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Bell notification failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Notify all super admins about the change
     */
    private function notifyAllSuperAdmins($title, $message, $admin_info) {
        $query = "SELECT admin_id FROM admins WHERE role = 'super_admin' AND is_active = TRUE AND admin_id != $1";
        $result = pg_query_params($this->connection, $query, [$this->admin_id]);
        
        if ($result) {
            while ($row = pg_fetch_assoc($result)) {
                $notify_query = "INSERT INTO admin_notifications (
                                   admin_id, title, message, type, is_read, 
                                   created_at, action_url, priority, category
                                 ) VALUES ($1, $2, $3, $4, FALSE, CURRENT_TIMESTAMP, $5, $6, $7)";
                
                pg_query_params($this->connection, $notify_query, [
                    $row['admin_id'],
                    $title,
                    $message . " (Changed by: {$admin_info['full_name']})",
                    'visual_change_alert',
                    'modules/admin/topbar_settings.php',
                    'medium',
                    'system_settings'
                ]);
            }
        }
    }
    
    /**
     * Generate HTML email body
     */
    private function generateEmailBody($changes, $admin_info) {
        $timestamp = date('F j, Y \a\t g:i A');
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        
        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background: #2e7d32; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; }
                .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; }
                .changes { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
                .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; color: #666; }
                .change-item { margin: 10px 0; padding: 10px; border-left: 3px solid #2e7d32; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>🔧 EducAid System Alert</h1>
                <h2>Visual Settings Modified</h2>
            </div>
            
            <div class='content'>
                <div class='warning'>
                    <strong>⚠️ Security Notice:</strong> This email is sent to notify you that visual changes have been made to the EducAid system's topbar settings.
                </div>
                
                <h3>Change Details:</h3>
                <ul>
                    <li><strong>Modified by:</strong> {$admin_info['full_name']} ({$admin_info['username']})</li>
                    <li><strong>Email:</strong> {$admin_info['email']}</li>
                    <li><strong>Date & Time:</strong> {$timestamp}</li>
                    <li><strong>IP Address:</strong> {$ip_address}</li>
                </ul>
                
                <h3>Changes Made:</h3>
                <div class='changes'>";
        
                foreach ($changes as $field => $change) {
                        $from = htmlspecialchars($change['from'] ?? '', ENT_QUOTES);
                        $to = htmlspecialchars($change['to'] ?? '', ENT_QUOTES);
                        $html .= "<div class='change-item'>
                                                <strong>{$field}:</strong><br>
                                                From: <code>{$from}</code><br>
                                                To: <code>{$to}</code>
                                            </div>";
                }
        
        $html .= "
                </div>
                
                <div class='warning'>
                    <strong>Important:</strong> If you did not make these changes, please contact your system administrator immediately and review your account security.
                </div>
                
                <p>You can review the current settings by logging into the admin panel and navigating to System Controls → Topbar Settings.</p>
            </div>
            
            <div class='footer'>
                <p>This is an automated notification from the EducAid System.<br>
                Please do not reply to this email.</p>
            </div>
        </body>
        </html>";
        
        return $html;
    }
    
    /**
     * Generate plain text email body
     */
    private function generatePlainTextBody($changes, $admin_info) {
        $timestamp = date('F j, Y \a\t g:i A');
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        
        $text = "EDUCAID SYSTEM ALERT - Visual Settings Modified\n\n";
        $text .= "SECURITY NOTICE: This email notifies you that visual changes have been made to the EducAid system's topbar settings.\n\n";
        $text .= "CHANGE DETAILS:\n";
        $text .= "- Modified by: {$admin_info['full_name']} ({$admin_info['username']})\n";
        $text .= "- Email: {$admin_info['email']}\n";
        $text .= "- Date & Time: {$timestamp}\n";
        $text .= "- IP Address: {$ip_address}\n\n";
        $text .= "CHANGES MADE:\n";
        
        foreach ($changes as $field => $change) {
            $from = $change['from'] ?? '';
            $to = $change['to'] ?? '';
            $text .= "- {$field}: '{$from}' → '{$to}'\n";
        }
        
        $text .= "\nIMPORTANT: If you did not make these changes, please contact your system administrator immediately.\n\n";
        $text .= "You can review current settings in Admin Panel → System Controls → Topbar Settings.\n\n";
        $text .= "This is an automated notification from the EducAid System.";
        
        return $text;
    }
    
    /**
     * Generate bell notification message
     */
    private function generateBellMessage($changes, $admin_info) {
    $change_count = count($changes);
        $timestamp = date('M j, Y g:i A');
        
        $message = "Visual settings were modified by {$admin_info['full_name']} on {$timestamp}. ";
        $message .= "{$change_count} setting" . ($change_count > 1 ? 's' : '') . " changed: ";
        
        $change_list = [];
        foreach ($changes as $field => $change) { $change_list[] = $field; }
        
        $message .= implode(', ', $change_list) . '.';
        
        return $message;
    }
    
    /**
     * Format field names for display
     */
    private function formatFieldName($field) {
        $field_map = [
            'topbar_email' => 'Email Address',
            'topbar_phone' => 'Phone Number',
            'topbar_office_hours' => 'Office Hours',
            'system_name' => 'System Name',
            'municipality_name' => 'Municipality Name',
            'topbar_bg_color' => 'Background Color',
            'topbar_bg_gradient' => 'Gradient Color',
            'topbar_text_color' => 'Text Color',
            'topbar_link_color' => 'Link Color'
        ];
        
        return $field_map[$field] ?? ucwords(str_replace(['topbar_', '_'], ['', ' '], $field));
    }
    
    /**
     * Log notification events
     */
    private function logNotification($type, $email, $data) {
        // Guard if audit table doesn't exist yet
        $exists = pg_query_params($this->connection, "SELECT 1 FROM information_schema.tables WHERE table_name = $1", ['admin_activity_log']);
        if (!$exists || !pg_fetch_row($exists)) return; // silently skip
        $log_query = "INSERT INTO admin_activity_log (admin_id, action, details, ip_address, timestamp) 
                      VALUES ($1, $2, $3, $4, CURRENT_TIMESTAMP)";
        $details = json_encode([
            'notification_type' => $type,
            'recipient_email' => $email,
            'data' => $data,
            'context' => 'visual_settings_notification'
        ]);
        @pg_query_params($this->connection, $log_query, [
            $this->admin_id,
            "Notification: {$type}",
            $details,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    }

    private function normalizeChanges($changes) {
        // Accept two shapes:
        // 1) ['field_key' => ['from'=>old,'to'=>new], ...]
        // 2) [ ['field'=>'field_key','old_value'=>old,'new_value'=>new,'label'=>..], ...]
        $normalized = [];
        // Shape 1 quick check: any element has 'from' & 'to'
        $shape1 = false;
        foreach ($changes as $k => $v) { if (is_array($v) && (isset($v['from']) || isset($v['to']))) { $shape1=true; break; } }
        if ($shape1) {
            foreach ($changes as $field => $meta) {
                if (!is_array($meta)) continue;
                $normalized[$field] = [
                    'from' => $meta['from'] ?? ($meta['old'] ?? ''),
                    'to'   => $meta['to'] ?? ($meta['new'] ?? '')
                ];
            }
            return $normalized;
        }
        // Shape 2: list of change objects
        foreach ($changes as $row) {
            if (!is_array($row)) continue;
            $field = $row['field'] ?? $row['label'] ?? null;
            if (!$field) continue;
            $normalized[$field] = [
                'from' => $row['old_value'] ?? ($row['from'] ?? ''),
                'to'   => $row['new_value'] ?? ($row['to'] ?? '')
            ];
        }
        return $normalized;
    }
}