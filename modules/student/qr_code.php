<?php
include '../../config/database.php';
session_start();

// Check if student is logged in
if (!isset($_SESSION['student_username']) || !isset($_SESSION['student_id'])) {
    header("Location: ../../unified_login.php");
    exit;
}

$student_id = $_SESSION['student_id'];

// Get student information including QR code data
$query = "
    SELECT s.student_id, s.first_name, s.middle_name, s.last_name, 
           s.payroll_no, s.status,
           q.unique_id as qr_unique_id, q.status as qr_status, q.created_at as qr_created_at
    FROM students s
    LEFT JOIN qr_codes q ON s.student_id = q.student_id AND s.payroll_no = q.payroll_number
    WHERE s.student_id = $1
";

$result = pg_query_params($connection, $query, [$student_id]);
$student_data = pg_fetch_assoc($result);

if (!$student_data) {
    header("Location: ../../unified_login.php");
    exit;
}

// Check if student has received aid (status = 'given')
$has_received_aid = ($student_data['status'] === 'given');

// Get distribution details if student has received aid
$distribution_details = null;
if ($has_received_aid) {
    // Get the most recent distribution where this student received aid
    $dist_query = "
        SELECT 
            ds.distribution_id,
            ds.academic_year,
            ds.semester,
            ds.distribution_date,
            ds.location,
            ds.finalized_at,
            dss.amount_received,
            dss.payroll_number
        FROM distribution_student_snapshot dss
        JOIN distribution_snapshots ds ON dss.distribution_id = ds.distribution_id
        WHERE dss.student_id = $1
        ORDER BY ds.finalized_at DESC
        LIMIT 1
    ";
    
    $dist_result = pg_query_params($connection, $dist_query, [$student_id]);
    if ($dist_result && pg_num_rows($dist_result) > 0) {
        $distribution_details = pg_fetch_assoc($dist_result);
    }
}

// Get student info for header dropdown
$student_info_query = "SELECT first_name, last_name FROM students WHERE student_id = $1";
$student_info_result = pg_query_params($connection, $student_info_query, [$student_id]);
$student_info = pg_fetch_assoc($student_info_result);

$has_qr_code = !empty($student_data['qr_unique_id']) && !empty($student_data['payroll_no']);
$qr_image_url = '';

if ($has_qr_code) {
    // Generate QR code image URL
    $qr_image_url = '../admin/phpqrcode/generate_qr.php?data=' . urlencode($student_data['qr_unique_id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>My QR Code - EducAid</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="../../assets/css/bootstrap.min.css">
  <link href="../../assets/css/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="../../assets/css/student/homepage.css">
  <link rel="stylesheet" href="../../assets/css/student/sidebar.css">
  <link rel="stylesheet" href="../../assets/css/student/accessibility.css" />
  <script src="../../assets/js/student/accessibility.js"></script>
  <style>
    body {
      background: linear-gradient(135deg, #0068DA 0%, #0088C8 50%, #00B1C6 100%);
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      min-height: 100vh;
      margin: 0;
      padding: 0;
      overflow-x: hidden;
    }
    
    #wrapper {
      width: 100%;
      overflow-x: hidden;
    }
    
    .main-content {
      padding: 2rem;
      width: 100%;
      max-width: 100%;
    }
    
    .home-section {
      min-height: 100vh;
      padding-top: 80px; /* Account for header (56px) + some spacing */
    }
    
    /* Use shared header for nav and menu toggle styling */
    
    .qr-card {
      background: white;
      border-radius: 15px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.2);
      padding: 2rem;
      text-align: center;
      max-width: 800px;
      width: 100%;
      margin: 0 auto;
    }
    
    .student-info {
      background: linear-gradient(135deg, #007bff, #0056b3);
      color: white;
      padding: 1.5rem;
      border-radius: 10px;
      margin-bottom: 2rem;
    }
    
    .qr-display {
      padding: 2rem;
      background: #f8f9fa;
      border-radius: 10px;
      margin: 2rem 0;
    }
    
    .qr-image {
      max-width: 350px;
      height: auto;
      border: 3px solid #007bff;
      border-radius: 10px;
      padding: 15px;
      background: white;
      box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    
    .payroll-badge {
      background: linear-gradient(45deg, #28a745, #20c997);
      color: white;
      padding: 0.75rem 1.5rem;
      border-radius: 25px;
      font-size: 1.2rem;
      font-weight: bold;
      display: inline-block;
      margin: 1rem 0;
    }
    
    .status-badge {
      padding: 0.5rem 1rem;
      border-radius: 20px;
      font-weight: 600;
      text-transform: uppercase;
      font-size: 0.9rem;
    }
    
    .status-given { background: #cfe2ff; color: #084298; }
    .status-active { background: #d4edda; color: #155724; }
    .status-applicant { background: #fff3cd; color: #856404; }
    .status-pending { background: #fff3cd; color: #856404; }
    .status-disabled { background: #f8d7da; color: #721c24; }
    .status-inactive { background: #f8d7da; color: #721c24; }
    
    .no-qr-message {
      background: linear-gradient(135deg, #ffc107, #e0a800);
      color: #212529;
      padding: 2rem;
      border-radius: 10px;
      margin: 2rem 0;
    }
    
    .download-btn {
      background: linear-gradient(45deg, #007bff, #0056b3);
      border: none;
      color: white;
      padding: 12px 30px;
      border-radius: 25px;
      font-weight: 600;
      text-decoration: none;
      display: inline-block;
      transition: all 0.3s ease;
      margin-top: 1rem;
    }
    
    .download-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(0,123,255,0.4);
      color: white;
    }
    
    .info-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1rem;
      margin-top: 1rem;
    }
    
    .info-item {
      background: rgba(255,255,255,0.2);
      padding: 1rem;
      border-radius: 8px;
      backdrop-filter: blur(10px);
    }
    
    .info-label {
      font-size: 0.9rem;
      opacity: 0.9;
      margin-bottom: 0.5rem;
    }
    
    .info-value {
      font-size: 1.1rem;
      font-weight: 600;
    }

    @media (max-width: 768px) {
      .home-section {
        padding-top: 80px; /* Ensure content doesn't go under header on mobile */
      }
      
      .main-content { 
        padding: 1rem 0.75rem; 
      }
      
      .qr-card { 
        padding: 1rem; 
        margin: 0 0.5rem;
      }
      
      .student-info {
        padding: 1rem;
        margin-bottom: 1.5rem;
      }
      
      .student-info h2 {
        font-size: 1.3rem;
        margin-bottom: 1rem !important;
      }
      
      .info-grid {
        grid-template-columns: 1fr;
        gap: 0.75rem;
      }
      
      .info-item {
        padding: 0.75rem;
      }
      
      .info-label {
        font-size: 0.85rem;
      }
      
      .info-value {
        font-size: 1rem;
      }
      
      .qr-display {
        padding: 1rem;
        margin: 1rem 0;
      }
      
      .qr-image { 
        max-width: 100%;
        width: 100%;
        height: auto;
      }
      
      .payroll-badge {
        font-size: 1rem;
        padding: 0.6rem 1.2rem;
      }
      
      .no-qr-message {
        padding: 1.25rem;
        margin: 1rem 0;
      }
      
      .no-qr-message h3 {
        font-size: 1.2rem;
      }
      
      .no-qr-message p,
      .no-qr-message ul {
        font-size: 0.9rem;
      }
      
      .download-btn {
        padding: 10px 20px;
        font-size: 0.95rem;
      }
      
      .home-section { 
        margin-left: 0 !important; 
      }
      
      /* Card adjustments for mobile */
      .card-body {
        padding: 0.75rem;
      }
      
      .alert {
        padding: 0.75rem;
        font-size: 0.9rem;
      }
      
      .alert h4 {
        font-size: 1.1rem;
      }
      
      /* Rely on shared header responsiveness */
    }
  </style>
</head>
<body>
  <!-- Student Topbar -->
  <?php include __DIR__ . '/../../includes/student/student_topbar.php'; ?>

  <div id="wrapper" style="padding-top: var(--topbar-h);">
  <?php include __DIR__ . '/../../includes/student/student_sidebar.php'; ?>
  
    <!-- Student Header -->
    <?php include __DIR__ . '/../../includes/student/student_header.php'; ?>
    
    <section class="home-section" id="page-content-wrapper">
      <div class="main-content">
        <div class="qr-card">
          <!-- Student Information Header -->
          <div class="student-info">
            <h2 class="mb-3">
              <i class="bi bi-qr-code me-2"></i>My QR Code
            </h2>
            <div class="info-grid">
              <div class="info-item">
                <div class="info-label">Student Name</div>
                <div class="info-value">
                  <?= htmlspecialchars($student_data['first_name'] . ' ' . $student_data['middle_name'] . ' ' . $student_data['last_name']) ?>
                </div>
              </div>
              <div class="info-item">
                <div class="info-label">Student ID</div>
                <div class="info-value"><?= htmlspecialchars($student_data['student_id']) ?></div>
              </div>
              <div class="info-item">
                <div class="info-label">Status</div>
                <div class="info-value">
                  <span class="status-badge status-<?= strtolower($student_data['status']) ?>">
                    <?= ucfirst($student_data['status']) ?>
                  </span>
                </div>
              </div>
            </div>
          </div>

          <?php if ($has_received_aid && $distribution_details): ?>
            <!-- Distribution Receipt Display -->
            <div class="alert alert-success mb-4">
              <h4 class="alert-heading">
                <i class="bi bi-check-circle-fill me-2"></i>Aid Received Successfully!
              </h4>
              <p class="mb-0">Your QR code was scanned and verified. You have received your educational assistance.</p>
            </div>
            
            <div class="qr-display">
              <h4 class="text-success mb-4">
                <i class="bi bi-receipt me-2"></i>Distribution Receipt
              </h4>
              
              <div class="row g-3">
                <div class="col-md-6">
                  <div class="card border-primary">
                    <div class="card-body">
                      <h6 class="card-title text-primary">
                        <i class="bi bi-calendar-check me-2"></i>Distribution Information
                      </h6>
                      <hr>
                      <p class="mb-2">
                        <strong>Distribution ID:</strong><br>
                        <code class="text-muted"><?= htmlspecialchars($distribution_details['distribution_id']) ?></code>
                      </p>
                      <p class="mb-2">
                        <strong>Academic Year:</strong><br>
                        <?= htmlspecialchars($distribution_details['academic_year']) ?>
                      </p>
                      <p class="mb-2">
                        <strong>Semester:</strong><br>
                        <?= htmlspecialchars($distribution_details['semester']) ?>
                      </p>
                      <p class="mb-0">
                        <strong>Date Received:</strong><br>
                        <?= date('F j, Y', strtotime($distribution_details['distribution_date'])) ?>
                      </p>
                    </div>
                  </div>
                </div>
                
                <div class="col-md-6">
                  <div class="card border-success">
                    <div class="card-body">
                      <h6 class="card-title text-success">
                        <i class="bi bi-cash-stack me-2"></i>Payment Details
                      </h6>
                      <hr>
                      <p class="mb-2">
                        <strong>Payroll Number:</strong><br>
                        <span class="badge bg-primary fs-6">#<?= htmlspecialchars($distribution_details['payroll_number']) ?></span>
                      </p>
                      <p class="mb-2">
                        <strong>Amount Received:</strong><br>
                        <span class="fs-4 text-success fw-bold">₱<?= number_format($distribution_details['amount_received'], 2) ?></span>
                      </p>
                      <p class="mb-0">
                        <strong>Location:</strong><br>
                        <?= htmlspecialchars($distribution_details['location']) ?>
                      </p>
                    </div>
                  </div>
                </div>
              </div>
              
              <?php if ($has_qr_code): ?>
              <div class="mt-4 pt-4 border-top">
                <h5 class="text-muted mb-3">Your QR Code (For Reference)</h5>
                <img src="<?= $qr_image_url ?>" alt="Student QR Code" class="qr-image" style="max-width: 250px;"
                     onerror="this.onerror=null; this.style.display='none';">
                <div class="mt-2">
                  <small class="text-muted">
                    <i class="bi bi-info-circle me-1"></i>
                    QR Code ID: <?= htmlspecialchars($student_data['qr_unique_id']) ?>
                  </small>
                </div>
              </div>
              <?php endif; ?>
            </div>
            
            <div class="alert alert-info mt-3">
              <i class="bi bi-printer me-2"></i>
              <strong>Need a copy?</strong> You can take a screenshot of this page for your records.
            </div>
            
          <?php elseif ($has_qr_code): ?>
            <!-- QR Code Display (Not Yet Scanned) -->
            <div class="qr-display">
              <div class="payroll-badge">
                <i class="bi bi-hash me-2"></i>Payroll Number: <?= htmlspecialchars($student_data['payroll_no']) ?>
              </div>
              
              <div class="mt-4">
                <h4 class="text-primary mb-3">Your QR Code</h4>
                <img src="<?= $qr_image_url ?>" alt="Student QR Code" class="qr-image" 
                     onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='block';">
                <div style="display:none;" class="alert alert-danger">
                  <i class="bi bi-exclamation-triangle me-2"></i>Error loading QR code image
                </div>
              </div>
              
              <div class="mt-3">
                <small class="text-muted">
                  <i class="bi bi-info-circle me-1"></i>
                  QR Code ID: <?= htmlspecialchars($student_data['qr_unique_id']) ?><br>
                  Generated: <?= date('F j, Y \a\t g:i A', strtotime($student_data['qr_created_at'])) ?>
                </small>
              </div>
              
              <a href="<?= $qr_image_url ?>" download="EducAid_QR_<?= $student_data['payroll_no'] ?>.png" class="download-btn">
                <i class="bi bi-download me-2"></i>Download QR Code
              </a>
            </div>
            
            <div class="alert alert-info mt-3">
              <i class="bi bi-lightbulb me-2"></i>
              <strong>How to use:</strong> Present this QR code during verification processes or when attending scheduled activities.
            </div>
            
          <?php else: ?>
            <!-- No QR Code Message -->
            <div class="no-qr-message">
              <h4><i class="bi bi-exclamation-circle me-2"></i>No QR Code Available</h4>
              <p class="mb-3">Your QR code has not been generated yet. This usually means:</p>
              <ul class="text-start">
                <li>Your application is still being processed</li>
                <li>Payroll numbers haven't been assigned yet</li>
                <li>The admin hasn't finalized the student list</li>
              </ul>
              <div class="mt-3">
                <small class="text-muted">
                  <i class="bi bi-clock me-1"></i>
                  Please check back later or contact the admin for more information.
                </small>
              </div>
            </div>
            
            <div class="alert alert-primary">
              <i class="bi bi-info-circle me-2"></i>
              <strong>Current Status:</strong> <?= ucfirst($student_data['status']) ?><br>
              Your QR code will be automatically generated once your status becomes "Active" and payroll numbers are assigned.
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </div>
  
  <script src="../../assets/js/bootstrap.bundle.min.js"></script>
  <script src="../../assets/js/student/sidebar.js"></script>
</body>
</html>
