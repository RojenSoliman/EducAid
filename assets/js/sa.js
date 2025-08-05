// multistep-registration.js

document.addEventListener("DOMContentLoaded", () => {
  const steps = document.querySelectorAll(".step");
  const panels = document.querySelectorAll(".step-panel");
  const otpButton = document.getElementById("requestOtpBtn");
  const otpTimerDisplay = document.getElementById("otpTimer");

  window.nextStep = function (current) {
    const next = current + 1;
    panels[current - 1].classList.add("d-none");
    panels[next - 1].classList.remove("d-none");

    steps[current - 1].classList.remove("active");
    steps[next - 1].classList.add("active");
  };

  window.prevStep = function (current) {
    const prev = current - 1;
    panels[current - 1].classList.add("d-none");
    panels[prev - 1].classList.remove("d-none");

    steps[current - 1].classList.remove("active");
    steps[prev - 1].classList.add("active");
  };

  // OTP Timer Logic
  function startOtpTimer(duration) {
    let remaining = duration;
    otpButton.disabled = true;
    updateTimerDisplay(remaining);

    const interval = setInterval(() => {
      remaining--;
      updateTimerDisplay(remaining);

      if (remaining <= 0) {
        clearInterval(interval);
        otpButton.disabled = false;
        otpTimerDisplay.textContent = "You can request again.";
      }
    }, 1000);
  }

  function updateTimerDisplay(seconds) {
    const minutes = Math.floor(seconds / 60);
    const secs = seconds % 60;
    otpTimerDisplay.textContent = `Wait ${minutes}:${secs.toString().padStart(2, "0")} to resend`;
  }

  if (otpButton) {
    otpButton.addEventListener("click", () => {
      const email = document.getElementById("email").value;
      const phone = document.getElementById("phone").value;
      const emailValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
      const phoneValid = /^09\d{9}$/.test(phone);

      if (!emailValid) {
        alert("Please enter a valid email address.");
        return;
      }

      if (!phoneValid) {
        alert("Please enter a valid Philippine phone number (e.g. 09XXXXXXXXX).");
        return;
      }

      // Trigger backend OTP send here (future)
      startOtpTimer(200);
    });
  }

  // Password validation on submit
  document.getElementById("multiStepForm").addEventListener("submit", (e) => {
    const password = document.getElementById("password").value;
    const confirmPassword = document.getElementById("confirmPassword").value;

    if (password.length < 12) {
      alert("Password must be at least 12 characters long.");
      e.preventDefault();
      return;
    }

    if (password !== confirmPassword) {
      alert("Passwords do not match.");
      e.preventDefault();
    }
  });
});
// Password Strength Checker
document.addEventListener("DOMContentLoaded", () => {
  const passwordInput = document.getElementById("password");
  const strengthBar = document.getElementById("strengthBar");
  const strengthText = document.getElementById("strengthText");

  passwordInput.addEventListener("input", () => {
    const val = passwordInput.value;
    let strength = 0;

    // Scoring rules
    if (val.length >= 12) strength += 1;
    if (/[A-Z]/.test(val)) strength += 1;
    if (/[a-z]/.test(val)) strength += 1;
    if (/[0-9]/.test(val)) strength += 1;
    if (/[\W_]/.test(val)) strength += 1;

    // Update progress bar
    let strengthPercent = (strength / 5) * 100;
    strengthBar.style.width = strengthPercent + "%";

    // Color and label
    if (strength <= 2) {
      strengthBar.className = "progress-bar bg-danger";
      strengthText.textContent = "Weak";
    } else if (strength === 3 || strength === 4) {
      strengthBar.className = "progress-bar bg-warning";
      strengthText.textContent = "Moderate";
    } else {
      strengthBar.className = "progress-bar bg-success";
      strengthText.textContent = "Strong";
    }
  });
});
// Toggle password visibility
document.querySelectorAll(".toggle-password").forEach(btn => {
  btn.addEventListener("click", () => {
    const targetId = btn.getAttribute("data-target");
    const input = document.getElementById(targetId);
    const icon = btn.querySelector("i");

    if (input.type === "password") {
      input.type = "text";
      icon.classList.remove("bi-eye");
      icon.classList.add("bi-eye-slash");
    } else {
      input.type = "password";
      icon.classList.remove("bi-eye-slash");
      icon.classList.add("bi-eye");
    }
  });
});

