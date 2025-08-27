/**
 * Document Upload and OCR Management Module
 */
export class DocumentManager {
    constructor() {
        this.verified = false;
        this.filenameValid = false;
        this.init();
    }

    init() {
        this.bindEvents();
    }

    bindEvents() {
        // Name field listeners
        document.querySelector('input[name="first_name"]')?.addEventListener('input', () => this.handleNameChange());
        document.querySelector('input[name="last_name"]')?.addEventListener('input', () => this.handleNameChange());
        
        // File upload listener
        document.getElementById('enrollmentForm')?.addEventListener('change', (e) => this.handleFileUpload(e));
        
        // OCR processing listener
        document.getElementById('processOcrBtn')?.addEventListener('click', () => this.processOCR());
    }

    handleNameChange() {
        const fileInput = document.getElementById('enrollmentForm');
        if (fileInput.files.length > 0) {
            const event = new Event('change');
            fileInput.dispatchEvent(event);
        }
    }

    validateFilename(filename, firstName, lastName) {
        const nameWithoutExt = filename.replace(/\.[^/.]+$/, '');
        const expectedFormat = `${lastName}_${firstName}_EAF`;
        return nameWithoutExt.toLowerCase() === expectedFormat.toLowerCase();
    }

    handleFileUpload(e) {
        const file = e.target.files[0];
        const filenameError = document.getElementById('filenameError');
        
        if (!file) {
            this.resetUploadState();
            return;
        }

        const firstName = document.querySelector('input[name="first_name"]').value.trim();
        const lastName = document.querySelector('input[name="last_name"]').value.trim();
        
        if (!firstName || !lastName) {
            window.notificationManager?.show('Please fill in your first and last name first.', 'error');
            e.target.value = '';
            return;
        }
        
        this.filenameValid = this.validateFilename(file.name, firstName, lastName);
        
        if (!this.filenameValid) {
            this.showFilenameError(filenameError, lastName, firstName, file.name);
        } else {
            this.showFilePreview(file);
            filenameError.style.display = 'none';
        }
        
        this.resetVerificationState();
        this.updateProcessButtonState();
    }

    showFilenameError(errorElement, lastName, firstName, fileName) {
        errorElement.style.display = 'block';
        errorElement.innerHTML = `
            <small><i class="bi bi-exclamation-triangle me-1"></i>
            Filename must be: <strong>${lastName}_${firstName}_EAF.${fileName.split('.').pop()}</strong>
            </small>
        `;
        document.getElementById('uploadPreview')?.classList.add('d-none');
        document.getElementById('ocrSection')?.classList.add('d-none');
    }

    showFilePreview(file) {
        const previewContainer = document.getElementById('uploadPreview');
        const previewImage = document.getElementById('previewImage');
        const pdfPreview = document.getElementById('pdfPreview');
        
        previewContainer?.classList.remove('d-none');
        document.getElementById('ocrSection')?.classList.remove('d-none');
        
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = (e) => {
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

    async processOCR() {
        const fileInput = document.getElementById('enrollmentForm');
        const file = fileInput.files[0];
        
        if (!this.validateOCRRequest(file)) return;

        const processBtn = document.getElementById('processOcrBtn');
        this.setProcessingState(processBtn, true);
        
        try {
            const formData = this.createOCRFormData(file);
            const response = await fetch('student_register.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();
            
            if (data.status === 'success') {
                this.displayVerificationResults(data.verification);
            } else {
                throw new Error(data.message);
            }
        } catch (error) {
            console.error('Error processing OCR:', error);
            window.notificationManager?.show('Failed to process document. Please try again.', 'error');
        } finally {
            this.setProcessingState(processBtn, false);
        }
    }

    validateOCRRequest(file) {
        if (!file) {
            window.notificationManager?.show('Please select a file first.', 'error');
            return false;
        }

        if (!this.filenameValid) {
            window.notificationManager?.show('Please rename your file to follow the required format: Lastname_Firstname_EAF', 'error');
            return false;
        }

        return true;
    }

    createOCRFormData(file) {
        const formData = new FormData();
        formData.append('processOcr', 'true');
        formData.append('enrollment_form', file);
        formData.append('first_name', document.querySelector('input[name="first_name"]').value);
        formData.append('middle_name', document.querySelector('input[name="middle_name"]').value);
        formData.append('last_name', document.querySelector('input[name="last_name"]').value);
        formData.append('university_id', document.querySelector('select[name="university_id"]').value);
        formData.append('year_level_id', document.querySelector('select[name="year_level_id"]').value);
        return formData;
    }

    setProcessingState(button, isProcessing) {
        button.disabled = isProcessing;
        button.innerHTML = isProcessing 
            ? '<i class="bi bi-hourglass-split me-2"></i>Processing...'
            : '<i class="bi bi-search me-2"></i>Process Document';
    }

    displayVerificationResults(verification) {
        const resultsContainer = document.getElementById('ocrResults');
        const feedbackContainer = document.getElementById('ocrFeedback');
        
        resultsContainer?.classList.remove('d-none');
        
        this.updateVerificationChecklist(verification);
        
        if (verification.overall_success) {
            this.handleVerificationSuccess(feedbackContainer);
        } else {
            this.handleVerificationFailure(feedbackContainer);
        }
    }

    updateVerificationChecklist(verification) {
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
            const icon = element?.querySelector('i');
            const isValid = verification[checkMap[check]];
            
            if (icon) {
                icon.className = isValid 
                    ? 'bi bi-check-circle text-success me-2'
                    : 'bi bi-x-circle text-danger me-2';
            }
        });
    }

    handleVerificationSuccess(feedbackContainer) {
        feedbackContainer.style.display = 'none';
        feedbackContainer.className = 'alert alert-success mt-3';
        feedbackContainer.innerHTML = '<strong>Verification Successful!</strong> Your document has been validated.';
        feedbackContainer.style.display = 'block';
        this.verified = true;
        document.getElementById('nextStep4Btn').disabled = false;
        window.notificationManager?.show('Document verification successful!', 'success');
    }

    handleVerificationFailure(feedbackContainer) {
        feedbackContainer.style.display = 'none';
        feedbackContainer.className = 'alert alert-warning mt-3';
        feedbackContainer.innerHTML = '<strong>Verification Failed:</strong> Please ensure your document is clear and contains all required information. Upload a clearer image or check that the document matches your registration details.';
        feedbackContainer.style.display = 'block';
        this.verified = false;
        document.getElementById('nextStep4Btn').disabled = true;
        window.notificationManager?.show('Document verification failed. Please try again with a clearer document.', 'error');
    }

    updateProcessButtonState() {
        const processBtn = document.getElementById('processOcrBtn');
        const fileInput = document.getElementById('enrollmentForm');
        
        if (processBtn) {
            processBtn.disabled = !(fileInput.files.length > 0 && this.filenameValid);
        }
    }

    resetUploadState() {
        document.getElementById('filenameError').style.display = 'none';
        this.filenameValid = false;
        this.updateProcessButtonState();
    }

    resetVerificationState() {
        this.verified = false;
        document.getElementById('nextStep4Btn').disabled = true;
        document.getElementById('ocrResults')?.classList.add('d-none');
    }

    isVerified() {
        return this.verified;
    }
}