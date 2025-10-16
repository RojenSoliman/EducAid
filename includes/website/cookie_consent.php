<?php
// Simple cookie consent banner include
?>
<div id="eaCookieConsent" style="display:none; position:fixed; left:0; right:0; bottom:0; z-index:1100;">
  <div class="container">
    <div class="alert alert-dark border shadow-sm d-flex align-items-center justify-content-between gap-3 mb-2" role="alert" style="border-radius:12px;">
      <div class="d-flex align-items-start gap-3">
        <i class="bi bi-shield-lock" style="font-size:1.25rem;"></i>
        <div class="small">
          <strong>We use cookies to improve your experience</strong><br/>
          We use a few essential cookies so the site works properly (like keeping you signed in and keeping things secure).
          If you click "Accept all", we'll also remember your preferences to make things easier next time.
          You can keep browsing without accepting—only the essential cookies will be used.
        </div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <button id="eaAcceptAllCookies" class="btn btn-success btn-sm"><i class="bi bi-check2 me-1"></i>Accept all</button>
        <a href="#faq" class="btn btn-outline-secondary btn-sm">Learn more</a>
      </div>
    </div>
  </div>
  <script>
  (function(){
    function getCookie(name){
      var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.$?*|{}()\[\]\\\/\+^]/g, '\\$&') + '=([^;]*)'));
      return m ? decodeURIComponent(m[1]) : null;
    }
    function setCookie(name, value, days){
      var maxAge = days ? '; Max-Age=' + (days*24*60*60) : '';
      document.cookie = name + '=' + encodeURIComponent(value) + maxAge + '; Path=/; SameSite=Lax';
    }
    var banner = document.getElementById('eaCookieConsent');
    if (!banner) return;
    if (!getCookie('ea_cookie_consent')) {
      banner.style.display = 'block';
    }
    var btn = document.getElementById('eaAcceptAllCookies');
    if (btn) {
      btn.addEventListener('click', function(){
        setCookie('ea_cookie_consent', 'all', 365);
        banner.style.display = 'none';
        try { localStorage.setItem('ea_pref_consent', 'all'); } catch(e) {}
      });
    }
  })();
  </script>
</div>
