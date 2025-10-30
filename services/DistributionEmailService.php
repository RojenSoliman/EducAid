<?php
/**
 * Email Notification Service for Distribution Lifecycle Events
 * Sends bulk emails to students when distributions open/close
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../phpmailer/vendor/autoload.php';

class DistributionEmailService {
    private $conn;
    private $from_email;
    private $from_name;
    
    public function __construct($connection) {
        $this->conn = $connection;
        $this->from_email = 'dilucayaka02@gmail.com';
        $this->from_name = 'EducAid General Trias';
    }
    
    /**
     * Send email to all applicant students when distribution opens
     */
    public function notifyDistributionOpened($academic_year, $semester, $deadline) {
        try {
            // Get all applicant students with valid emails
            $query = "SELECT student_id, first_name, last_name, email 
                     FROM students 
                     WHERE status = 'applicant' 
                     AND email IS NOT NULL 
                     AND email != ''";
            
            $result = pg_query($this->conn, $query);
            
            if (!$result) {
                error_log('[EmailService] Failed to fetch students: ' . pg_last_error($this->conn));
                return ['success' => false, 'message' => 'Database error'];
            }
            
            $sent_count = 0;
            $failed_count = 0;
            
            while ($student = pg_fetch_assoc($result)) {
                $success = $this->sendDistributionOpenedEmail(
                    $student['email'],
                    $student['first_name'] . ' ' . $student['last_name'],
                    $academic_year,
                    $semester,
                    $deadline
                );
                
                if ($success) {
                    $sent_count++;
                } else {
                    $failed_count++;
                }
                
                // Add small delay to avoid overwhelming mail server (0.1 seconds)
                usleep(100000);
            }
            
            error_log("[EmailService] Distribution Opened emails sent: $sent_count, failed: $failed_count");
            
            return [
                'success' => true,
                'sent' => $sent_count,
                'failed' => $failed_count
            ];
            
        } catch (Exception $e) {
            error_log('[EmailService] Error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Send email to all active students when distribution closes
     */
    public function notifyDistributionClosed($academic_year, $semester) {
        try {
            // Get all students who participated (active or given status)
            $query = "SELECT student_id, first_name, last_name, email 
                     FROM students 
                     WHERE status IN ('active', 'given', 'applicant')
                     AND email IS NOT NULL 
                     AND email != ''";
            
            $result = pg_query($this->conn, $query);
            
            if (!$result) {
                error_log('[EmailService] Failed to fetch students: ' . pg_last_error($this->conn));
                return ['success' => false, 'message' => 'Database error'];
            }
            
            $sent_count = 0;
            $failed_count = 0;
            
            while ($student = pg_fetch_assoc($result)) {
                $success = $this->sendDistributionClosedEmail(
                    $student['email'],
                    $student['first_name'] . ' ' . $student['last_name'],
                    $academic_year,
                    $semester
                );
                
                if ($success) {
                    $sent_count++;
                } else {
                    $failed_count++;
                }
                
                usleep(100000);
            }
            
            error_log("[EmailService] Distribution Closed emails sent: $sent_count, failed: $failed_count");
            
            return [
                'success' => true,
                'sent' => $sent_count,
                'failed' => $failed_count
            ];
            
        } catch (Exception $e) {
            error_log('[EmailService] Error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Send individual email for distribution opened
     */
    private function sendDistributionOpenedEmail($to_email, $student_name, $academic_year, $semester, $deadline) {
        $deadline_formatted = date('F j, Y', strtotime($deadline));
        
        $subject = "📢 New Educational Assistance Distribution is Now Open - $academic_year $semester";
        
        $message = $this->getEmailTemplate([
            'student_name' => $student_name,
            'title' => 'New Distribution Started!',
            'message' => "Great news! The educational assistance distribution for <strong>$academic_year $semester</strong> is now open.",
            'details' => [
                "📅 <strong>Submission Deadline:</strong> $deadline_formatted",
                "📝 <strong>Action Required:</strong> Upload your required documents",
                "⏰ <strong>Time Remaining:</strong> " . $this->calculateTimeRemaining($deadline)
            ],
            'cta_text' => 'Upload Documents Now',
            'cta_url' => getenv('APP_URL') . '/modules/student/upload_document.php',
            'footer_text' => 'Don\'t miss this opportunity! Make sure to submit all required documents before the deadline.'
        ]);
        
        return $this->sendEmail($to_email, $subject, $message);
    }
    
    /**
     * Send individual email for distribution closed
     */
    private function sendDistributionClosedEmail($to_email, $student_name, $academic_year, $semester) {
        $subject = "✅ Distribution Completed - $academic_year $semester";
        
        $message = $this->getEmailTemplate([
            'student_name' => $student_name,
            'title' => 'Distribution Cycle Completed',
            'message' => "The educational assistance distribution for <strong>$academic_year $semester</strong> has been successfully completed.",
            'details' => [
                "✅ All qualified students have been processed",
                "📊 You can view your distribution history in your student portal",
                "🔔 Watch for announcements about the next distribution cycle"
            ],
            'cta_text' => 'View My Dashboard',
            'cta_url' => getenv('APP_URL') . '/modules/student/student_homepage.php',
            'footer_text' => 'Thank you for your participation in this distribution cycle!'
        ]);
        
        return $this->sendEmail($to_email, $subject, $message);
    }
    
    /**
     * Get email HTML template
     */
    private function getEmailTemplate($data) {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f7f9;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 20px auto;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px 20px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .header p {
            margin: 5px 0 0 0;
            opacity: 0.9;
            font-size: 14px;
        }
        .content {
            padding: 30px 25px;
        }
        .greeting {
            font-size: 18px;
            color: #2c3e50;
            margin-bottom: 20px;
        }
        .message {
            font-size: 15px;
            color: #555;
            margin-bottom: 25px;
            line-height: 1.8;
        }
        .details {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 15px 20px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .details p {
            margin: 10px 0;
            font-size: 14px;
        }
        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white !important;
            padding: 14px 32px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            margin: 20px 0;
            text-align: center;
        }
        .footer {
            background: #f8f9fa;
            padding: 20px 25px;
            text-align: center;
            font-size: 13px;
            color: #6c757d;
            border-top: 1px solid #e9ecef;
        }
        .footer-note {
            font-size: 12px;
            color: #999;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎓 EducAid General Trias</h1>
            <p>Educational Assistance Program</p>
        </div>
        <div class="content">
            <div class="greeting">
                Hello, <strong>{$data['student_name']}</strong>!
            </div>
            <h2 style="color: #667eea; margin: 0 0 15px 0;">{$data['title']}</h2>
            <div class="message">
                {$data['message']}
            </div>
            <div class="details">
HTML;
        
        foreach ($data['details'] as $detail) {
            $message .= "<p>$detail</p>\n";
        }
        
        $message .= <<<HTML
            </div>
            <div style="text-align: center;">
                <a href="{$data['cta_url']}" class="cta-button">{$data['cta_text']}</a>
            </div>
        </div>
        <div class="footer">
            <p><strong>{$data['footer_text']}</strong></p>
            <p class="footer-note">
                This is an automated email from EducAid General Trias. Please do not reply to this email.
                <br>For questions, please contact your school administrator.
            </p>
        </div>
    </div>
</body>
</html>
HTML;
        
        return $message;
    }
    
    /**
     * Calculate time remaining until deadline
     */
    private function calculateTimeRemaining($deadline) {
        $now = new DateTime();
        $deadline_dt = new DateTime($deadline);
        $diff = $now->diff($deadline_dt);
        
        if ($diff->days > 0) {
            return $diff->days . ' days';
        } elseif ($diff->h > 0) {
            return $diff->h . ' hours';
        } else {
            return 'Less than an hour';
        }
    }
    
    /**
     * Send email using PHPMailer with SMTP (same config as OTPService)
     */
    private function sendEmail($to, $subject, $message) {
        $mail = new PHPMailer(true);
        
        try {
            // Server settings - Same as OTPService
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'dilucayaka02@gmail.com';
            $mail->Password   = 'jlld eygl hksj flvg';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            
            // Recipients
            $mail->setFrom($this->from_email, $this->from_name);
            $mail->addAddress($to);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $message;
            
            // Send email
            $result = $mail->send();
            
            if ($result) {
                error_log("[DistributionEmailService] Email sent successfully to: $to");
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("[DistributionEmailService] Failed to send email to $to: {$mail->ErrorInfo}");
            return false;
        }
    }
}

