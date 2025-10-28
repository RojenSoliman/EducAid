<?php /* index.php - EducAid Landing (root) */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>EducAid – Scholarship Assistance Platform</title>
  <link href="../../assets/css/bootstrap.min.css" rel="stylesheet" />
  <link href="../../assets/css/bootstrap-icons.css" rel="stylesheet" />
  <style>
    :root {
      --trias-blue: #1e3a8a;
      --trias-light-blue: #3b82f6;
      --trias-gold: #f59e0b;
      --trias-dark-blue: #1e40af;
      --trias-accent: #fbbf24;
    }
    .hero-section {
      background: linear-gradient(135deg, var(--trias-blue) 0%, var(--trias-dark-blue) 100%);
      min-height: 100vh;
      display: flex;
      align-items: center;
    }
    .btn-rounded { border-radius: 50px; padding: 12px 30px; }
    .btn-trias-primary { background-color: var(--trias-blue); border-color: var(--trias-blue); color: #fff; }
    .btn-trias-primary:hover { background-color: var(--trias-dark-blue); border-color: var(--trias-dark-blue); color: #fff; }
    .btn-trias-gold { background-color: var(--trias-gold); border-color: var(--trias-gold); color: #fff; }
    .btn-trias-gold:hover { background-color: var(--trias-accent); border-color: var(--trias-accent); color: #fff; }
    .feature-card { transition: transform 0.3s ease; }
    .feature-card:hover { transform: translateY(-5px); }
    .stats-section { background: #f8fafc; }
    .cta-section { background: linear-gradient(135deg, var(--trias-light-blue) 0%, var(--trias-blue) 100%); }
    .navbar .nav-link { font-size: 1.2rem; }
    .navbar-brand { color: var(--trias-blue) !important; }
    .text-trias-blue { color: var(--trias-blue) !important; }
    .text-trias-gold { color: var(--trias-gold) !important; }
    .text-trias-light-blue { color: var(--trias-light-blue) !important; }
    .bg-trias-blue { background-color: var(--trias-blue) !important; }
    .border-trias-gold { border-color: var(--trias-gold) !important; }
    .footer-text { color: #cbd5e1 !important; }
    .footer-link { color: #e2e8f0 !important; text-decoration: none; }
    .footer-link:hover { color: var(--trias-gold) !important; }
    .footer-heading { color: #ffffff !important; }
    .trias-accent-border { border-left: 4px solid var(--trias-gold); padding-left: 1rem; }
  </style>
</head>
<body>

  <!-- Navbar -->
  <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top">
    <div class="container">
      <a class="navbar-brand fw-bold text-trias-blue fs-3" href="#home">
        <i class="bi bi-mortarboard-fill me-2"></i>EDUCAID
        <small class="d-block fs-6 text-muted">General Trias City</small>
      </a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="navbarNav">
        <ul class="navbar-nav mx-auto">
          <li class="nav-item"><a class="nav-link text-dark fw-medium" href="#home">Home</a></li>
          <li class="nav-item"><a class="nav-link text-dark fw-medium" href="#about">About</a></li>
          <li class="nav-item"><a class="nav-link text-dark fw-medium" href="#programs">Programs</a></li>
          <li class="nav-item"><a class="nav-link text-dark fw-medium" href="#contact">Contact</a></li>
        </ul>
        <div class="d-flex">
          <!-- From project root to modules/student/... -->
          <a href="student_login.html" class="btn btn-outline-primary btn-rounded me-2" href="unified_login.php">Login</a>
          <a href="student_register.php" class="btn btn-trias-primary btn-rounded">Register</a>
        </div>
      </div>
    </div>
  </nav>

  <!-- Hero Section -->
  <section id="home" class="hero-section text-white">
    <div class="container">
      <div class="row align-items-center">
        <div class="col-lg-6 mb-5 mb-lg-0">
          <div class="trias-accent-border">
            <h1 class="display-4 fw-bold mb-4">
              Scholarship made easy with <span class="text-trias-gold">EducAid</span>
            </h1>
            <p class="lead mb-4">
              Empowering General Trias students through accessible scholarship programs. Track your application, upload documents, and receive aid—faster and simpler than ever before.
            </p>
          </div>
          <div class="d-flex flex-wrap gap-3 mt-4">
            <a href="student_login.html" class="btn btn-trias-gold btn-rounded btn-lg">
              <i class="bi bi-play-circle me-2"></i>Start Application
            </a>
            <a href="#about" class="btn btn-outline-light btn-rounded btn-lg">
              <i class="bi bi-info-circle me-2"></i>Learn More
            </a>
          </div>
        </div>
        <div class="col-lg-6 text-center">
          <div class="position-relative">
            <img src="https://via.placeholder.com/500x400/1e3a8a/ffffff?text=General+Trias+Education"
                 class="img-fluid rounded-3 shadow-lg border border-trias-gold border-3" alt="General Trias Education">
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Stats Section -->
  <section class="stats-section py-5">
    <div class="container">
      <div class="text-center mb-5">
        <h2 class="fw-bold text-trias-blue">General Trias Educational Impact</h2>
        <p class="text-muted">Supporting students across our growing city</p>
      </div>
      <div class="row text-center">
        <div class="col-md-3 mb-4">
          <div class="p-4">
            <i class="bi bi-people-fill text-trias-blue fs-1 mb-3"></i>
            <h3 class="fw-bold text-trias-blue">5,000+</h3>
            <p class="text-muted">General Trias Students Helped</p>
          </div>
        </div>
        <div class="col-md-3 mb-4">
          <div class="p-4">
            <i class="bi bi-building text-trias-light-blue fs-1 mb-3"></i>
            <h3 class="fw-bold text-trias-light-blue">50+</h3>
            <p class="text-muted">Partner Schools in Cavite</p>
          </div>
        </div>
        <div class="col-md-3 mb-4">
          <div class="p-4">
            <i class="bi bi-award-fill text-trias-gold fs-1 mb-3"></i>
            <h3 class="fw-bold text-trias-gold">98%</h3>
            <p class="text-muted">Local Success Rate</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- About Section -->
  <section id="about" class="py-5">
    <div class="container">
      <div class="row align-items-center">
        <div class="col-lg-6 mb-5 mb-lg-0">
          <img src="https://via.placeholder.com/600x400/f8fafc/1e3a8a?text=General+Trias+Community"
               class="img-fluid rounded-3 shadow" alt="General Trias Community">
        </div>
        <div class="col-lg-6">
          <div class="trias-accent-border">
            <h2 class="display-5 fw-bold mb-4 text-trias-blue">Why Choose EducAid in General Trias?</h2>
            <p class="lead text-muted mb-4">
              We're committed to making quality education accessible to every student in General Trias City, supporting our community's growth and development.
            </p>
          </div>
          <div class="row mt-4">
            <div class="col-sm-6 mb-3">
              <div class="d-flex">
                <i class="bi bi-check-circle-fill text-trias-gold me-3 fs-4"></i>
                <div>
                  <h5 class="fw-bold">Local Partnership</h5>
                  <p class="text-muted mb-0">Connected with General Trias schools</p>
                </div>
              </div>
            </div>
            <div class="col-sm-6 mb-3">
              <div class="d-flex">
                <i class="bi bi-lightning-fill text-trias-blue me-3 fs-4"></i>
                <div>
                  <h5 class="fw-bold">Fast Processing</h5>
                  <p class="text-muted mb-0">Quick local approval system</p>
                </div>
              </div>
            </div>
            <div class="col-sm-6 mb-3">
              <div class="d-flex">
                <i class="bi bi-shield-check text-trias-light-blue me-3 fs-4"></i>
                <div>
                  <h5 class="fw-bold">Community Focused</h5>
                  <p class="text-muted mb-0">Tailored for local needs</p>
                </div>
              </div>
            </div>
            <div class="col-sm-6 mb-3">
              <div class="d-flex">
                <i class="bi bi-headset text-trias-gold me-3 fs-4"></i>
                <div>
                  <h5 class="fw-bold">Local Support</h5>
                  <p class="text-muted mb-0">General Trias-based assistance</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Programs Section -->
  <section id="programs" class="py-5 bg-light">
    <div class="container">
      <div class="text-center mb-5">
        <h2 class="display-5 fw-bold text-trias-blue">General Trias Scholarship Programs</h2>
        <p class="lead text-muted">Educational opportunities for our local community</p>
      </div>
      <div class="row">
        <div class="col-lg-4 mb-4">
          <div class="card h-100 feature-card border-0 shadow-sm">
            <div class="card-body text-center p-4">
              <i class="bi bi-book text-trias-blue fs-1 mb-3"></i>
              <h4 class="fw-bold mb-3 text-trias-blue">Academic Excellence</h4>
              <p class="text-muted mb-4">For General Trias students with outstanding academic performance</p>
              <a href="#" class="btn btn-outline-primary btn-rounded">Learn More</a>
            </div>
          </div>
        </div>
        <div class="col-lg-4 mb-4">
          <div class="card h-100 feature-card border-0 shadow-sm">
            <div class="card-body text-center p-4">
              <i class="bi bi-heart text-trias-gold fs-1 mb-3"></i>
              <h4 class="fw-bold mb-3 text-trias-gold">Community Aid</h4>
              <p class="text-muted mb-4">Financial assistance for local families in need</p>
              <a href="#" class="btn btn-trias-gold btn-rounded">Learn More</a>
            </div>
          </div>
        </div>
        <div class="col-lg-4 mb-4">
          <div class="card h-100 feature-card border-0 shadow-sm">
            <div class="card-body text-center p-4">
              <i class="bi bi-trophy text-trias-light-blue fs-1 mb-3"></i>
              <h4 class="fw-bold mb-3 text-trias-light-blue">Special Talents</h4>
              <p class="text-muted mb-4">Supporting talented students from General Trias</p>
              <a href="#" class="btn btn-outline-info btn-rounded">Learn More</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- CTA Section -->
  <section class="cta-section py-5 text-white">
    <div class="container text-center">
      <div class="row justify-content-center">
        <div class="col-lg-8">
          <h2 class="display-5 fw-bold mb-4">Ready to Start Your Educational Journey in General Trias?</h2>
          <p class="lead mb-4">Join hundreds of General Trias students who have already received scholarship assistance through EducAid.</p>
          <a href="modules/student/student_login.php" class="btn btn-light btn-rounded btn-lg me-3">
            <i class="bi bi-person-plus me-2"></i>Apply Now
          </a>
          <a href="#contact" class="btn btn-trias-gold btn-rounded btn-lg">
            <i class="bi bi-telephone me-2"></i>Contact Us
          </a>
        </div>
      </div>
    </div>
  </section>

  <!-- Footer - Dynamic CMS Controlled -->
  <?php include __DIR__ . '/../../includes/website/footer.php'; ?>

  <script src="../../assets/js/bootstrap.bundle.min.js"></script>
  <script>
    // Smooth scrolling for internal navigation links only
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
      anchor.addEventListener('click', function (e) {
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
          e.preventDefault();
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      });
    });

    // Navbar background on scroll
    window.addEventListener('scroll', function() {
      const navbar = document.querySelector('.navbar');
      if (window.scrollY > 50) {
        navbar.classList.add('bg-white', 'shadow-sm');
      } else {
        navbar.classList.remove('bg-white', 'shadow-sm');
      }
    });
  </script>
</body>
</html>
