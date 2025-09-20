<?php
// OTP Email Template for EducAid System
// Professional HTML email template with responsive design

function generateOTPEmailTemplate($otp, $recipient_name = 'User', $purpose = 'login') {
    $current_year = date('Y');
    
    $template = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>EducAid OTP Verification</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
                line-height: 1.6;
                color: #333333;
                background-color: #f4f6f9;
            }
            
            .email-container {
                max-width: 600px;
                margin: 20px auto;
                background: #ffffff;
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            }
            
            .header {
                background: linear-gradient(135deg, #0051f8, #18a54a);
                color: white;
                padding: 30px 20px;
                text-align: center;
            }
            
            .logo {
                width: 60px;
                height: 60px;
                background: rgba(255, 255, 255, 0.2);
                border-radius: 50%;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                font-size: 1.5rem;
                font-weight: 800;
                margin-bottom: 15px;
            }
            
            .header h1 {
                font-size: 1.4rem;
                margin-bottom: 5px;
                font-weight: 600;
            }
            
            .header p {
                opacity: 0.9;
                font-size: 0.95rem;
            }
            
            .content {
                padding: 40px 30px;
                text-align: center;
            }
            
            .greeting {
                font-size: 1.1rem;
                margin-bottom: 20px;
                color: #2c3e50;
            }
            
            .message {
                font-size: 1rem;
                margin-bottom: 30px;
                color: #555;
                line-height: 1.6;
            }
            
            .otp-container {
                background: linear-gradient(135deg, #f8f9ff, #e8f2ff);
                border: 2px dashed #0051f8;
                border-radius: 12px;
                padding: 25px;
                margin: 30px 0;
                text-align: center;
            }
            
            .otp-label {
                font-size: 0.9rem;
                color: #666;
                margin-bottom: 10px;
                text-transform: uppercase;
                letter-spacing: 1px;
                font-weight: 600;
            }
            
            .otp-code {
                font-size: 2.2rem;
                font-weight: 800;
                color: #0051f8;
                letter-spacing: 8px;
                margin: 10px 0;
                font-family: "Courier New", monospace;
            }
            
            .otp-validity {
                font-size: 0.85rem;
                color: #e74c3c;
                margin-top: 10px;
                font-weight: 500;
            }
            
            .instructions {
                background: #fff9e6;
                border: 1px solid #ffeaa7;
                border-radius: 8px;
                padding: 20px;
                margin: 25px 0;
                text-align: left;
            }
            
            .instructions h3 {
                color: #d68910;
                font-size: 1rem;
                margin-bottom: 12px;
                display: flex;
                align-items: center;
            }
            
            .instructions ul {
                color: #666;
                padding-left: 20px;
            }
            
            .instructions li {
                margin-bottom: 8px;
                font-size: 0.9rem;
            }
            
            .security-notice {
                background: #ffe8e8;
                border: 1px solid #ffcccb;
                border-radius: 8px;
                padding: 15px;
                margin: 20px 0;
                font-size: 0.9rem;
                color: #c0392b;
            }
            
            .footer {
                background: #f8f9fa;
                padding: 25px 30px;
                text-align: center;
                border-top: 1px solid #e9ecef;
            }
            
            .contact-info {
                margin-bottom: 15px;
                color: #666;
                font-size: 0.9rem;
            }
            
            .contact-info a {
                color: #0051f8;
                text-decoration: none;
            }
            
            .copyright {
                font-size: 0.8rem;
                color: #999;
                margin-top: 15px;
            }
            
            .divider {
                height: 2px;
                background: linear-gradient(90deg, #0051f8, #18a54a);
                margin: 20px 0;
            }
            
            @media (max-width: 600px) {
                .email-container {
                    margin: 10px;
                    border-radius: 8px;
                }
                
                .content {
                    padding: 30px 20px;
                }
                
                .otp-code {
                    font-size: 1.8rem;
                    letter-spacing: 4px;
                }
                
                .header {
                    padding: 25px 15px;
                }
                
                .footer {
                    padding: 20px 15px;
                }
            }
        </style>
    </head>
    <body>
        <div class="email-container">
            <!-- Header -->
            <div class="header">
                <div class="logo">EA</div>
                <h1>EducAid Verification</h1>
                <p>City of General Trias • Educational Assistance Program</p>
            </div>
            
            <!-- Main Content -->
            <div class="content">
                <div class="greeting">Hello ' . htmlspecialchars($recipient_name) . '!</div>
                
                <div class="message">
                    You have requested to ' . ($purpose === 'login' ? 'sign in to your EducAid account' : 'reset your password') . '. 
                    Please use the verification code below to complete the process.
                </div>
                
                <!-- OTP Code -->
                <div class="otp-container">
                    <div class="otp-label">Your Verification Code</div>
                    <div class="otp-code">' . $otp . '</div>
                    <div class="otp-validity">⏰ Valid for 10 minutes only</div>
                </div>
                
                <!-- Instructions -->
                <div class="instructions">
                    <h3>📋 How to use this code:</h3>
                    <ul>
                        <li>Return to the EducAid portal and enter this 6-digit code</li>
                        <li>Complete the verification within 10 minutes</li>
                        <li>Do not share this code with anyone</li>
                        <li>Contact support if you didn\'t request this code</li>
                    </ul>
                </div>
                
                <!-- Security Notice -->
                <div class="security-notice">
                    🔒 <strong>Security Notice:</strong> EducAid will never ask for your password or verification code via email, phone, or text message. If you receive suspicious requests, please report them immediately.
                </div>
                
                <div class="divider"></div>
                
                <p style="color: #666; font-size: 0.9rem;">
                    If you didn\'t request this verification code, please ignore this email or contact our support team for assistance.
                </p>
            </div>
            
            <!-- Footer -->
            <div class="footer">
                <div class="contact-info">
                    <strong>Need Help?</strong><br>
                    📧 <a href="mailto:educaid@generaltrias.gov.ph">educaid@generaltrias.gov.ph</a><br>
                    📞 (046) 509-5555<br>
                    🏛️ General Trias City Hall, Cavite
                </div>
                
                <div class="copyright">
                    © ' . $current_year . ' EducAid - City of General Trias. All rights reserved.<br>
                    This is an automated message from the official EducAid portal.
                </div>
            </div>
        </div>
    </body>
    </html>';
    
    return $template;
}

function generatePasswordResetEmailTemplate($reset_token, $recipient_name = 'User') {
    $current_year = date('Y');
    $reset_link = "https://yourdomain.com/reset-password.php?token=" . urlencode($reset_token);
    
    $template = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>EducAid Password Reset</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
                line-height: 1.6;
                color: #333333;
                background-color: #f4f6f9;
            }
            
            .email-container {
                max-width: 600px;
                margin: 20px auto;
                background: #ffffff;
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            }
            
            .header {
                background: linear-gradient(135deg, #e74c3c, #c0392b);
                color: white;
                padding: 30px 20px;
                text-align: center;
            }
            
            .logo {
                width: 60px;
                height: 60px;
                background: rgba(255, 255, 255, 0.2);
                border-radius: 50%;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                font-size: 1.5rem;
                font-weight: 800;
                margin-bottom: 15px;
            }
            
            .reset-button {
                display: inline-block;
                background: linear-gradient(135deg, #0051f8, #18a54a);
                color: white;
                padding: 15px 30px;
                text-decoration: none;
                border-radius: 8px;
                font-weight: 600;
                margin: 20px 0;
                transition: transform 0.2s ease;
            }
            
            .reset-button:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0, 81, 248, 0.3);
            }
            
            .content {
                padding: 40px 30px;
            }
            
            .warning-box {
                background: #fff3cd;
                border: 1px solid #ffeaa7;
                border-radius: 8px;
                padding: 20px;
                margin: 20px 0;
                border-left: 4px solid #f39c12;
            }
        </style>
    </head>
    <body>
        <div class="email-container">
            <div class="header">
                <div class="logo">🔒</div>
                <h1>Password Reset Request</h1>
                <p>EducAid Account Security</p>
            </div>
            
            <div class="content">
                <h2>Hello ' . htmlspecialchars($recipient_name) . ',</h2>
                
                <p>You have requested to reset your EducAid account password. Click the button below to create a new password:</p>
                
                <div style="text-align: center; margin: 30px 0;">
                    <a href="' . $reset_link . '" class="reset-button">Reset My Password</a>
                </div>
                
                <div class="warning-box">
                    <strong>⚠️ Important Security Information:</strong>
                    <ul style="margin-top: 10px; padding-left: 20px;">
                        <li>This link will expire in 1 hour for security reasons</li>
                        <li>If you didn\'t request this reset, please ignore this email</li>
                        <li>Never share your password or reset links with anyone</li>
                    </ul>
                </div>
                
                <p><strong>Alternative:</strong> If the button doesn\'t work, copy and paste this link into your browser:</p>
                <p style="word-break: break-all; background: #f8f9fa; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 0.9rem;">
                    ' . $reset_link . '
                </p>
            </div>
        </div>
    </body>
    </html>';
    
    return $template;
}
?>