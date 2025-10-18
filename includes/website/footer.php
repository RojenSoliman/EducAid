<?php
/**
 * Dynamic Footer - Modular Component
 * CMS-Controlled footer for all website pages
 * Reads settings from footer_settings table
 */

// Ensure database connection
if (!isset($connection)) {
    @include_once __DIR__ . '/../../config/database.php';
}

// Default fallback settings
$footer_settings = [
    'footer_bg_color' => '#0051f8',
    'footer_text_color' => '#ffffff',
    'footer_heading_color' => '#ffffff',
    'footer_link_color' => '#ffffff',
    'footer_link_hover_color' => '#fbbf24',
    'footer_divider_color' => '#ffffff',
    'footer_title' => 'EducAid • General Trias',
    'footer_description' => 'Let\'s join forces for a more progressive GenTrias.',
    'contact_address' => '123 Education Street, Academic City',
    'contact_phone' => '+1 (555) 123-4567',
    'contact_email' => 'info@educaid.com'
];

// Load settings from database
if (isset($connection)) {
    $footerQuery = "SELECT * FROM footer_settings WHERE is_active = TRUE ORDER BY created_at DESC LIMIT 1";
    $footerResult = @pg_query($connection, $footerQuery);
    
    if ($footerResult && pg_num_rows($footerResult) > 0) {
        $dbFooter = pg_fetch_assoc($footerResult);
        foreach ($dbFooter as $key => $value) {
            if ($value !== null && $value !== '') {
                $footer_settings[$key] = $value;
            }
        }
    }
}
?>

<style>
    #dynamic-footer {
        background: <?= htmlspecialchars($footer_settings['footer_bg_color']) ?>;
        color: <?= htmlspecialchars($footer_settings['footer_text_color']) ?>;
    }
    #dynamic-footer .footer-logo {
        font-size: 1.2rem;
        font-weight: 600;
        color: <?= htmlspecialchars($footer_settings['footer_heading_color']) ?>;
    }
    #dynamic-footer small {
        color: <?= htmlspecialchars($footer_settings['footer_text_color']) ?>;
        opacity: 0.9;
    }
    #dynamic-footer h6 {
        color: <?= htmlspecialchars($footer_settings['footer_heading_color']) ?>;
        font-weight: 600;
    }
    #dynamic-footer a {
        color: <?= htmlspecialchars($footer_settings['footer_link_color']) ?>;
        text-decoration: none;
        transition: color 0.3s ease;
    }
    #dynamic-footer a:hover {
        color: <?= htmlspecialchars($footer_settings['footer_link_hover_color']) ?>;
    }
    #dynamic-footer hr {
        border-color: <?= htmlspecialchars($footer_settings['footer_divider_color']) ?> !important;
        opacity: 0.25;
    }
    #dynamic-footer .brand-badge {
        width: 48px;
        height: 48px;
        background: <?= htmlspecialchars($footer_settings['footer_link_hover_color']) ?>;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 1.1rem;
        color: <?= htmlspecialchars($footer_settings['footer_bg_color']) ?>;
    }
    #dynamic-footer .btn-light {
        background: #fff;
        border: none;
        padding: 0.5rem 1rem;
        border-radius: 4px;
    }
    #dynamic-footer .form-control {
        border: none;
        padding: 0.5rem 1rem;
        border-radius: 4px;
    }
</style>

<!-- Footer (CMS Controlled) -->
<footer id="dynamic-footer" class="pt-5 pb-4">
  <div class="container">
    <div class="row g-4 align-items-center">
      <div class="col-lg-6">
        <div class="d-flex align-items-center gap-3">
            <div class="brand-badge">EA</div>
            <div>
              <div class="footer-logo"><?= htmlspecialchars($footer_settings['footer_title']) ?></div>
              <small><?= htmlspecialchars($footer_settings['footer_description']) ?></small>
            </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="row">
          <div class="col-6 col-md-4">
            <h6>Explore</h6>
            <ul class="list-unstyled small">
              <li><a href="landingpage.php#home">Home</a></li>
              <li><a href="about.php">About</a></li>
              <li><a href="how-it-works.php">Process</a></li>
              <li><a href="announcements.php">Announcements</a></li>
            </ul>
          </div>
          <div class="col-6 col-md-4">
            <h6>Resources</h6>
            <ul class="list-unstyled small">
              <li><a href="requirements.php">Requirements</a></li>
              <li><a href="landingpage.php#faq">FAQs</a></li>
              <li><a href="contact.php">Contact</a></li>
            </ul>
          </div>
          <div class="col-12 col-md-4 mt-3 mt-md-0">
            <h6>Contact Info</h6>
            <ul class="list-unstyled small">
              <li class="mb-2"><i class="bi bi-geo-alt me-2"></i><?= htmlspecialchars($footer_settings['contact_address']) ?></li>
              <li class="mb-2"><i class="bi bi-telephone me-2"></i><?= htmlspecialchars($footer_settings['contact_phone']) ?></li>
              <li class="mb-2"><i class="bi bi-envelope me-2"></i><?= htmlspecialchars($footer_settings['contact_email']) ?></li>
            </ul>
          </div>
        </div>
      </div>
    </div>
    <hr class="border-light my-4" />
    <div class="d-flex justify-content-between flex-wrap gap-2 small">
      <span>© <span id="year"><?= date('Y') ?></span> City Government of General Trias • EducAid</span>
      <span>Powered by the Office of the Mayor • IT</span>
    </div>
  </div>
</footer>
