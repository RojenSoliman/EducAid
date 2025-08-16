/**
 * OTP Management Module
 */
export class OTPManager {
    constructor() {
        this.verified = false;
        this.countdown = null;
        this.init();
    }

    init() {
        this.bindEvents();
    }

    bindEvents() {
        document.getElementById("sendOtpBtn")?.addEventListener("click", () => this.sendOTP());
        document.getElementById("resendOtpBtn")?.addEventListener("click", () => this.resendOTP());
        document.getElementById("verifyOtpBtn")?.addEventListener("click", () => this.verifyOTP());
    }

    async sendOTP() {
        const emailInput = document.getElementById('emailInput');
        const email = emailInput.value;

        if (!email || !/\S+@\S+\.\S+/.test(email)) {
            window.notificationManager?.show('Please enter a valid email address before sending OTP.', 'error');
            return;
        }

        const sendOtpBtn = document.getElementById("sendOtpBtn");
        sendOtpBtn.disabled = true;
        sendOtpBtn.textContent = 'Sending OTP...';
        document.getElementById("resendOtpBtn").disabled = true;

        try {
            const response = await this.makeOTPRequest('sendOtp', { email });
            
            if (response.status === 'success') {
                window.notificationManager?.show(response.message, 'success');
                document.getElementById("otpSection").classList.remove("d-none");
                document.getElementById("sendOtpBtn").classList.add("d-none");
                document.getElementById("resendOtpBtn").style.display = 'block';
                this.startTimer();
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            window.notificationManager?.show(error.message || 'Failed to send OTP. Please try again.', 'error');
            sendOtpBtn.disabled = false;
            sendOtpBtn.textContent = "Send OTP (Email)";
            document.getElementById("resendOtpBtn").disabled = true;
        }
    }

    async verifyOTP() {
        const enteredOtp = document.getElementById('otp').value;
        const emailForOtpVerification = document.getElementById('emailInput').value;

        if (!enteredOtp) {
            window.notificationManager?.show('Please enter the OTP.', 'error');
            return;
        }

        const verifyOtpBtn = document.getElementById("verifyOtpBtn");
        verifyOtpBtn.disabled = true;
        verifyOtpBtn.textContent = 'Verifying...';

        try {
            const response = await this.makeOTPRequest('verifyOtp', { 
                otp: enteredOtp, 
                email: emailForOtpVerification 
            });

            if (response.status === 'success') {
                this.handleVerificationSuccess(verifyOtpBtn);
                window.notificationManager?.show(response.message, 'success');
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            window.notificationManager?.show(error.message || 'Failed to verify OTP. Please try again.', 'error');
            verifyOtpBtn.disabled = false;
            verifyOtpBtn.textContent = "Verify OTP";
            this.verified = false;
        }
    }

    async makeOTPRequest(action, data) {
        const formData = new FormData();
        formData.append(action, 'true');
        Object.keys(data).forEach(key => formData.append(key, data[key]));

        const response = await fetch('student_register.php', {
            method: 'POST',
            body: formData
        });

        return response.json();
    }

    handleVerificationSuccess(verifyOtpBtn) {
        this.verified = true;
        document.getElementById('otp').disabled = true;
        verifyOtpBtn.classList.add('btn-success');
        verifyOtpBtn.textContent = 'Verified!';
        verifyOtpBtn.disabled = true;
        clearInterval(this.countdown);
        document.getElementById('timer').textContent = '';
        document.getElementById('resendOtpBtn').style.display = 'none';
        document.getElementById('nextStep5Btn').disabled = false;
        document.getElementById('emailInput').disabled = true;
        document.getElementById('emailInput').classList.add('verified-email');
    }

    startTimer() {
        let timeLeft = 40;
        clearInterval(this.countdown);
        document.getElementById('timer').textContent = `Time left: ${timeLeft} seconds`;

        this.countdown = setInterval(() => {
            timeLeft--;
            document.getElementById('timer').textContent = `Time left: ${timeLeft} seconds`;

            if (timeLeft <= 0) {
                this.handleTimerExpiry();
            }
        }, 1000);
    }

    handleTimerExpiry() {
        clearInterval(this.countdown);
        document.getElementById('timer').textContent = "OTP expired. Please request a new OTP.";
        document.getElementById('otp').disabled = false;
        document.getElementById('verifyOtpBtn').disabled = false;
        document.getElementById('verifyOtpBtn').textContent = 'Verify OTP';
        document.getElementById('verifyOtpBtn').classList.remove('btn-success');
        document.getElementById('resendOtpBtn').disabled = false;
        document.getElementById('resendOtpBtn').style.display = 'block';
        document.getElementById('sendOtpBtn').classList.add('d-none');
        this.verified = false;
        document.getElementById('nextStep5Btn').disabled = true;
    }

    isVerified() {
        return this.verified;
    }
}