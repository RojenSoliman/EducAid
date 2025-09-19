<!-- Signup Modal Component -->
<div class="modal fade" id="signupModal" tabindex="-1" aria-labelledby="signupModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header border-0">
        <h5 class="modal-title" id="signupModalLabel">
          <img src="assets/images/educaid-logo.png" alt="EducAid" class="me-2" style="height: 24px;">
          Apply for Educational Assistance
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        <!-- Multi-step Progress Indicator -->
        <div class="signup-progress mb-4">
          <div class="progress mb-2" style="height: 4px;">
            <div class="progress-bar" role="progressbar" style="width: 33%"></div>
          </div>
          <div class="d-flex justify-content-between">
            <small class="text-primary fw-bold">1. Basic Info</small>
            <small class="text-muted">2. Verification</small>
            <small class="text-muted">3. Complete</small>
          </div>
        </div>

        <!-- Step 1: Basic Information -->
        <form id="signupForm" class="auth-form">
          <div id="step1" class="signup-step">
            <div class="row">
              <div class="col-md-6 mb-3">
                <label for="signupFirstName" class="form-label">First Name</label>
                <div class="input-group">
                  <span class="input-group-text bg-light border-end-0">
                    <i class="bi bi-person"></i>
                  </span>
                  <input type="text" class="form-control border-start-0" id="signupFirstName" name="first_name" required>
                </div>
              </div>
              <div class="col-md-6 mb-3">
                <label for="signupLastName" class="form-label">Last Name</label>
                <div class="input-group">
                  <span class="input-group-text bg-light border-end-0">
                    <i class="bi bi-person"></i>
                  </span>
                  <input type="text" class="form-control border-start-0" id="signupLastName" name="last_name" required>
                </div>
              </div>
            </div>
            <div class="mb-3">
              <label for="signupEmail" class="form-label">Email Address</label>
              <div class="input-group">
                <span class="input-group-text bg-light border-end-0">
                  <i class="bi bi-envelope"></i>
                </span>
                <input type="email" class="form-control border-start-0" id="signupEmail" name="email" required>
              </div>
              <div class="form-text">We'll send verification codes to this email</div>
            </div>
            <div class="mb-3">
              <label for="signupPhone" class="form-label">Mobile Number</label>
              <div class="input-group">
                <span class="input-group-text bg-light border-end-0">
                  <i class="bi bi-phone"></i>
                </span>
                <input type="tel" class="form-control border-start-0" id="signupPhone" name="mobile_number" pattern="[0-9]{11}" required>
              </div>
              <div class="form-text">Format: 09XXXXXXXXX (11 digits)</div>
            </div>
            <div class="row">
              <div class="col-md-6 mb-3">
                <label for="signupPassword" class="form-label">Password</label>
                <div class="input-group">
                  <span class="input-group-text bg-light border-end-0">
                    <i class="bi bi-lock"></i>
                  </span>
                  <input type="password" class="form-control border-start-0" id="signupPassword" name="password" required minlength="8">
                  <button class="btn btn-outline-secondary" type="button" id="toggleSignupPassword">
                    <i class="bi bi-eye"></i>
                  </button>
                </div>
              </div>
              <div class="col-md-6 mb-3">
                <label for="signupConfirmPassword" class="form-label">Confirm Password</label>
                <div class="input-group">
                  <span class="input-group-text bg-light border-end-0">
                    <i class="bi bi-lock"></i>
                  </span>
                  <input type="password" class="form-control border-start-0" id="signupConfirmPassword" name="confirm_password" required minlength="8">
                </div>
              </div>
            </div>
            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" id="agreeTerms" required>
              <label class="form-check-label" for="agreeTerms">
                I agree to the <a href="#" class="text-primary">Terms of Service</a> and <a href="#" class="text-primary">Privacy Policy</a>
              </label>
            </div>
            <button type="button" class="btn btn-primary w-100" id="signupNextBtn">
              Continue to Verification
              <i class="bi bi-arrow-right ms-2"></i>
            </button>
          </div>

          <!-- Step 2: Email Verification -->
          <div id="step2" class="signup-step d-none">
            <div class="text-center mb-3">
              <i class="bi bi-envelope-check text-primary" style="font-size: 2rem;"></i>
              <h6 class="mt-2">Verify Your Email</h6>
              <p class="text-muted small">We've sent a verification code to <span id="verificationEmail" class="fw-bold"></span></p>
            </div>
            <div class="mb-3">
              <label for="signupOtpCode" class="form-label">Enter 6-digit verification code</label>
              <input type="text" class="form-control text-center" id="signupOtpCode" name="verification_otp" maxlength="6" pattern="[0-9]{6}" required style="letter-spacing: 0.5em; font-size: 1.1rem;">
            </div>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-outline-secondary" id="signupBackBtn">
                <i class="bi bi-arrow-left me-2"></i>Back
              </button>
              <button type="submit" class="btn btn-primary flex-fill" id="signupSubmitBtn">
                <span class="spinner-border spinner-border-sm d-none me-2" role="status"></span>
                Create Account
              </button>
            </div>
            <button type="button" class="btn btn-outline-secondary w-100 mt-2" id="resendSignupOtpBtn">
              Resend Verification Code
            </button>
          </div>

          <!-- Step 3: Success -->
          <div id="step3" class="signup-step d-none">
            <div class="text-center">
              <i class="bi bi-check-circle-fill text-success" style="font-size: 3rem;"></i>
              <h5 class="mt-3 text-success">Account Created Successfully!</h5>
              <p class="text-muted mb-4">Your application has been submitted for review. You'll receive an email notification once your account is approved.</p>
              <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                <strong>What's next?</strong> Your account is now under review by our administrators. This process typically takes 1-2 business days.
              </div>
              <button type="button" class="btn btn-primary" data-bs-dismiss="modal">
                Got it, thanks!
              </button>
            </div>
          </div>
        </form>

        <!-- Alert Container -->
        <div id="signupAlert" class="alert d-none" role="alert"></div>
      </div>
      <div class="modal-footer border-0 text-center">
        <p class="mb-0 text-muted">
          Already have an account? 
          <a href="#" class="text-primary text-decoration-none" data-bs-toggle="modal" data-bs-target="#loginModal" data-bs-dismiss="modal">
            Sign in here
          </a>
        </p>
      </div>
    </div>
  </div>
</div>