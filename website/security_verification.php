<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify - EducAid</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google reCAPTCHA v2 -->
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    
    <style>
        body {
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        
        .verification-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .verification-box {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 2rem;
            max-width: 400px;
            width: 100%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .site-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #212529;
            margin-bottom: 0.5rem;
            text-align: center;
        }
        
        .verification-text {
            color: #6c757d;
            margin-bottom: 1.5rem;
            text-align: center;
            font-size: 0.95rem;
        }
        
        .g-recaptcha {
            margin: 1.5rem 0;
            display: flex;
            justify-content: center;
        }
        
        .continue-btn {
            background-color: #0d6efd;
            border: 1px solid #0d6efd;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            width: 100%;
            font-size: 0.95rem;
        }
        
        .continue-btn:hover:not(:disabled) {
            background-color: #0b5ed7;
            border-color: #0a58ca;
        }
        
        .continue-btn:disabled {
            background-color: #6c757d;
            border-color: #6c757d;
            opacity: 0.65;
        }
        
        @media (max-width: 576px) {
            .verification-box {
                margin: 1rem;
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <?php
    // Include reCAPTCHA configuration
    require_once '../config/recaptcha_v2_config.php';
    
    // Check if user is already verified
    session_start();
    if (isset($_SESSION['captcha_verified']) && $_SESSION['captcha_verified'] === true) {
        header('Location: landingpage.php');
        exit;
    }
    ?>

    <div class="verification-container">
        <div class="verification-box">
            <h1 class="site-title">EducAid</h1>
            <p class="verification-text">
                Please verify that you are human to continue.
            </p>
            
            <form id="verificationForm">
                <!-- reCAPTCHA v2 widget -->
                <div class="g-recaptcha" 
                     data-sitekey="<?php echo RECAPTCHA_V2_SITE_KEY; ?>" 
                     data-callback="enableContinueButton">
                </div>
                
                <button type="submit" id="continueBtn" class="btn continue-btn" disabled>
                    Continue
                </button>
            </form>
        </div>
    </div>

    <script>
        // Enable continue button when reCAPTCHA is completed
        function enableContinueButton() {
            document.getElementById('continueBtn').disabled = false;
        }
        
        // Handle form submission
        document.getElementById('verificationForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const recaptchaResponse = grecaptcha.getResponse();
            const continueBtn = document.getElementById('continueBtn');
            
            if (!recaptchaResponse) {
                alert('Please complete the verification');
                return;
            }
            
            // Show loading state
            continueBtn.disabled = true;
            continueBtn.textContent = 'Verifying...';
            
            try {
                const formData = new FormData();
                formData.append('g-recaptcha-response', recaptchaResponse);
                
                const response = await fetch('verify_captcha.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    window.location.href = 'landingpage.php';
                } else {
                    alert('Verification failed. Please try again.');
                    grecaptcha.reset();
                    continueBtn.disabled = true;
                    continueBtn.textContent = 'Continue';
                }
                
            } catch (error) {
                console.error('Verification error:', error);
                alert('Error occurred. Please try again.');
                grecaptcha.reset();
                continueBtn.disabled = true;
                continueBtn.textContent = 'Continue';
            }
        });
    </script>
</body>
<?php if (file_exists(__DIR__ . '/../includes/website/cookie_consent.php')) { include __DIR__ . '/../includes/website/cookie_consent.php'; } ?>
</html>