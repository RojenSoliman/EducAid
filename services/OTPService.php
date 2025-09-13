<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../phpmailer/vendor/autoload.php';

class OTPService {
    private $connection;
    
    public function __construct($dbConnection) {
        $this->connection = $dbConnection;
    }
    
    /**
     * Generate and send OTP to email
     */
    public function sendOTP($email, $purpose, $adminId) {
        $otp = $this->generateOTP();
        // Use PostgreSQL's timezone-aware NOW() function for consistency
        $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        
        // Store OTP in database
        $this->storeOTP($adminId, $otp, $email, $purpose, $expiresAt);
        
        // Send email
        return $this->sendOTPEmail($email, $otp, $purpose);
    }
    
    /**
     * Verify OTP
     */
    public function verifyOTP($adminId, $otp, $purpose) {
        $query = "
            SELECT * FROM admin_otp_verifications 
            WHERE admin_id = $1 AND otp = $2 AND purpose = $3 
            AND expires_at > NOW() AND used = FALSE
            ORDER BY created_at DESC LIMIT 1
        ";
        
        // Debug: Log the verification query parameters
        error_log("OTPService verifyOTP: adminId=$adminId, otp=$otp, purpose=$purpose");
        
        $result = pg_query_params($this->connection, $query, [$adminId, $otp, $purpose]);
        
        if ($result) {
            $rowCount = pg_num_rows($result);
            error_log("OTPService verifyOTP: Query executed, found $rowCount matching records");
            
            if ($rowCount > 0) {
                // Mark OTP as used
                $otpData = pg_fetch_assoc($result);
                error_log("OTPService verifyOTP: Found valid OTP, marking as used");
                $this->markOTPAsUsed($otpData['id']);
                return true;
            } else {
                error_log("OTPService verifyOTP: No valid OTP found");
            }
        } else {
            $error = pg_last_error($this->connection);
            error_log("OTPService verifyOTP: Query failed - $error");
        }
        
        return false;
    }
    
    /**
     * Generate 6-digit OTP
     */
    private function generateOTP() {
        return sprintf('%06d', mt_rand(0, 999999));
    }
    
    /**
     * Store OTP in database
     */
    private function storeOTP($adminId, $otp, $email, $purpose, $expiresAt) {
        // Clean up old OTPs for this admin and purpose
        pg_query_params($this->connection, "
            UPDATE admin_otp_verifications 
            SET used = TRUE 
            WHERE admin_id = $1 AND purpose = $2 AND used = FALSE
        ", [$adminId, $purpose]);
        
        // Insert new OTP with PostgreSQL's NOW() + interval for consistent timezone handling
        pg_query_params($this->connection, "
            INSERT INTO admin_otp_verifications (admin_id, otp, email, purpose, expires_at) 
            VALUES ($1, $2, $3, $4, NOW() + INTERVAL '10 minutes')
        ", [$adminId, $otp, $email, $purpose]);
    }
    
    /**
     * Mark OTP as used
     */
    private function markOTPAsUsed($otpId) {
        pg_query_params($this->connection, "
            UPDATE admin_otp_verifications SET used = TRUE WHERE id = $1
        ", [$otpId]);
    }
    
    /**
     * Send OTP email using PHPMailer
     */
    private function sendOTPEmail($email, $otp, $purpose) {
        $mail = new PHPMailer(true);
        
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'dilucayaka02@gmail.com';
            $mail->Password   = 'jlld eygl hksj flvg';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            
            // Recipients
            $mail->setFrom('dilucayaka02@gmail.com', 'EducAid System');
            $mail->addAddress($email);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'EducAid OTP Verification';
            
            $purposeText = $purpose === 'email_change' ? 'email address change' : 'password change';
            
            $mail->Body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                    <h2 style='color: #007bff;'>EducAid OTP Verification</h2>
                    <p>You have requested a <strong>{$purposeText}</strong> for your EducAid admin account.</p>
                    <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; text-align: center; margin: 20px 0;'>
                        <h3 style='color: #28a745; margin: 0; font-size: 32px; letter-spacing: 3px;'>{$otp}</h3>
                    </div>
                    <p><strong>Important:</strong></p>
                    <ul>
                        <li>This OTP is valid for 10 minutes only</li>
                        <li>Do not share this code with anyone</li>
                        <li>If you didn't request this change, please ignore this email</li>
                    </ul>
                    <hr style='border: none; border-top: 1px solid #dee2e6; margin: 20px 0;'>
                    <small style='color: #6c757d;'>This is an automated message from EducAid System. Please do not reply to this email.</small>
                </div>
            ";
            
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("OTP Email Error: " . $mail->ErrorInfo);
            return false;
        }
    }
}
?>