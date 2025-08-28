// Configuration constants
const CONFIG = {
    STORAGE_KEY: 'educaid_registration_progress',
    AUTO_SAVE_INTERVAL: 5000, // Auto-save every 5 seconds
    STORAGE_EXPIRY: 24 * 60 * 60 * 1000, // 24 hours in milliseconds
    VERSION: '1.0' // For handling future format changes
};

// Enhanced state variables
let countdown;
let currentStep = 1;
let otpVerified = false;
let documentVerified = false;
let filenameValid = false;
let hasUnsavedChanges = false;
let autoSaveTimer = null;

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
    
    // Save progress when changing steps
    if (stepNumber > 1) {
        hasUnsavedChanges = true;
        saveProgress();
    }
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
    
    // Save progress after successful step change
    hasUnsavedChanges = true;
    saveProgress();
}

function prevStep() {
    if (currentStep > 1) {
        showStep(currentStep - 1);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // Check for existing progress
    const saved = localStorage.getItem(CONFIG.STORAGE_KEY);
    if (saved) {
        try {
            const progress = JSON.parse(saved);
            if (Date.now() - progress.timestamp <= CONFIG.STORAGE_EXPIRY) {
                showProgressRestoreDialog();
            } else {
                clearProgress();
                initializeForm();
            }
        } catch (error) {
            clearProgress();
            initializeForm();
        }
    } else {
        initializeForm();
    }
    
    function initializeForm() {
        showStep(1);
        updateRequiredFields();
        document.getElementById('nextStep5Btn').disabled = true;
        document.getElementById('nextStep5Btn').addEventListener('click', nextStep);
        
        // Setup auto-save functionality
        setupAutoSave();
        
        // Add listeners to name fields to re-validate filename if changed
        document.querySelector('input[name="first_name"]').addEventListener('input', function() {
            hasUnsavedChanges = true;
            if (document.getElementById('enrollmentForm').files.length > 0) {
                const event = new Event('change');
                document.getElementById('enrollmentForm').dispatchEvent(event);
            }
        });

        document.querySelector('input[name="last_name"]').addEventListener('input', function() {
            hasUnsavedChanges = true;
            if (document.getElementById('enrollmentForm').files.length > 0) {
                const event = new Event('change');
                document.getElementById('enrollmentForm').dispatchEvent(event);
            }
        });
    }
});

// ---- OTP BUTTON HANDLING ----

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

// ----- FIX FOR REQUIRED FIELD ERROR -----
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

// ----- DOCUMENT UPLOAD AND OCR FUNCTIONALITY -----

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

// ========================================
// SAVE STATE FUNCTIONALITY
// ========================================

function saveProgress() {
    try {
        const formData = new FormData(document.getElementById('multiStepForm'));
        const progress = {
            version: CONFIG.VERSION,
            timestamp: Date.now(),
            currentStep: currentStep,
            otpVerified: otpVerified,
            documentVerified: documentVerified,
            filenameValid: filenameValid,
            formFields: {},
            fileInfo: null,
            specialStates: {}
        };

        // Save form field values
        for (let [key, value] of formData.entries()) {
            // Skip file inputs as they can't be restored
            if (key !== 'enrollment_form') {
                progress.formFields[key] = value;
            }
        }

        // Save file information (not the actual file)
        const fileInput = document.getElementById('enrollmentForm');
        if (fileInput.files.length > 0) {
            const file = fileInput.files[0];
            progress.fileInfo = {
                name: file.name,
                size: file.size,
                type: file.type,
                lastModified: file.lastModified
            };
        }

        // Save special UI states
        progress.specialStates = {
            otpSectionVisible: !document.getElementById("otpSection").classList.contains("d-none"),
            emailDisabled: document.getElementById('emailInput').disabled,
            sendOtpBtnHidden: document.getElementById("sendOtpBtn").classList.contains("d-none"),
            verifyBtnSuccess: document.getElementById("verifyOtpBtn").classList.contains("btn-success"),
            nextStep5BtnEnabled: !document.getElementById('nextStep5Btn').disabled,
            uploadPreviewVisible: !document.getElementById('uploadPreview').classList.contains('d-none'),
            ocrSectionVisible: !document.getElementById('ocrSection').classList.contains('d-none'),
            ocrResultsVisible: !document.getElementById('ocrResults').classList.contains('d-none')
        };

        localStorage.setItem(CONFIG.STORAGE_KEY, JSON.stringify(progress));
        hasUnsavedChanges = false;
        console.log('Progress saved successfully');
        
        // Show brief save indicator
        showSaveIndicator('Saved');
        
    } catch (error) {
        console.error('Error saving progress:', error);
        showSaveIndicator('Save failed', 'error');
    }
}

function loadProgress() {
    try {
        const saved = localStorage.getItem(CONFIG.STORAGE_KEY);
        if (!saved) return false;

        const progress = JSON.parse(saved);

        // Check version compatibility
        if (progress.version !== CONFIG.VERSION) {
            console.log('Save version mismatch, clearing old data');
            clearProgress();
            return false;
        }

        // Check if progress is still valid (not expired)
        if (Date.now() - progress.timestamp > CONFIG.STORAGE_EXPIRY) {
            console.log('Saved progress expired, clearing data');
            clearProgress();
            return false;
        }

        // Restore state variables
        currentStep = progress.currentStep || 1;
        otpVerified = progress.otpVerified || false;
        documentVerified = progress.documentVerified || false;
        filenameValid = progress.filenameValid || false;

        // Restore form field values
        Object.entries(progress.formFields).forEach(([key, value]) => {
            const field = document.querySelector(`[name="${key}"]`);
            if (field) {
                if (field.type === 'radio') {
                    const radioButton = document.querySelector(`[name="${key}"][value="${value}"]`);
                    if (radioButton) radioButton.checked = true;
                } else if (field.type === 'checkbox') {
                    field.checked = value === 'on' || value === true;
                } else {
                    field.value = value;
                }
            }
        });

        // Restore special UI states
        if (progress.specialStates) {
            const states = progress.specialStates;
            
            if (states.otpSectionVisible) {
                document.getElementById("otpSection").classList.remove("d-none");
            }
            
            if (states.emailDisabled) {
                document.getElementById('emailInput').disabled = true;
                document.getElementById('emailInput').classList.add('verified-email');
            }
            
            if (states.sendOtpBtnHidden) {
                document.getElementById("sendOtpBtn").classList.add("d-none");
                document.getElementById("resendOtpBtn").style.display = 'block';
            }
            
            if (states.verifyBtnSuccess) {
                const verifyBtn = document.getElementById("verifyOtpBtn");
                verifyBtn.classList.add('btn-success');
                verifyBtn.textContent = 'Verified!';
                verifyBtn.disabled = true;
                document.getElementById('otp').disabled = true;
            }
            
            if (states.nextStep5BtnEnabled) {
                document.getElementById('nextStep5Btn').disabled = false;
            }
            
            if (states.uploadPreviewVisible && progress.fileInfo) {
                document.getElementById('uploadPreview').classList.remove('d-none');
                // Show file info message since we can't restore the actual file
                showFileRestoreMessage(progress.fileInfo);
            }
            
            if (states.ocrSectionVisible) {
                document.getElementById('ocrSection').classList.remove('d-none');
            }
            
            if (states.ocrResultsVisible) {
                document.getElementById('ocrResults').classList.remove('d-none');
                // Enable next button if document was verified
                if (documentVerified) {
                    document.getElementById('nextStep4Btn').disabled = false;
                }
            }
        }

        // Show the correct step
        showStep(currentStep);
        
        // Update password strength if password was restored
        if (document.getElementById('password').value) {
            updatePasswordStrength();
        }

        console.log('Progress loaded successfully');
        showSaveIndicator('Progress restored', 'success');
        
        return true;
        
    } catch (error) {
        console.error('Error loading progress:', error);
        clearProgress();
        return false;
    }
}

function clearProgress() {
    localStorage.removeItem(CONFIG.STORAGE_KEY);
    hasUnsavedChanges = false;
    console.log('Progress cleared');
}

function showFileRestoreMessage(fileInfo) {
    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-info mt-2';
    alertDiv.innerHTML = `
        <i class="bi bi-info-circle me-2"></i>
        <strong>File was previously selected:</strong> ${fileInfo.name}<br>
        <small>Please re-upload your file to continue with document verification.</small>
        <button type="button" class="btn-close float-end" data-bs-dismiss="alert"></button>
    `;
    
    const uploadSection = document.getElementById('enrollmentForm').closest('.mb-3');
    uploadSection.appendChild(alertDiv);
    
    // Auto-remove after 10 seconds
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.remove();
        }
    }, 10000);
}

function showSaveIndicator(message, type = 'success') {
    // Create or update save indicator
    let indicator = document.getElementById('saveIndicator');
    if (!indicator) {
        indicator = document.createElement('div');
        indicator.id = 'saveIndicator';
        indicator.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 8px 16px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            z-index: 9999;
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        `;
        document.body.appendChild(indicator);
    }
    
    // Set colors based on type
    if (type === 'error') {
        indicator.style.backgroundColor = '#dc3545';
        indicator.style.color = 'white';
    } else {
        indicator.style.backgroundColor = '#28a745';
        indicator.style.color = 'white';
    }
    
    indicator.textContent = message;
    indicator.style.opacity = '1';
    
    setTimeout(() => {
        indicator.style.opacity = '0';
    }, 2000);
}

function setupAutoSave() {
    // Clear existing timer
    if (autoSaveTimer) {
        clearInterval(autoSaveTimer);
    }
    
    // Set up auto-save timer
    autoSaveTimer = setInterval(() => {
        if (hasUnsavedChanges) {
            saveProgress();
        }
    }, CONFIG.AUTO_SAVE_INTERVAL);
    
    // Save on form changes
    const form = document.getElementById('multiStepForm');
    form.addEventListener('input', () => {
        hasUnsavedChanges = true;
    });
    
    form.addEventListener('change', () => {
        hasUnsavedChanges = true;
    });
    
    // Save before page unload
    window.addEventListener('beforeunload', (e) => {
        if (hasUnsavedChanges && currentStep > 1 && currentStep < 6) {
            saveProgress();
            e.preventDefault();
            e.returnValue = 'You have unsaved registration progress. Are you sure you want to leave?';
        }
    });
    
    // Save when visibility changes (mobile apps, tab switching)
    document.addEventListener('visibilitychange', () => {
        if (document.hidden && hasUnsavedChanges) {
            saveProgress();
        }
    });
}

function showProgressRestoreDialog() {
    // Check if Bootstrap is available
    if (typeof bootstrap === 'undefined') {
        // Fallback to simple confirm dialog
        const restore = confirm(
            'We found your previous registration progress.\n\n' +
            'Click OK to continue where you left off, or Cancel to start fresh.'
        );
        
        if (restore) {
            if (loadProgress()) {
                showNotifier('Previous progress restored successfully!', 'success');
            }
        } else {
            clearProgress();
            showNotifier('Starting fresh registration', 'success');
        }
        return;
    }

    // Original Bootstrap modal code
    const modal = document.createElement('div');
    modal.className = 'modal fade';
    modal.id = 'restoreProgressModal';
    modal.innerHTML = `
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-clock-history me-2"></i>Previous Progress Found
                    </h5>
                </div>
                <div class="modal-body">
                    <p>We found your previous registration progress. Would you like to continue where you left off?</p>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary flex-fill" id="restoreProgressBtn">
                            <i class="bi bi-arrow-clockwise me-2"></i>Continue Previous
                        </button>
                        <button type="button" class="btn btn-outline-secondary flex-fill" id="startFreshBtn">
                            <i class="bi bi-arrow-counterclockwise me-2"></i>Start Fresh
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    const bootstrapModal = new bootstrap.Modal(modal);
    bootstrapModal.show();
    
    document.getElementById('restoreProgressBtn').addEventListener('click', () => {
        if (loadProgress()) {
            showNotifier('Previous progress restored successfully!', 'success');
        }
        bootstrapModal.hide();
        modal.remove();
    });
    
    document.getElementById('startFreshBtn').addEventListener('click', () => {
        clearProgress();
        showNotifier('Starting fresh registration', 'success');
        bootstrapModal.hide();
        modal.remove();
    });
    
    modal.addEventListener('hidden.bs.modal', () => {
        modal.remove();
    });
}

