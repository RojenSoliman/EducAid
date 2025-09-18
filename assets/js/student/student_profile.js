    // OTP logic for email change
    let otpCountdown, secondsLeft = 0;
    let otpVerified = false;
    function startOtpTimer() {
      secondsLeft = 40;
      document.getElementById('otpTimer').textContent = `Time left: ${secondsLeft} seconds`;
      clearInterval(otpCountdown);
      otpCountdown = setInterval(() => {
        secondsLeft--;
        document.getElementById('otpTimer').textContent = `Time left: ${secondsLeft} seconds`;
        if (secondsLeft <= 0) {
          clearInterval(otpCountdown);
          document.getElementById('otpTimer').textContent = "OTP expired. Please resend.";
          document.getElementById('verifyOtpBtn').disabled = true;
          document.getElementById('resendOtpBtn').style.display = 'inline-block';
        }
      }, 1000);
    }

    document.getElementById('sendOtpBtn').onclick = function(e) {
      e.preventDefault();
      const email = document.getElementById('newEmailInput').value;
      document.getElementById('emailOtpStatus').textContent = '';
      if (!email || !/\S+@\S+\.\S+/.test(email)) {
        document.getElementById('emailOtpStatus').textContent = "Enter a valid email.";
        return;
      }
      this.disabled = true;
      this.textContent = "Sending...";
      fetch('', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `ajax=send_otp&new_email=${encodeURIComponent(email)}`
      }).then(res => res.json()).then(data => {
        if (data.status === 'success') {
          document.getElementById('emailOtpStatus').textContent = "OTP sent! Check email.";
          document.getElementById('emailOtpStatus').className = 'form-success position-absolute';
          document.getElementById('otpSection').style.display = 'block';
          document.getElementById('verifyOtpBtn').disabled = false;
          document.getElementById('resendOtpBtn').style.display = 'none';
          startOtpTimer();
        } else {
          document.getElementById('emailOtpStatus').textContent = data.message;
          document.getElementById('emailOtpStatus').className = 'form-error position-absolute';
          this.disabled = false;
          this.textContent = "Send OTP";
        }
      }).catch(()=>{
        document.getElementById('emailOtpStatus').textContent = "Failed to send. Try again.";
        document.getElementById('emailOtpStatus').className = 'form-error position-absolute';
        this.disabled = false; this.textContent = "Send OTP";
      });
    };

    document.getElementById('resendOtpBtn').onclick = function() {
      document.getElementById('sendOtpBtn').disabled = false;
      document.getElementById('sendOtpBtn').textContent = "Send OTP";
      document.getElementById('otpSection').style.display = 'none';
      document.getElementById('emailOtpStatus').textContent = '';
    };

    document.getElementById('verifyOtpBtn').onclick = function() {
      const otp = document.getElementById('otpInput').value;
      const email = document.getElementById('newEmailInput').value;
      document.getElementById('otpInputError').textContent = '';
      if (!otp) {
        document.getElementById('otpInputError').textContent = "Enter the OTP.";
        return;
      }
      this.disabled = true; this.textContent = "Verifying...";
      fetch('', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `ajax=verify_otp&otp=${encodeURIComponent(otp)}&new_email=${encodeURIComponent(email)}`
      }).then(res=>res.json()).then(data=>{
        if (data.status === 'success') {
          document.getElementById('emailOtpStatus').textContent = "OTP verified!";
          document.getElementById('emailOtpStatus').className = 'form-success position-absolute';
          otpVerified = true;
          document.getElementById('otpInput').disabled = true;
          document.getElementById('saveEmailBtn').style.display = 'inline-block';
          document.getElementById('sendOtpBtn').style.display = 'none';
          document.getElementById('verifyOtpBtn').style.display = 'none';
          document.getElementById('resendOtpBtn').style.display = 'none';
          clearInterval(otpCountdown);
        } else {
          document.getElementById('otpInputError').textContent = data.message;
          this.disabled = false; this.textContent = "Verify OTP";
          otpVerified = false;
        }
      }).catch(()=>{
        document.getElementById('otpInputError').textContent = "Failed to verify. Try again.";
        this.disabled = false; this.textContent = "Verify OTP";
      });
    };

    document.getElementById('emailUpdateForm').onsubmit = function(e) {
      if (!otpVerified) {
        document.getElementById('emailOtpStatus').textContent = "You must verify OTP before saving.";
        document.getElementById('emailOtpStatus').className = 'form-error position-absolute';
        e.preventDefault();
        return false;
      }
    };

    // Password OTP logic for change password modal
    let otpPwdCountdown, otpPwdSecondsLeft = 0;
    let otpPwdVerified = false;

    function startOtpPwdTimer() {
      otpPwdSecondsLeft = 40;
      document.getElementById('otpPwdTimer').textContent = `Time left: ${otpPwdSecondsLeft} seconds`;
      clearInterval(otpPwdCountdown);
      otpPwdCountdown = setInterval(() => {
        otpPwdSecondsLeft--;
        document.getElementById('otpPwdTimer').textContent = `Time left: ${otpPwdSecondsLeft} seconds`;
        if (otpPwdSecondsLeft <= 0) {
          clearInterval(otpPwdCountdown);
          document.getElementById('otpPwdTimer').textContent = "OTP expired. Please resend.";
          document.getElementById('verifyOtpPwdBtn').disabled = true;
          document.getElementById('resendOtpPwdBtn').style.display = 'inline-block';
        }
      }, 1000);
    }

    function clearFieldErrors() {
      document.getElementById('currentPwdError').textContent = '';
      document.getElementById('newPwdError').textContent = '';
      document.getElementById('confirmPwdError').textContent = '';
      document.getElementById('otpPwdError').textContent = '';
    }

    document.getElementById('sendOtpPwdBtn').onclick = function(e) {
      e.preventDefault();
      clearFieldErrors();
      const currentPwd = document.getElementById('currentPwdInput').value;
      const newPwd = document.getElementById('newPwdInput').value;
      const confirmPwd = document.getElementById('confirmPwdInput').value;

      if (!currentPwd) {
        document.getElementById('currentPwdError').textContent = "Required";
        return;
      }
      if (!newPwd) {
        document.getElementById('newPwdError').textContent = "Required";
        return;
      }
      if (newPwd.length < 12) {
        document.getElementById('newPwdError').textContent = "Min 12 characters";
        return;
      }
      if (!confirmPwd) {
        document.getElementById('confirmPwdError').textContent = "Required";
        return;
      }
      if (newPwd !== confirmPwd) {
        document.getElementById('confirmPwdError').textContent = "Passwords do not match";
        return;
      }

      this.disabled = true;
      this.textContent = "Sending...";

      fetch('', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `ajax_pwd=send_otp_pwd&current_password=${encodeURIComponent(currentPwd)}&new_password=${encodeURIComponent(newPwd)}`
      }).then(res => res.json()).then(data => {
        if (data.status === 'success') {
          document.getElementById('otpPwdStatus').innerHTML = "<span class='form-success'>OTP sent! Check your email.</span>";
          document.getElementById('otpPwdSection').style.display = 'block';
          document.getElementById('verifyOtpPwdBtn').disabled = false;
          document.getElementById('resendOtpPwdBtn').style.display = 'none';
          startOtpPwdTimer();
        } else {
          if (data.target) {
            if (data.target === 'currentPwdInput') document.getElementById('currentPwdError').textContent = data.message;
            if (data.target === 'newPwdInput') document.getElementById('newPwdError').textContent = data.message;
            if (data.target === 'otpPwdInput') document.getElementById('otpPwdError').textContent = data.message;
          }
          document.getElementById('otpPwdStatus').innerHTML = `<span class='form-error'>${data.message}</span>`;
          this.disabled = false;
          this.textContent = "Send OTP";
        }
      }).catch(()=>{
        document.getElementById('otpPwdStatus').innerHTML = "<span class='form-error'>Failed to send. Try again.</span>";
        this.disabled = false; this.textContent = "Send OTP";
      });
    };

    document.getElementById('resendOtpPwdBtn').onclick = function() {
      document.getElementById('sendOtpPwdBtn').disabled = false;
      document.getElementById('sendOtpPwdBtn').textContent = "Send OTP";
      document.getElementById('otpPwdSection').style.display = 'none';
      document.getElementById('otpPwdStatus').innerHTML = '';
      clearFieldErrors();
    };

    document.getElementById('verifyOtpPwdBtn').onclick = function() {
      const otp = document.getElementById('otpPwdInput').value;
      document.getElementById('otpPwdError').textContent = '';
      if (!otp) {
        document.getElementById('otpPwdError').textContent = "Required";
        return;
      }
      this.disabled = true; this.textContent = "Verifying...";
      fetch('', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `ajax_pwd=verify_otp_pwd&otp=${encodeURIComponent(otp)}`
      }).then(res=>res.json()).then(data=>{
        if (data.status === 'success') {
          document.getElementById('otpPwdStatus').innerHTML = "<span class='form-success'>OTP verified!</span>";
          otpPwdVerified = true;
          document.getElementById('otpPwdInput').disabled = true;
          document.getElementById('savePwdBtn').style.display = 'inline-block';
          document.getElementById('sendOtpPwdBtn').style.display = 'none';
          document.getElementById('verifyOtpPwdBtn').style.display = 'none';
          document.getElementById('resendOtpPwdBtn').style.display = 'none';
          clearInterval(otpPwdCountdown);
        } else {
          if (data.target) document.getElementById(data.target).textContent = data.message;
          document.getElementById('otpPwdStatus').innerHTML = `<span class='form-error'>${data.message}</span>`;
          this.disabled = false; this.textContent = "Verify OTP";
          otpPwdVerified = false;
        }
      }).catch(()=>{
        document.getElementById('otpPwdStatus').innerHTML = "<span class='form-error'>Failed to verify. Try again.</span>";
        this.disabled = false; this.textContent = "Verify OTP";
      });
    };

    document.getElementById('passwordUpdateForm').onsubmit = function(e) {
      if (!otpPwdVerified) {
        document.getElementById('otpPwdStatus').innerHTML = "<span class='form-error'>You must verify OTP before saving.</span>";
        e.preventDefault();
        return false;
      }
    };