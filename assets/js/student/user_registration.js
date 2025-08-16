 let countdown;
        let currentStep = 1;
        let otpVerified = false;

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

        document.addEventListener('DOMContentLoaded', () => {
            showStep(1);
            updateRequiredFields();
            document.getElementById('nextStep5Btn').disabled = true;
            document.getElementById('nextStep5Btn').addEventListener('click', nextStep);
            
            // Initialize all handlers
            initializeTermsAndConditions(); // Add this line
            
            // Add listeners to name fields to re-validate filename if changed
            document.querySelector('input[name="first_name"]').addEventListener('input', function() {
                if (document.getElementById('enrollmentForm').files.length > 0) {
                    const event = new Event('change');
                    document.getElementById('enrollmentForm').dispatchEvent(event);
                }
            });
            
            document.querySelector('input[name="last_name"]').addEventListener('input', function() {
                if (document.getElementById('enrollmentForm').files.length > 0) {
                    const event = new Event('change');
                    document.getElementById('enrollmentForm').dispatchEvent(event);
                }
            });
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
        let documentVerified = false;
        let filenameValid = false;

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

        // ----- TERMS AND CONDITIONS MODAL FUNCTIONALITY ----
        function initializeTermsAndConditions() {
            const termsModal = document.getElementById('termsModal');
            const termsModalBody = document.getElementById('termsModalBody');
            const acceptTermsBtn = document.getElementById('acceptTermsBtn');
            const agreeTermsCheckbox = document.getElementById('agreeTerms');
            const scrollNotice = document.getElementById('scrollNotice');
            const scrollDetector = document.getElementById('scrollDetector');
            
            let hasScrolledToBottom = false;
            
            // Reset modal state when opened
            termsModal.addEventListener('show.bs.modal', function() {
                hasScrolledToBottom = false;
                acceptTermsBtn.disabled = true;
                scrollNotice.style.display = 'block';
                termsModalBody.scrollTop = 0;
            });
            
            // Scroll detection
            termsModalBody.addEventListener('scroll', function() {
                const scrollTop = termsModalBody.scrollTop;
                const scrollHeight = termsModalBody.scrollHeight;
                const clientHeight = termsModalBody.clientHeight;
                
                // Check if user has scrolled to within 10px of the bottom
                if (scrollTop + clientHeight >= scrollHeight - 10) {
                    if (!hasScrolledToBottom) {
                        hasScrolledToBottom = true;
                        acceptTermsBtn.disabled = false;
                        scrollNotice.innerHTML = '<small class="text-success"><i class="bi bi-check-circle me-1"></i>Thank you for reading the terms</small>';
                        
                        // Animate the accept button
                        acceptTermsBtn.classList.add('btn-success');
                        acceptTermsBtn.classList.remove('btn-primary');
                        
                        // Optional: Add a subtle animation
                        acceptTermsBtn.style.animation = 'pulse 0.5s ease-in-out';
                        setTimeout(() => {
                            acceptTermsBtn.style.animation = '';
                        }, 500);
                    }
                }
            });
            
            // Handle accept button click
            acceptTermsBtn.addEventListener('click', function() {
                if (hasScrolledToBottom) {
                    agreeTermsCheckbox.checked = true;
                    agreeTermsCheckbox.disabled = false;
                    
                    // Close modal
                    const modalInstance = bootstrap.Modal.getInstance(termsModal);
                    modalInstance.hide();
                    
                    // Show success message
                    showNotifier('Terms and conditions accepted successfully!', 'success');
                    
                    // Update button text to show accepted state
                    const showTermsBtn = document.getElementById('showTermsBtn');
                    showTermsBtn.innerHTML = 'Terms and Conditions ✓';
                    showTermsBtn.classList.add('text-success');
                }
            });
            
            // Prevent checkbox from being unchecked once accepted
            agreeTermsCheckbox.addEventListener('change', function() {
                if (this.checked && hasScrolledToBottom) {
                    // Keep it checked
                    return;
                } else if (this.checked && !hasScrolledToBottom) {
                    // If somehow checked without reading, uncheck it
                    this.checked = false;
                    showNotifier('Please read the complete terms and conditions first.', 'error');
                    // Show modal again
                    const modalInstance = new bootstrap.Modal(termsModal);
                    modalInstance.show();
                }
            });
        }

        // Add CSS for animations
        const termsStyle = document.createElement('style');
        termsStyle.textContent = `
            @keyframes pulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.05); }
                100% { transform: scale(1); }
            }
            
            .terms-content {
                line-height: 1.6;
                font-size: 14px;
            }
            
            .terms-content h6 {
                color: #495057;
                margin-top: 1.5rem;
                margin-bottom: 0.5rem;
                font-weight: 600;
            }
            
            .terms-content ul {
                margin-bottom: 1rem;
            }
            
            .terms-content li {
                margin-bottom: 0.25rem;
            }
            
            .modal-body {
                max-height: 60vh;
            }
            
            .scroll-notice {
                transition: all 0.3s ease;
            }
            
            .btn-link {
                font-size: inherit;
                vertical-align: baseline;
            }
            
            #acceptTermsBtn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
        `;
        document.head.appendChild(termsStyle);