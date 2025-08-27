// Student Registration JavaScript Functions
// EducAid Registration System

let countdown;
let currentStep = 1;
let otpVerified = false;
let documentVerified = false;
let filenameValid = false;

// Add these variables at the top with other declarations
let termsRead = false;
let termsAccepted = false;

// ---- STEP NAVIGATION FUNCTIONS ----

function updateRequiredFields() {
    // Disable all required fields initially
    document.querySelectorAll('.step-panel input[required], .step-panel select[required], .step-panel textarea[required]').forEach(el => {
        el.disabled = true;
    });
    // Enable required fields in the visible panel only
    document.querySelectorAll(`#step-${currentStep} input[required], #step-${currentStep} select[required], #step-${currentStep} textarea[required]`).forEach(el => {
        el.disabled = false;
    });
}

function showStep(stepNumber) {
    document.querySelectorAll('.step-panel').forEach(panel => {
        panel.classList.add('d-none');
    });
    document.getElementById(`step-${stepNumber}`).classList.remove('d-none');

    document.querySelectorAll('.step').forEach((step, index) => {
        if (index + 1 === stepNumber) {
            step.classList.add('active');
        } else {
            step.classList.remove('active');
        }
    });
    currentStep = stepNumber;
    updateRequiredFields();
}

function showNotifier(message, type = 'error') {
    const notifier = document.getElementById('notifier');
    notifier.textContent = message;
    notifier.classList.remove('success', 'error');
    notifier.classList.add(type);
    notifier.style.display = 'block';

    setTimeout(() => {
        notifier.style.display = 'none';
    }, 3000);
}

function nextStep() {
    if (currentStep === 6) return;

    let isValid = true;
    const currentPanel = document.getElementById(`step-${currentStep}`);
    const inputs = currentPanel.querySelectorAll('input[required], select[required], textarea[required]');

    inputs.forEach(input => {
        if (input.type === 'radio') {
            const radioGroupName = input.name;
            if (!document.querySelector(`input[name="${radioGroupName}"]:checked`)) {
                isValid = false;
            }
        } else if (input.type === 'checkbox') {
            if (!input.checked) {
                isValid = false;
            }
        } else if (!input.value.trim()) {
            isValid = false;
        }
    });

    if (!isValid) {
        showNotifier('Please fill in all required fields for the current step.', 'error');
        return;
    }

    if (currentStep === 5) {
        if (!otpVerified) {
            showNotifier('Please verify your OTP before proceeding.', 'error');
            return;
        }
        showStep(currentStep + 1);
    } else if (currentStep < 6) {
        showStep(currentStep + 1);
    }
}

function prevStep() {
    if (currentStep > 1) {
        showStep(currentStep - 1);
    }
}

// ---- OTP FUNCTIONS ----

function initializeOTPHandlers() {
    document.getElementById("sendOtpBtn").addEventListener("click", function() {
        const emailInput = document.getElementById('emailInput');
        const email = emailInput.value;

        if (!email || !/\S+@\S+\.\S+/.test(email)) {
            showNotifier('Please enter a valid email address before sending OTP.', 'error');
            return;
        }

        const sendOtpBtn = this;
        sendOtpBtn.disabled = true;
        sendOtpBtn.textContent = 'Sending OTP...';
        document.getElementById("resendOtpBtn").disabled = true;

        const formData = new FormData();
        formData.append('sendOtp', 'true');
        formData.append('email', email);

        fetch('student_register.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                showNotifier(data.message, 'success');
                document.getElementById("otpSection").classList.remove("d-none");
                document.getElementById("sendOtpBtn").classList.add("d-none");
                document.getElementById("resendOtpBtn").style.display = 'block';
                startOtpTimer();
            } else {
                showNotifier(data.message, 'error');
                sendOtpBtn.disabled = false;
                sendOtpBtn.textContent = "Send OTP (Email)";
                document.getElementById("resendOtpBtn").disabled = true;
            }
        })
        .catch(error => {
            console.error('Error sending OTP:', error);
            showNotifier('Failed to send OTP. Please try again.', 'error');
            sendOtpBtn.disabled = false;
            sendOtpBtn.textContent = "Send OTP (Email)";
            document.getElementById("resendOtpBtn").disabled = true;
        });
    });

    document.getElementById("resendOtpBtn").addEventListener("click", function() {
        const emailInput = document.getElementById('emailInput');
        const email = emailInput.value;

        if (document.getElementById('timer').textContent !== "OTP expired. Please request a new OTP.") {
            return;
        }

        const resendOtpBtn = this;
        resendOtpBtn.disabled = true;
        resendOtpBtn.textContent = 'Resending OTP...';

        const formData = new FormData();
        formData.append('sendOtp', 'true');
        formData.append('email', email);

        fetch('student_register.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                showNotifier(data.message, 'success');
                startOtpTimer();
            } else {
                showNotifier(data.message, 'error');
                resendOtpBtn.disabled = false;
                resendOtpBtn.textContent = "Resend OTP";
            }
        })
        .catch(error => {
            console.error('Error sending OTP:', error);
            showNotifier('Failed to send OTP. Please try again.', 'error');
            resendOtpBtn.disabled = false;
            resendOtpBtn.textContent = "Resend OTP";
        });
    });

    document.getElementById("verifyOtpBtn").addEventListener("click", function() {
        const enteredOtp = document.getElementById('otp').value;
        const emailForOtpVerification = document.getElementById('emailInput').value;

        if (!enteredOtp) {
            showNotifier('Please enter the OTP.', 'error');
            return;
        }

        const verifyOtpBtn = this;
        verifyOtpBtn.disabled = true;
        verifyOtpBtn.textContent = 'Verifying...';

        const formData = new FormData();
        formData.append('verifyOtp', 'true');
        formData.append('otp', enteredOtp);
        formData.append('email', emailForOtpVerification);

        fetch('student_register.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                showNotifier(data.message, 'success');
                otpVerified = true;
                document.getElementById('otp').disabled = true;
                verifyOtpBtn.classList.add('btn-success');
                verifyOtpBtn.textContent = 'Verified!';
                verifyOtpBtn.disabled = true;
                clearInterval(countdown);
                document.getElementById('timer').textContent = '';
                document.getElementById('resendOtpBtn').style.display = 'none';
                document.getElementById('nextStep5Btn').disabled = false;
                document.getElementById('emailInput').disabled = true;
                document.getElementById('emailInput').classList.add('verified-email');
            } else {
                showNotifier(data.message, 'error');
                verifyOtpBtn.disabled = false;
                verifyOtpBtn.textContent = "Verify OTP";
                otpVerified = false;
            }
        })
        .catch(error => {
            console.error('Error verifying OTP:', error);
            showNotifier('Failed to verify OTP. Please try again.', 'error');
            verifyOtpBtn.disabled = false;
            verifyOtpBtn.textContent = "Verify OTP";
            otpVerified = false;
        });
    });
}

function startOtpTimer() {
    let timeLeft = 40;
    clearInterval(countdown);
    document.getElementById('timer').textContent = `Time left: ${timeLeft} seconds`;

    countdown = setInterval(function() {
        timeLeft--;
        document.getElementById('timer').textContent = `Time left: ${timeLeft} seconds`;

        if (timeLeft <= 0) {
            clearInterval(countdown);
            document.getElementById('timer').textContent = "OTP expired. Please request a new OTP.";
            document.getElementById('otp').disabled = false;
            document.getElementById('verifyOtpBtn').disabled = false;
            document.getElementById('verifyOtpBtn').textContent = 'Verify OTP';
            document.getElementById('verifyOtpBtn').classList.remove('btn-success');
            document.getElementById('resendOtpBtn').disabled = false;
            document.getElementById('resendOtpBtn').style.display = 'block';
            document.getElementById('sendOtpBtn').classList.add('d-none');
            otpVerified = false;
            document.getElementById('nextStep5Btn').disabled = true;
        }
    }, 1000);
}

// ---- PASSWORD STRENGTH FUNCTIONS ----

function initializePasswordHandlers() {
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirmPassword');
    const strengthBar = document.getElementById('strengthBar');
    const strengthText = document.getElementById('strengthText');

    function updatePasswordStrength() {
        const password = passwordInput.value;
        let strength = 0;

        if (password.length >= 12) strength += 25;
        if (/[A-Z]/.test(password)) strength += 25;
        if (/[a-z]/.test(password)) strength += 25;
        if (/[0-9]/.test(password)) strength += 25;
        if (/[^A-Za-z0-9]/.test(password)) strength += 25;

        strength = Math.min(strength, 100);

        strengthBar.style.width = strength + '%';
        strengthBar.className = 'progress-bar';

        if (strength < 50) {
            strengthBar.classList.add('bg-danger');
            strengthText.textContent = 'Weak';
        } else if (strength < 75) {
            strengthBar.classList.add('bg-warning');
            strengthText.textContent = 'Medium';
        } else {
            strengthBar.classList.add('bg-success');
            strengthText.textContent = 'Strong';
        }

        if (password.length === 0) {
            strengthBar.style.width = '0%';
            strengthText.textContent = '';
        }
    }

    passwordInput.addEventListener('input', updatePasswordStrength);

    confirmPasswordInput.addEventListener('input', function() {
        if (passwordInput.value !== confirmPasswordInput.value) {
            confirmPasswordInput.setCustomValidity('Passwords do not match');
        } else {
            confirmPasswordInput.setCustomValidity('');
        }
    });
}

// ---- DOCUMENT UPLOAD AND OCR FUNCTIONS ----

function validateFilename(filename, firstName, lastName) {
    // Remove file extension for validation
    const nameWithoutExt = filename.replace(/\.[^/.]+$/, '');
    
    // Expected format: Lastname_Firstname_EAF
    const expectedFormat = `${lastName}_${firstName}_EAF`;
    
    // Case-insensitive comparison
    return nameWithoutExt.toLowerCase() === expectedFormat.toLowerCase();
}

function updateProcessButtonState() {
    const processBtn = document.getElementById('processOcrBtn');
    const fileInput = document.getElementById('enrollmentForm');
    
    if (fileInput.files.length > 0 && filenameValid) {
        processBtn.disabled = false;
    } else {
        processBtn.disabled = true;
    }
}

function initializeDocumentHandlers() {
    document.getElementById('enrollmentForm').addEventListener('change', function(e) {
        const file = e.target.files[0];
        const filenameError = document.getElementById('filenameError');
        
        if (file) {
            // Get form data for filename validation
            const firstName = document.querySelector('input[name="first_name"]').value.trim();
            const lastName = document.querySelector('input[name="last_name"]').value.trim();
            
            if (!firstName || !lastName) {
                showNotifier('Please fill in your first and last name first.', 'error');
                this.value = '';
                return;
            }
            
            // Validate filename format
            filenameValid = validateFilename(file.name, firstName, lastName);
            
            if (!filenameValid) {
                filenameError.style.display = 'block';
                filenameError.innerHTML = `
                    <small><i class="bi bi-exclamation-triangle me-1"></i>
                    Filename must be: <strong>${lastName}_${firstName}_EAF.${file.name.split('.').pop()}</strong>
                    </small>
                `;
                document.getElementById('uploadPreview').classList.add('d-none');
                document.getElementById('ocrSection').classList.add('d-none');
            } else {
                filenameError.style.display = 'none';
                
                const previewContainer = document.getElementById('uploadPreview');
                const previewImage = document.getElementById('previewImage');
                const pdfPreview = document.getElementById('pdfPreview');
                
                previewContainer.classList.remove('d-none');
                document.getElementById('ocrSection').classList.remove('d-none');
                
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        previewImage.src = e.target.result;
                        previewImage.style.display = 'block';
                        pdfPreview.style.display = 'none';
                    };
                    reader.readAsDataURL(file);
                } else if (file.type === 'application/pdf') {
                    previewImage.style.display = 'none';
                    pdfPreview.style.display = 'block';
                }
            }
            
            // Reset verification status
            documentVerified = false;
            document.getElementById('nextStep4Btn').disabled = true;
            document.getElementById('ocrResults').classList.add('d-none');
            updateProcessButtonState();
        } else {
            filenameError.style.display = 'none';
            filenameValid = false;
            updateProcessButtonState();
        }
    });

    document.getElementById('processOcrBtn').addEventListener('click', function() {
        const fileInput = document.getElementById('enrollmentForm');
        const file = fileInput.files[0];
        
        if (!file) {
            showNotifier('Please select a file first.', 'error');
            return;
        }

        if (!filenameValid) {
            showNotifier('Please rename your file to follow the required format: Lastname_Firstname_EAF', 'error');
            return;
        }

        const processBtn = this;
        processBtn.disabled = true;
        processBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Processing...';
        
        // Get form data for verification
        const formData = new FormData();
        formData.append('processOcr', 'true');
        formData.append('enrollment_form', file);
        formData.append('first_name', document.querySelector('input[name="first_name"]').value);
        formData.append('middle_name', document.querySelector('input[name="middle_name"]').value);
        formData.append('last_name', document.querySelector('input[name="last_name"]').value);
        formData.append('university_id', document.querySelector('select[name="university_id"]').value);
        formData.append('year_level_id', document.querySelector('select[name="year_level_id"]').value);

        fetch('student_register.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            processBtn.disabled = false;
            processBtn.innerHTML = '<i class="bi bi-search me-2"></i>Process Document';
            
            if (data.status === 'success') {
                displayVerificationResults(data.verification);
            } else {
                showNotifier(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error processing OCR:', error);
            showNotifier('Failed to process document. Please try again.', 'error');
            processBtn.disabled = false;
            processBtn.innerHTML = '<i class="bi bi-search me-2"></i>Process Document';
        });
    });
}

function displayVerificationResults(verification) {
    const resultsContainer = document.getElementById('ocrResults');
    const feedbackContainer = document.getElementById('ocrFeedback');
    
    resultsContainer.classList.remove('d-none');
    
    // Update checklist items
    const checks = ['firstname', 'middlename', 'lastname', 'yearlevel', 'university', 'document'];
    const checkMap = {
        'firstname': 'first_name',
        'middlename': 'middle_name', 
        'lastname': 'last_name',
        'yearlevel': 'year_level',
        'university': 'university',
        'document': 'document_keywords'
    };
    
    checks.forEach(check => {
        const element = document.getElementById(`check-${check}`);
        const icon = element.querySelector('i');
        const isValid = verification[checkMap[check]];
        
        if (isValid) {
            icon.className = 'bi bi-check-circle text-success me-2';
        } else {
            icon.className = 'bi bi-x-circle text-danger me-2';
        }
    });
    
    if (verification.overall_success) {
        feedbackContainer.style.display = 'none';
        feedbackContainer.className = 'alert alert-success mt-3';
        feedbackContainer.innerHTML = '<strong>Verification Successful!</strong> Your document has been validated.';
        feedbackContainer.style.display = 'block';
        documentVerified = true;
        document.getElementById('nextStep4Btn').disabled = false;
        showNotifier('Document verification successful!', 'success');
    } else {
        feedbackContainer.style.display = 'none';
        feedbackContainer.className = 'alert alert-warning mt-3';
        feedbackContainer.innerHTML = '<strong>Verification Failed:</strong> Please ensure your document is clear and contains all required information. Upload a clearer image or check that the document matches your registration details.';
        feedbackContainer.style.display = 'block';
        documentVerified = false;
        document.getElementById('nextStep4Btn').disabled = true;
        showNotifier('Document verification failed. Please try again with a clearer document.', 'error');
    }
}

// ---- FORM SUBMISSION HANDLER ----

function initializeFormSubmission() {
    document.getElementById('multiStepForm').addEventListener('submit', function(e) {
        if (currentStep !== 6) {
            e.preventDefault();
            showNotifier('Please complete all steps first.', 'error');
            return;
        }
        // Show all panels and enable all fields for browser validation
        document.querySelectorAll('.step-panel').forEach(panel => {
            panel.classList.remove('d-none');
            panel.style.display = '';
        });
        document.querySelectorAll('input, select, textarea').forEach(el => {
            el.disabled = false;
        });
    });
}

// ---- INITIALIZATION FUNCTION ----

function initializeNameFieldListeners() {
    // Add listeners to name fields to re-validate filename if changed
    document.querySelector('input[name="first_name"]').addEventListener('input', function() {
        if (document.getElementById('enrollmentForm').files.length > 0) {
            // Trigger filename re-validation if file is already selected
            const event = new Event('change');
            document.getElementById('enrollmentForm').dispatchEvent(event);
        }
    });
    
    document.querySelector('input[name="last_name"]').addEventListener('input', function() {
        if (document.getElementById('enrollmentForm').files.length > 0) {
            // Trigger filename re-validation if file is already selected
            const event = new Event('change');
            document.getElementById('enrollmentForm').dispatchEvent(event);
        }
    });
}

function addVerificationStyles() {
    // Add CSS for verification checklist
    const style = document.createElement('style');
    style.textContent = `
        .verification-checklist .form-check {
            display: flex;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .verification-checklist .form-check:last-child {
            border-bottom: none;
        }
        .verification-checklist .form-check span {
            font-size: 14px;
        }
    `;
    document.head.appendChild(style);
}

// ---- TERMS AND CONDITIONS HANDLING ----
function initializeTermsAndConditions() {
    const showTermsBtn = document.getElementById('showTermsBtn');
    const termsModalElement = document.getElementById('termsModal');
    const termsContent = document.getElementById('termsContent');
    const acceptTermsBtn = document.getElementById('acceptTermsBtn');
    const agreeTerms = document.getElementById('agreeTerms');
    const submitBtn = document.getElementById('submitBtn');
    const scrollIndicator = document.getElementById('scrollIndicator');

    // Initialize Bootstrap Modal
    const termsModal = new bootstrap.Modal(termsModalElement);

    // Show terms modal when button is clicked
    showTermsBtn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        // Reset scroll tracking when modal opens
        termsRead = false;
        termsAccepted = false;
        acceptTermsBtn.disabled = true;
        scrollIndicator.style.display = 'block';
        acceptTermsBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Please read all terms first';
        
        // Show the modal
        termsModal.show();
    });

    // Track scrolling to ensure user reads all terms
    termsContent.addEventListener('scroll', function() {
        const scrollTop = termsContent.scrollTop;
        const scrollHeight = termsContent.scrollHeight;
        const clientHeight = termsContent.clientHeight;
        
        // Calculate scroll percentage
        const scrollPercentage = (scrollTop + clientHeight) / scrollHeight;
        
        // Update scroll indicator
        if (scrollPercentage < 0.95) {
            scrollIndicator.innerHTML = `
                <i class="bi bi-arrow-down me-2"></i>
                Please continue reading - ${Math.round(scrollPercentage * 100)}% completed
            `;
            scrollIndicator.className = 'alert alert-warning';
        } else {
            scrollIndicator.innerHTML = `
                <i class="bi bi-check-circle me-2"></i>
                Thank you for reading all terms and conditions
            `;
            scrollIndicator.className = 'alert alert-success';
            
            // Enable accept button when fully scrolled
            if (!termsRead) {
                termsRead = true;
                acceptTermsBtn.disabled = false;
                acceptTermsBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>I Accept Terms and Conditions';
                
                // Add visual feedback
                acceptTermsBtn.classList.add('btn-pulse');
                setTimeout(() => {
                    acceptTermsBtn.classList.remove('btn-pulse');
                }, 2000);
            }
        }
    });

    // Handle terms acceptance
    acceptTermsBtn.addEventListener('click', function() {
        if (!termsRead) {
            showNotifier('Please read all terms and conditions first.', 'error');
            return;
        }

        termsAccepted = true;
        agreeTerms.checked = true;
        agreeTerms.disabled = false;
        
        // Update status
        const termsStatus = document.getElementById('termsStatus');
        termsStatus.innerHTML = '<small class="text-success"><i class="bi bi-check-circle me-1"></i>Terms and conditions accepted</small>';
        
        // Enable submit button if password requirements are met
        checkSubmitButtonStatus();
        
        // Close modal
        termsModal.hide();
        
        showNotifier('Terms and conditions accepted successfully!', 'success');
    });

    // Prevent manual checkbox checking
    agreeTerms.addEventListener('click', function(e) {
        if (!termsAccepted) {
            e.preventDefault();
            showNotifier('Please read and accept the terms and conditions first.', 'error');
            return false;
        }
    });

    // Monitor password strength for submit button
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirmPassword');
    
    [passwordInput, confirmPasswordInput].forEach(input => {
        if (input) {
            input.addEventListener('input', checkSubmitButtonStatus);
        }
    });
}

function checkSubmitButtonStatus() {
    const submitBtn = document.getElementById('submitBtn');
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirmPassword');
    const agreeTerms = document.getElementById('agreeTerms');
    
    const passwordValid = passwordInput.value.length >= 12;
    const passwordsMatch = passwordInput.value === confirmPasswordInput.value && confirmPasswordInput.value.length > 0;
    const termsAgreed = termsAccepted && agreeTerms.checked;
    
    const canSubmit = passwordValid && passwordsMatch && termsAgreed;
    
    submitBtn.disabled = !canSubmit;
    
    if (canSubmit) {
        submitBtn.classList.remove('btn-secondary');
        submitBtn.classList.add('btn-success');
        submitBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Submit Registration';
    } else {
        submitBtn.classList.remove('btn-success');
        submitBtn.classList.add('btn-secondary');
        submitBtn.innerHTML = 'Submit';
    }
}

// ---- PHILIPPINES PHONE NUMBER VALIDATION ----
function initializePhoneValidation() {
    const phoneInput = document.getElementById('phoneInput');
    const phoneValidation = document.getElementById('phoneValidation');
    
    if (!phoneInput) {
        console.warn('Phone input not found');
        return;
    }
    
    // Real-time input validation
    phoneInput.addEventListener('input', function(e) {
        let value = e.target.value;
        
        // Remove all non-digit characters
        value = value.replace(/\D/g, '');
        
        // Ensure it starts with 9
        if (value.length > 0 && value[0] !== '9') {
            value = '9' + value.replace(/9/g, '').substring(0, 9);
        }
        
        // Limit to 10 digits
        if (value.length > 10) {
            value = value.substring(0, 10);
        }
        
        // Update the input value
        e.target.value = value;
        
        // Show validation feedback
        showPhoneValidation(value, phoneValidation);
    });
    
    // Prevent pasting invalid content
    phoneInput.addEventListener('paste', function(e) {
        e.preventDefault();
        
        // Get pasted content
        const paste = (e.clipboardData || window.clipboardData).getData('text');
        
        // Clean the pasted content
        let cleanValue = paste.replace(/\D/g, '');
        
        // Ensure it starts with 9
        if (cleanValue.length > 0 && cleanValue[0] !== '9') {
            cleanValue = '9' + cleanValue.replace(/9/g, '').substring(0, 9);
        }
        
        // Limit to 10 digits
        if (cleanValue.length > 10) {
            cleanValue = cleanValue.substring(0, 10);
        }
        
        // Set the cleaned value
        this.value = cleanValue;
        
        // Show validation feedback
        showPhoneValidation(cleanValue, phoneValidation);
    });
    
    // Prevent keyboard input of letters and special characters
    phoneInput.addEventListener('keypress', function(e) {
        // Allow backspace, delete, tab, escape, enter
        if ([8, 9, 27, 13, 46].indexOf(e.keyCode) !== -1 ||
            // Allow Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
            (e.keyCode === 65 && e.ctrlKey === true) ||
            (e.keyCode === 67 && e.ctrlKey === true) ||
            (e.keyCode === 86 && e.ctrlKey === true) ||
            (e.keyCode === 88 && e.ctrlKey === true)) {
            return;
        }
        
        // Ensure that it is a number and stop the keypress
        if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
            e.preventDefault();
        }
        
        // If input already has 10 digits, prevent more input
        if (this.value.length >= 10) {
            e.preventDefault();
        }
        
        // If first digit is not 9, and we're typing at position 0
        if (this.selectionStart === 0 && e.key !== '9' && this.value.length === 0) {
            e.preventDefault();
        }
    });
    
    // Handle focus out validation
    phoneInput.addEventListener('blur', function() {
        const value = this.value;
        if (value.length > 0 && value.length < 10) {
            showPhoneValidation(value, phoneValidation);
        }
    });
}

function showPhoneValidation(phoneNumber, validationElement) {
    if (!validationElement) return;
    
    if (phoneNumber.length === 0) {
        validationElement.innerHTML = '';
        return;
    }
    
    if (phoneNumber.length < 10) {
        validationElement.innerHTML = `
            <small class="text-warning">
                <i class="bi bi-exclamation-triangle me-1"></i>
                Phone number must be exactly 10 digits (${phoneNumber.length}/10)
            </small>
        `;
        return;
    }
    
    if (phoneNumber.length === 10 && phoneNumber[0] === '9') {
        // Validate against Philippines mobile prefixes
        const prefix = phoneNumber.substring(0, 3);
        const validPrefixes = [
            // Globe/TM
            '905', '906', '915', '916', '917', '925', '926', '927', '935', '936', '937',
            '945', '953', '954', '955', '956', '965', '966', '967', '975', '976', '977',
            '978', '979', '994', '995', '996', '997',
            // Smart/TNT  
            '908', '909', '910', '911', '912', '913', '914', '918', '919', '920', '921',
            '928', '929', '930', '938', '939', '946', '947', '948', '949', '950', '951',
            '998', '999',
            // Sun Cellular
            '922', '923', '924', '931', '932', '933', '934', '940', '941', '942', '943',
            // DITO
            '895', '896', '897', '898',
            // Cherry Mobile
            '992', '993'
        ];
        
        if (validPrefixes.includes(prefix)) {
            let provider = 'Unknown';
            if (['905', '906', '915', '916', '917', '925', '926', '927', '935', '936', '937', '945', '953', '954', '955', '956', '965', '966', '967', '975', '976', '977', '978', '979', '994', '995', '996', '997'].includes(prefix)) {
                provider = 'Globe/TM';
            } else if (['908', '909', '910', '911', '912', '913', '914', '918', '919', '920', '921', '928', '929', '930', '938', '939', '946', '947', '948', '949', '950', '951', '998', '999'].includes(prefix)) {
                provider = 'Smart/TNT';
            } else if (['922', '923', '924', '931', '932', '933', '934', '940', '941', '942', '943'].includes(prefix)) {
                provider = 'Sun Cellular';
            } else if (['895', '896', '897', '898'].includes(prefix)) {
                provider = 'DITO';
            } else if (['992', '993'].includes(prefix)) {
                provider = 'Cherry Mobile';
            }
            
            validationElement.innerHTML = `
                <small class="text-success">
                    <i class="bi bi-check-circle me-1"></i>
                    Valid ${provider} number (+63${phoneNumber})
                </small>
            `;
        } else {
            validationElement.innerHTML = `
                <small class="text-danger">
                    <i class="bi bi-x-circle me-1"></i>
                    Invalid Philippines mobile number prefix (${prefix})
                </small>
            `;
        }
    } else {
        validationElement.innerHTML = `
            <small class="text-danger">
                <i class="bi bi-x-circle me-1"></i>
                Invalid phone number format
            </small>
        `;
    }
}

// ---- MAIN INITIALIZATION ----
document.addEventListener('DOMContentLoaded', () => {
    // Initialize the registration form
    showStep(1);
    updateRequiredFields();
    
    // Disable next button for step 5 initially
    document.getElementById('nextStep5Btn').disabled = true;
    document.getElementById('nextStep5Btn').addEventListener('click', nextStep);
    
    // Initialize all handlers
    initializeOTPHandlers();
    initializePasswordHandlers();
    initializeDocumentHandlers();
    initializeFormSubmission();
    initializeNameFieldListeners();
    initializeTermsAndConditions();
    addVerificationStyles();
    initializePhoneValidation();
    
    console.log('Student registration system initialized successfully');
});