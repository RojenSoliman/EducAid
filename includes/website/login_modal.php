<!-- Login Modal Component -->
<div class="modal fade" id="loginModal" tabindex="-1" aria-labelledby="loginModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0">
        <h5 class="modal-title" id="loginModalLabel">
          <img src="assets/images/educaid-logo.png" alt="EducAid" class="me-2" style="height: 24px;">
          Sign In to EducAid
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        <!-- Login Form -->
        <form id="loginForm" class="auth-form">
          <div class="mb-3">
            <label for="loginEmail" class="form-label">Email Address</label>
            <div class="input-group">
              <span class="input-group-text bg-light border-end-0">
                <i class="bi bi-envelope"></i>
              </span>
              <input type="email" class="form-control border-start-0" id="loginEmail" name="email" required>
            </div>
          </div>
          <div class="mb-3">
            <label for="loginPassword" class="form-label">Password</label>
            <div class="input-group">
              <span class="input-group-text bg-light border-end-0">
                <i class="bi bi-lock"></i>
              </span>
              <input type="password" class="form-control border-start-0" id="loginPassword" name="password" required>
              <button class="btn btn-outline-secondary" type="button" id="toggleLoginPassword">
                <i class="bi bi-eye"></i>
              </button>
            </div>
          </div>
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="rememberMe">
              <label class="form-check-label" for="rememberMe">
                Remember me
              </label>
            </div>
            <a href="#" class="text-primary text-decoration-none" data-bs-toggle="modal" data-bs-target="#forgotPasswordModal" data-bs-dismiss="modal">
              Forgot password?
            </a>
          </div>
          <button type="submit" class="btn btn-primary w-100 mb-3" id="loginSubmitBtn">
            <span class="spinner-border spinner-border-sm d-none me-2" role="status"></span>
            Sign In
          </button>
        </form>

        <!-- OTP Verification Form (Initially Hidden) -->
        <form id="otpForm" class="auth-form d-none">
          <div class="text-center mb-3">
            <i class="bi bi-shield-check text-primary" style="font-size: 2rem;"></i>
            <h6 class="mt-2">Verify Your Identity</h6>
            <p class="text-muted small">We've sent a verification code to your email</p>
          </div>
          <div class="mb-3">
            <label for="otpCode" class="form-label">Enter 6-digit code</label>
            <input type="text" class="form-control text-center" id="otpCode" name="otp" maxlength="6" pattern="[0-9]{6}" required style="letter-spacing: 0.5em; font-size: 1.1rem;">
          </div>
          <button type="submit" class="btn btn-primary w-100 mb-3" id="otpSubmitBtn">
            <span class="spinner-border spinner-border-sm d-none me-2" role="status"></span>
            Verify & Sign In
          </button>
          <button type="button" class="btn btn-outline-secondary w-100" id="resendOtpBtn">
            Resend Code
          </button>
        </form>

        <!-- Alert Container -->
        <div id="loginAlert" class="alert d-none" role="alert"></div>
      </div>
      <div class="modal-footer border-0 text-center">
        <p class="mb-0 text-muted">
          Don't have an account? 
          <a href="#" class="text-primary text-decoration-none" data-bs-toggle="modal" data-bs-target="#signupModal" data-bs-dismiss="modal">
            Sign up here
          </a>
        </p>
      </div>
    </div>
  </div>
</div>

<!-- Forgot Password Modal -->
<div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-labelledby="forgotPasswordModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0">
        <h5 class="modal-title" id="forgotPasswordModalLabel">Reset Password</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        <form id="forgotPasswordForm" class="auth-form">
          <div class="text-center mb-3">
            <i class="bi bi-key text-primary" style="font-size: 2rem;"></i>
            <p class="mt-2 text-muted">Enter your email address and we'll send you a reset link</p>
          </div>
          <div class="mb-3">
            <label for="forgotEmail" class="form-label">Email Address</label>
            <div class="input-group">
              <span class="input-group-text bg-light border-end-0">
                <i class="bi bi-envelope"></i>
              </span>
              <input type="email" class="form-control border-start-0" id="forgotEmail" name="forgot_email" required>
            </div>
          </div>
          <button type="submit" class="btn btn-primary w-100 mb-3" id="forgotSubmitBtn">
            <span class="spinner-border spinner-border-sm d-none me-2" role="status"></span>
            Send Reset Link
          </button>
        </form>
        <div id="forgotAlert" class="alert d-none" role="alert"></div>
      </div>
      <div class="modal-footer border-0 text-center">
        <p class="mb-0 text-muted">
          Remember your password? 
          <a href="#" class="text-primary text-decoration-none" data-bs-toggle="modal" data-bs-target="#loginModal" data-bs-dismiss="modal">
            Sign in
          </a>
        </p>
      </div>
    </div>
  </div>
</div>