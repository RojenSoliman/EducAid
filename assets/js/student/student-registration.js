/**
 * EducAid Student Registration - Main Application
 * Orchestrates all modules and manages application state
 */

import { FormManager } from './modules/form-manager.js';
import { OTPManager } from './modules/otp-manager.js';
import { NotificationManager } from './modules/notification-manager.js';
import { PasswordManager } from './modules/password-manager.js';
import { DocumentManager } from './modules/document-manager.js';

/**
 * Main Application Class
 */
class StudentRegistrationApp {
    constructor() {
        this.modules = {};
        this.init();
    }

    async init() {
        // Wait for DOM to be fully loaded
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.initializeModules());
        } else {
            this.initializeModules();
        }
    }

    initializeModules() {
        // Initialize core modules
        this.modules.notificationManager = new NotificationManager();
        this.modules.formManager = new FormManager();
        this.modules.otpManager = new OTPManager();
        this.modules.passwordManager = new PasswordManager();
        this.modules.documentManager = new DocumentManager();

        // Make modules globally accessible
        window.notificationManager = this.modules.notificationManager;
        window.formManager = this.modules.formManager;
        window.otpManager = this.modules.otpManager;
        window.documentManager = this.modules.documentManager;

        // Initialize remaining features
        this.initializeTermsAndConditions();
        this.initializeFormSubmission();
        this.injectDynamicStyles();

        console.log('EducAid Student Registration App initialized successfully');
    }

    initializeTermsAndConditions() {
        const termsModal = document.getElementById('termsModal');
        const termsModalBody = document.getElementById('termsModalBody');
        const acceptTermsBtn = document.getElementById('acceptTermsBtn');
        const agreeTermsCheckbox = document.getElementById('agreeTerms');
        const scrollNotice = document.getElementById('scrollNotice');
        
        if (!termsModal || !termsModalBody || !acceptTermsBtn) return;
        
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
            
            if (scrollTop + clientHeight >= scrollHeight - 10) {
                if (!hasScrolledToBottom) {
                    hasScrolledToBottom = true;
                    acceptTermsBtn.disabled = false;
                    scrollNotice.innerHTML = '<small class="text-success"><i class="bi bi-check-circle me-1"></i>Thank you for reading the terms</small>';
                    
                    acceptTermsBtn.classList.add('btn-success');
                    acceptTermsBtn.classList.remove('btn-primary');
                    
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
                
                const modalInstance = bootstrap.Modal.getInstance(termsModal);
                modalInstance.hide();
                
                window.notificationManager?.show('Terms and conditions accepted successfully!', 'success');
                
                const showTermsBtn = document.getElementById('showTermsBtn');
                if (showTermsBtn) {
                    showTermsBtn.innerHTML = 'Terms and Conditions ✓';
                    showTermsBtn.classList.add('text-success');
                }
            }
        });
        
        // Prevent checkbox from being unchecked once accepted
        agreeTermsCheckbox?.addEventListener('change', function() {
            if (this.checked && hasScrolledToBottom) {
                return;
            } else if (this.checked && !hasScrolledToBottom) {
                this.checked = false;
                window.notificationManager?.show('Please read the complete terms and conditions first.', 'error');
                const modalInstance = new bootstrap.Modal(termsModal);
                modalInstance.show();
            }
        });
    }

    initializeFormSubmission() {
        const form = document.getElementById('multiStepForm');
        if (!form) return;

        form.addEventListener('submit', (e) => {
            if (this.modules.formManager.getCurrentStep() !== 6) {
                e.preventDefault();
                this.modules.notificationManager.show('Please complete all steps first.', 'error');
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

    injectDynamicStyles() {
        const styles = `
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

        const styleElement = document.createElement('style');
        styleElement.textContent = styles;
        document.head.appendChild(styleElement);
    }
}

// Initialize the application
new StudentRegistrationApp();

// Export for global access if needed
export default StudentRegistrationApp;

