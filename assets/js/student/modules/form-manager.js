/**
 * Form Step Management Module
 */
export class FormManager {
    constructor() {
        this.currentStep = 1;
        this.init();
    }

    init() {
        this.showStep(1);
        this.updateRequiredFields();
        this.bindEvents();
    }

    bindEvents() {
        // Handle all navigation buttons using event delegation
        document.addEventListener('click', (e) => {
            if (e.target.dataset.action === 'next') {
                e.preventDefault();
                this.nextStep();
            } else if (e.target.dataset.action === 'prev') {
                e.preventDefault();
                this.prevStep();
            }
        });
    }

    updateRequiredFields() {
        // Disable all required fields initially
        document.querySelectorAll('.step-panel input[required], .step-panel select[required], .step-panel textarea[required]').forEach(el => {
            el.disabled = true;
        });
        // Enable required fields in the visible panel only
        document.querySelectorAll(`#step-${this.currentStep} input[required], #step-${this.currentStep} select[required], #step-${this.currentStep} textarea[required]`).forEach(el => {
            el.disabled = false;
        });
    }

    showStep(stepNumber) {
        document.querySelectorAll('.step-panel').forEach(panel => {
            panel.classList.add('d-none');
        });
        document.getElementById(`step-${stepNumber}`)?.classList.remove('d-none');

        document.querySelectorAll('.step').forEach((step, index) => {
            if (index + 1 === stepNumber) {
                step.classList.add('active');
            } else {
                step.classList.remove('active');
            }
        });
        this.currentStep = stepNumber;
        this.updateRequiredFields();
    }

    nextStep() {
        if (this.currentStep === 6) return;

        if (!this.validateCurrentStep()) return;

        if (this.currentStep === 5) {
            if (!window.otpManager?.isVerified()) {
                window.notificationManager?.show('Please verify your OTP before proceeding.', 'error');
                return;
            }
            this.showStep(this.currentStep + 1);
        } else if (this.currentStep < 6) {
            this.showStep(this.currentStep + 1);
        }
    }

    prevStep() {
        if (this.currentStep > 1) {
            this.showStep(this.currentStep - 1);
        }
    }

    validateCurrentStep() {
        let isValid = true;
        const currentPanel = document.getElementById(`step-${this.currentStep}`);
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
            window.notificationManager?.show('Please fill in all required fields for the current step.', 'error');
        }

        return isValid;
    }

    getCurrentStep() {
        return this.currentStep;
    }
}