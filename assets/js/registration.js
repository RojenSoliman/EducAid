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
