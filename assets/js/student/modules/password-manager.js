/**
 * Password Strength Management Module
 */
export class PasswordManager {
    constructor() {
        this.init();
    }

    init() {
        this.bindEvents();
    }

    bindEvents() {
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirmPassword');

        passwordInput?.addEventListener('input', () => this.updatePasswordStrength());
        confirmPasswordInput?.addEventListener('input', () => this.validatePasswordMatch());
    }

    updatePasswordStrength() {
        const passwordInput = document.getElementById('password');
        const strengthBar = document.getElementById('strengthBar');
        const strengthText = document.getElementById('strengthText');
        const password = passwordInput.value;
        
        const strength = this.calculateStrength(password);
        const { color, text } = this.getStrengthDisplay(strength);

        strengthBar.style.width = strength + '%';
        strengthBar.className = `progress-bar ${color}`;
        strengthText.textContent = text;

        if (password.length === 0) {
            strengthBar.style.width = '0%';
            strengthText.textContent = '';
        }
    }

    calculateStrength(password) {
        let strength = 0;

        if (password.length >= 12) strength += 25;
        if (/[A-Z]/.test(password)) strength += 25;
        if (/[a-z]/.test(password)) strength += 25;
        if (/[0-9]/.test(password)) strength += 25;
        if (/[^A-Za-z0-9]/.test(password)) strength += 25;

        return Math.min(strength, 100);
    }

    getStrengthDisplay(strength) {
        if (strength < 50) {
            return { color: 'bg-danger', text: 'Weak' };
        } else if (strength < 75) {
            return { color: 'bg-warning', text: 'Medium' };
        } else {
            return { color: 'bg-success', text: 'Strong' };
        }
    }

    validatePasswordMatch() {
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirmPassword');

        if (passwordInput.value !== confirmPasswordInput.value) {
            confirmPasswordInput.setCustomValidity('Passwords do not match');
        } else {
            confirmPasswordInput.setCustomValidity('');
        }
    }
}