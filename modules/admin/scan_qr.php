<?php 
session_start();
require_once __DIR__ . '/../../config/database.php';
include_once __DIR__ . '/../../includes/workflow_control.php';

// Check admin authentication
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

// Check workflow prerequisites
$workflow_status = getWorkflowStatus($connection);
if (!$workflow_status['has_payroll_qr']) {
    header("Location: verify_students.php?error=no_payroll");
    exit;
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="student_distribution_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Payroll Number', 'Student Name', 'Student ID', 'Status', 'Distribution Date']);
    
    $csv_query = "
        SELECT s.payroll_no, s.first_name, s.middle_name, s.last_name, 
               s.student_id, s.status, d.date_given
        FROM students s
        LEFT JOIN distributions d ON s.student_id = d.student_id
        WHERE s.status IN ('active', 'given') AND s.payroll_no IS NOT NULL
        ORDER BY s.payroll_no ASC
    ";
    
    $csv_result = pg_query($connection, $csv_query);
    while ($row = pg_fetch_assoc($csv_result)) {
        $full_name = trim($row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['last_name']);
        $distribution_date = $row['date_given'] ? date('Y-m-d', strtotime($row['date_given'])) : '';
        fputcsv($output, [
            $row['payroll_no'],
            $full_name,
            $row['student_id'],
            ucfirst($row['status']),
            $distribution_date
        ]);
    }
    
    fclose($output);
    exit;
}

// Handle QR scan confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_distribution'])) {
    $student_id = $_POST['student_id'];
    $admin_id = $_SESSION['admin_id'] ?? 1;
    
    // Update student status to 'given'
    $update_query = "UPDATE students SET status = 'given' WHERE student_id = $1";
    $update_result = pg_query_params($connection, $update_query, [$student_id]);
    
    if ($update_result) {
        // Record distribution
        $dist_query = "INSERT INTO distributions (student_id, date_given, verified_by) VALUES ($1, NOW(), $2)";
        pg_query_params($connection, $dist_query, [$student_id, $admin_id]);
        
        // Add notification to student
        $notif_query = "INSERT INTO notifications (student_id, message) VALUES ($1, $2)";
        $notif_message = "Your scholarship aid has been successfully distributed. Thank you for participating in the EducAid program.";
        pg_query_params($connection, $notif_query, [$student_id, $notif_message]);
        
        echo json_encode(['success' => true, 'message' => 'Distribution confirmed successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update student status']);
    }
    exit;
}

// Handle QR code lookup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lookup_qr'])) {
    error_log("QR Lookup started for: " . $_POST['qr_code']);
    
    $qr_unique_id = $_POST['qr_code'];
    
    $lookup_query = "
        SELECT s.student_id, s.first_name, s.middle_name, s.last_name, 
               s.payroll_no, s.status,
               b.name as barangay_name, u.name as university_name, yl.name as year_level_name
        FROM students s
        LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
        LEFT JOIN universities u ON s.university_id = u.university_id
        LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
        JOIN qr_codes q ON s.student_id = q.student_id AND s.payroll_no = q.payroll_number
        WHERE q.unique_id = $1 AND s.status = 'active'
    ";
    
    $lookup_result = pg_query_params($connection, $lookup_query, [$qr_unique_id]);
    
    if (!$lookup_result) {
        error_log("Database query failed: " . pg_last_error($connection));
        echo json_encode(['success' => false, 'message' => 'Database error occurred']);
        exit;
    }
    
    if (pg_num_rows($lookup_result) > 0) {
        $student = pg_fetch_assoc($lookup_result);
        error_log("Student found: " . $student['student_id']);
        echo json_encode(['success' => true, 'student' => $student]);
    } else {
        error_log("No student found for QR: " . $qr_unique_id);
        echo json_encode(['success' => false, 'message' => 'QR code not found or student not eligible for distribution']);
    }
    exit;
}

// Fetch all students with payroll numbers for the table
$students_query = "
    SELECT s.student_id, s.payroll_no, s.first_name, s.middle_name, s.last_name, 
           s.status, q.unique_id as qr_unique_id,
           d.date_given
    FROM students s
    LEFT JOIN qr_codes q ON s.student_id = q.student_id AND s.payroll_no = q.payroll_number
    LEFT JOIN distributions d ON s.student_id = d.student_id
    WHERE s.status IN ('active', 'given') AND s.payroll_no IS NOT NULL
    ORDER BY s.payroll_no ASC
";

$students_result = pg_query($connection, $students_query);
$students = [];
if ($students_result) {
    while ($row = pg_fetch_assoc($students_result)) {
        $students[] = $row;
    }
}
?>

<?php $page_title='QR Code Scanner'; include '../../includes/admin/admin_head.php'; ?>
  <style>
    body { font-family: 'Poppins', sans-serif; }
    #reader { 
      width: 100%; 
      max-width: 500px; 
      margin: 0 auto 20px auto;
      border: 2px solid #007bff;
      border-radius: 10px;
    }
    .controls { 
      text-align: center; 
      margin: 20px 0; 
    }
    .status-active { background-color: #d4edda; color: #155724; }
    .status-given { background-color: #f8d7da; color: #721c24; }
    .table-container {
      max-height: 600px;
      overflow-y: auto;
      border: 1px solid #dee2e6;
      border-radius: 5px;
    }
    .export-section {
      margin-bottom: 20px;
      text-align: right;
    }
    .scanner-section {
      background: white;
      padding: 30px;
      border-radius: 10px;
      box-shadow: 0 5px 15px rgba(0,0,0,0.1);
      margin-bottom: 30px;
    }
    /* Ensure loading modal doesn't interfere with other modals */
    #loadingModal {
      z-index: 1040;
    }
    #qrConfirmModal {
      z-index: 1050;
    }
  </style>
  </head>
<body>
  <?php include '../../includes/admin/admin_topbar.php'; ?>
  <div id="wrapper" class="admin-wrapper">
    <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
    <?php include '../../includes/admin/admin_header.php'; ?>
    <section class="home-section" id="page-content-wrapper">
      <div class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
          <h1><i class="bi bi-qr-code-scan me-2"></i>QR Code Scanner & Distribution</h1>
        </div>

        <!-- Scanner Section -->
        <div class="scanner-section">
          <h3 class="text-center mb-4"><i class="bi bi-camera me-2"></i>Scan Student QR Code</h3>
          <div id="reader"></div>
          <div class="controls">
            <select id="camera-select" class="form-select w-auto d-inline-block me-2">
              <option value="">Select Camera</option>
            </select>
            <button id="start-button" class="btn btn-success me-2">
              <i class="bi bi-play-fill me-1"></i>Start Scanner
            </button>
            <button id="stop-button" class="btn btn-danger me-2" disabled>
              <i class="bi bi-stop-fill me-1"></i>Stop Scanner
            </button>
          </div>
          <div class="alert alert-info mt-3">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Instructions:</strong> Point the camera at a student's QR code to identify them and confirm aid distribution.
          </div>
        </div>

        <!-- Export Section -->
        <div class="export-section">
          <a href="?export=csv" class="btn btn-success">
            <i class="bi bi-download me-2"></i>Export to CSV
          </a>
        </div>

        <!-- Students Table -->
        <div class="card">
          <div class="card-header">
            <h3 class="mb-0"><i class="bi bi-people-fill me-2"></i>Students with Payroll Numbers</h3>
            <small class="text-muted">Total: <?= count($students) ?> students</small>
          </div>
          <div class="card-body p-0">
            <div class="table-container">
              <table class="table table-striped table-hover mb-0" id="studentsTable">
                <thead class="table-dark sticky-top">
                  <tr>
                    <th>Payroll #</th>
                    <th>Student Name</th>
                    <th>Student ID</th>
                    <th>Status</th>
                    <th>Distribution Date</th>
                    <th>QR Code</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($students as $student): ?>
                    <tr id="student-<?= $student['student_id'] ?>">
                      <td><strong><?= htmlspecialchars($student['payroll_no']) ?></strong></td>
                      <td><?= htmlspecialchars(trim($student['first_name'] . ' ' . $student['middle_name'] . ' ' . $student['last_name'])) ?></td>
                      <td><code><?= htmlspecialchars($student['student_id']) ?></code></td>
                      <td>
                        <span class="badge status-<?= $student['status'] ?>">
                          <?= ucfirst($student['status']) ?>
                        </span>
                      </td>
                      <td>
                        <?php if ($student['date_given']): ?>
                          <?= date('M j, Y', strtotime($student['date_given'])) ?>
                        <?php else: ?>
                          <span class="text-muted">Not distributed</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if ($student['qr_unique_id']): ?>
                          <i class="bi bi-qr-code text-success" title="QR Code Available"></i>
                        <?php else: ?>
                          <i class="bi bi-x-circle text-danger" title="No QR Code"></i>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>

  <!-- QR Code Confirmation Modal -->
  <div class="modal fade" id="qrConfirmModal" tabindex="-1" aria-labelledby="qrConfirmModalLabel" aria-hidden="true" data-bs-backdrop="false">
    <div class="modal-dialog modal-lg">
      <div class="modal-content border border-primary" style="box-shadow: 0 10px 30px rgba(0,0,0,0.3);">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title" id="qrConfirmModalLabel">
            <i class="bi bi-qr-code-scan me-2"></i>Confirm Aid Distribution
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div id="studentInfo">
            <!-- Student information will be loaded here -->
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
            <i class="bi bi-x-circle me-1"></i>Cancel
          </button>
          <button type="button" class="btn btn-success" id="confirmDistribution">
            <i class="bi bi-check-circle me-1"></i>Confirm Distribution
          </button>
          <button type="button" class="btn btn-warning btn-sm ms-2" id="resetButton" style="display: none;" onclick="resetConfirmButton()">
            <i class="bi bi-arrow-clockwise me-1"></i>Reset
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Loading Modal -->
  <div class="modal fade" id="loadingModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="false" data-bs-keyboard="false">
    <div class="modal-dialog modal-sm">
      <div class="modal-content border border-info" style="box-shadow: 0 10px 30px rgba(0,0,0,0.3);">
        <div class="modal-body text-center">
          <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
          </div>
          <p class="mt-3 mb-0">Processing QR Code...</p>
          <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="forceCloseLoading" style="display: none;" onclick="clearModalIssues()">
            <i class="bi bi-x-circle me-1"></i>Close
          </button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://unpkg.com/html5-qrcode"></script>
  <script src="../../assets/js/admin/sidebar.js"></script>
  <script src="../../assets/js/bootstrap.bundle.min.js"></script>
  <script>
    const startButton = document.getElementById('start-button');
    const stopButton = document.getElementById('stop-button');
    const cameraSelect = document.getElementById('camera-select');
    const html5QrCode = new Html5Qrcode("reader");
    let currentCameraId = null;
    let currentStudentData = null;
    
    // Initialize camera selection
    Html5Qrcode.getCameras().then(cameras => {
      if (!cameras.length) {
        alert("No cameras found.");
        return;
      }
      cameras.forEach(camera => {
        const option = document.createElement('option');
        option.value = camera.id;
        option.text = camera.label || `Camera ${camera.id}`;
        cameraSelect.appendChild(option);
      });
      
      // Prefer back camera
      const backCam = cameras.find(cam => cam.label.toLowerCase().includes('back'));
      if (backCam) {
        cameraSelect.value = backCam.id;
        currentCameraId = backCam.id;
      } else if (cameras.length > 0) {
        cameraSelect.value = cameras[0].id;
        currentCameraId = cameras[0].id;
      }
    }).catch(err => {
      console.error("Error getting cameras:", err);
    });
    
    cameraSelect.addEventListener('change', () => {
      currentCameraId = cameraSelect.value;
    });

    // Start scanner
    startButton.addEventListener('click', () => {
      if (!currentCameraId) {
        alert("Please select a camera.");
        return;
      }
      
      html5QrCode.start(
        currentCameraId,
        { 
          fps: 10, 
          qrbox: { width: 300, height: 300 },
          aspectRatio: 1.0
        },
        decodedText => {
          // QR code detected
          console.log("QR Code detected:", decodedText);
          
          // Immediately disable scanner to prevent multiple scans
          startButton.disabled = true;
          stopButton.disabled = true;
          
          // Stop scanner first
          html5QrCode.stop().then(() => {
            // Reset buttons after stopping
            startButton.disabled = false;
            stopButton.disabled = true;
            
            // Show loading modal with slight delay to ensure scanner is stopped
            setTimeout(() => {
              const loadingModal = new bootstrap.Modal(document.getElementById('loadingModal'), {
                backdrop: false,  // NO BACKDROP!
                keyboard: false
              });
              loadingModal.show();
              
              // Show force close button after 3 seconds
              setTimeout(() => {
                const forceCloseBtn = document.getElementById('forceCloseLoading');
                if (forceCloseBtn) {
                  forceCloseBtn.style.display = 'inline-block';
                }
              }, 3000);
              
              // Lookup student info
              lookupQRCode(decodedText);
            }, 100);
            
          }).catch(err => {
            console.error("Error stopping scanner:", err);
            startButton.disabled = false;
            stopButton.disabled = true;
            
            // Still try to lookup even if stop failed
            const loadingModal = new bootstrap.Modal(document.getElementById('loadingModal'), {
              backdrop: false,  // NO BACKDROP!
              keyboard: false
            });
            loadingModal.show();
            
            // Show force close button after 3 seconds
            setTimeout(() => {
              const forceCloseBtn = document.getElementById('forceCloseLoading');
              if (forceCloseBtn) {
                forceCloseBtn.style.display = 'inline-block';
              }
            }, 3000);
            
            lookupQRCode(decodedText);
          });
        },
        error => {
          // Ignore decode errors (happens frequently during scanning)
          // Only log if it's not a common decode error
          if (!error.includes('NotFoundException') && !error.includes('No MultiFormat Readers')) {
            console.log("Scanner error:", error);
          }
        }
      ).then(() => {
        startButton.disabled = true;
        stopButton.disabled = false;
        console.log("Scanner started successfully");
      }).catch(err => {
        console.error("Failed to start scanning:", err);
        alert("Failed to start camera. Please check permissions and try again.");
        startButton.disabled = false;
        stopButton.disabled = true;
      });
    });

    // Stop scanner
    stopButton.addEventListener('click', () => {
      html5QrCode.stop()
        .then(() => {
          startButton.disabled = false;
          stopButton.disabled = true;
        })
        .catch(err => console.error("Failed to stop scanning:", err));
    });

    // Lookup QR code
    function lookupQRCode(qrCode) {
      console.log('Looking up QR code:', qrCode);
      
      // Set a timeout to hide loading modal if it takes too long
      const timeoutId = setTimeout(() => {
        clearModalIssues(); // Use our emergency clear function
        alert('Request timed out. Please try again.');
      }, 10000); // 10 second timeout
      
      fetch('scan_qr.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'lookup_qr=1&qr_code=' + encodeURIComponent(qrCode)
      })
      .then(response => {
        clearTimeout(timeoutId);
        console.log('Response status:', response.status);
        
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        return response.text().then(text => {
          console.log('Raw response:', text);
          try {
            return JSON.parse(text);
          } catch (e) {
            console.error('JSON parse error:', e);
            throw new Error('Invalid JSON response: ' + text);
          }
        });
      })
      .then(data => {
        // Hide loading modal properly
        const loadingModal = bootstrap.Modal.getInstance(document.getElementById('loadingModal'));
        if (loadingModal) {
          loadingModal.hide();
        }
        
        console.log('Parsed data:', data);
        
        if (data.success) {
          currentStudentData = data.student;
          showStudentModal(data.student);
        } else {
          clearModalIssues(); // Clear any modal issues before showing alert
          alert(data.message || 'QR code not found or student not eligible');
        }
      })
      .catch(error => {
        clearTimeout(timeoutId);
        clearModalIssues(); // Clear any modal issues on error
        console.error('Fetch error:', error);
        alert('Error processing QR code: ' + error.message);
      });
    }

    // Show student confirmation modal
    function showStudentModal(student) {
      // Force hide loading modal first
      const loadingModal = bootstrap.Modal.getInstance(document.getElementById('loadingModal'));
      if (loadingModal) {
        loadingModal.hide();
      }
      
      // Remove any leftover backdrops just in case
      setTimeout(() => {
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(backdrop => backdrop.remove());
      }, 100);
      
      // Wait a moment for cleanup
      setTimeout(() => {
        const modalBody = document.getElementById('studentInfo');
        modalBody.innerHTML = `
          <div class="row">
            <div class="col-md-6">
              <h6 class="text-primary">Student Information</h6>
              <table class="table table-sm">
                <tr><td><strong>Name:</strong></td><td>${student.first_name} ${student.middle_name || ''} ${student.last_name}</td></tr>
                <tr><td><strong>Student ID:</strong></td><td><code>${student.student_id}</code></td></tr>
                <tr><td><strong>Payroll Number:</strong></td><td><span class="badge bg-primary">${student.payroll_no}</span></td></tr>
              </table>
            </div>
            <div class="col-md-6">
              <h6 class="text-primary">Additional Details</h6>
              <table class="table table-sm">
                <tr><td><strong>Status:</strong></td><td><span class="badge bg-success">${student.status.toUpperCase()}</span></td></tr>
                <tr><td><strong>Barangay:</strong></td><td>${student.barangay_name || 'N/A'}</td></tr>
                <tr><td><strong>University:</strong></td><td>${student.university_name || 'N/A'}</td></tr>
                <tr><td><strong>Year Level:</strong></td><td>${student.year_level_name || 'N/A'}</td></tr>
              </table>
            </div>
          </div>
          <div class="alert alert-warning mt-3">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Confirm Distribution:</strong> Are you sure you want to mark this student's aid as distributed? 
            This action will change their status to "Given" and cannot be easily undone.
          </div>
        `;
        
        // Create modal WITHOUT backdrop
        const modal = new bootstrap.Modal(document.getElementById('qrConfirmModal'), {
          backdrop: false,  // NO BACKDROP!
          keyboard: true,
          focus: true
        });
        modal.show();
      }, 300);
    }

    // Emergency function to clear all modal issues
    function clearModalIssues() {
      console.log('Clearing all modal issues...');
      
      // Hide force close button
      const forceCloseBtn = document.getElementById('forceCloseLoading');
      if (forceCloseBtn) {
        forceCloseBtn.style.display = 'none';
      }
      
      // Hide all modals immediately
      const allModals = document.querySelectorAll('.modal');
      allModals.forEach(modal => {
        const modalInstance = bootstrap.Modal.getInstance(modal);
        if (modalInstance) {
          modalInstance.hide();
        }
        // Force hide the modal element directly
        modal.style.display = 'none';
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
      });
      
      // Remove all backdrops aggressively
      setTimeout(() => {
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(backdrop => backdrop.remove());
      }, 50);
      
      setTimeout(() => {
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(backdrop => backdrop.remove());
      }, 200);
      
      // Reset body styles
      document.body.classList.remove('modal-open');
      document.body.style.overflow = '';
      document.body.style.paddingRight = '';
      document.body.style.marginRight = '';
      
      console.log('All modal issues cleared');
    }
    
    // Add emergency key combination (Ctrl+Shift+C) to clear modal issues
    document.addEventListener('keydown', (e) => {
      if (e.ctrlKey && e.shiftKey && e.key === 'C') {
        clearModalIssues();
        alert('Modal issues cleared! You can now use the interface normally.');
      }
    });

    // Reset confirm button function
    function resetConfirmButton() {
      const button = document.getElementById('confirmDistribution');
      const resetBtn = document.getElementById('resetButton');
      
      button.disabled = false;
      button.innerHTML = '<i class="bi bi-check-circle me-1"></i>Confirm Distribution';
      resetBtn.style.display = 'none';
      
      console.log('Confirm button has been reset');
    }

    // Confirm distribution
    document.getElementById('confirmDistribution').addEventListener('click', () => {
      if (!currentStudentData) {
        alert('No student data available. Please scan a QR code first.');
        return;
      }
      
      const button = document.getElementById('confirmDistribution');
      const resetBtn = document.getElementById('resetButton');
      const originalText = button.innerHTML;
      
      console.log('Confirming distribution for student:', currentStudentData.student_id);
      
      button.disabled = true;
      button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Processing...';
      resetBtn.style.display = 'inline-block'; // Show reset button
      
      // Add timeout for the confirmation request
      const confirmTimeoutId = setTimeout(() => {
        button.disabled = false;
        button.innerHTML = originalText;
        resetBtn.style.display = 'none';
        alert('Confirmation request timed out. Please try again.');
      }, 15000); // 15 second timeout
      
      fetch('scan_qr.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'confirm_distribution=1&student_id=' + encodeURIComponent(currentStudentData.student_id)
      })
      .then(response => {
        clearTimeout(confirmTimeoutId);
        console.log('Confirmation response status:', response.status);
        
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        return response.text().then(text => {
          console.log('Confirmation raw response:', text);
          try {
            return JSON.parse(text);
          } catch (e) {
            console.error('JSON parse error in confirmation:', e);
            throw new Error('Invalid JSON response: ' + text);
          }
        });
      })
      .then(data => {
        console.log('Confirmation parsed data:', data);
        
        if (data.success) {
          // Force hide ALL modals immediately
          clearModalIssues();
          
          // Update table row
          updateStudentRow(currentStudentData.student_id);
          
          // Show success message
          showSuccessMessage('Distribution confirmed successfully!');
          
          // Reset current student data
          currentStudentData = null;
        } else {
          alert(data.message || 'Failed to confirm distribution');
        }
      })
      .catch(error => {
        clearTimeout(confirmTimeoutId);
        console.error('Confirmation error:', error);
        alert('Error confirming distribution: ' + error.message);
      })
      .finally(() => {
        // Always re-enable the button and hide reset button
        const resetBtn = document.getElementById('resetButton');
        button.disabled = false;
        button.innerHTML = originalText;
        resetBtn.style.display = 'none';
      });
    });

    // Update student row in table
    function updateStudentRow(studentId) {
      const row = document.getElementById(`student-${studentId}`);
      if (row) {
        // Update status badge
        const statusCell = row.cells[3];
        statusCell.innerHTML = '<span class="badge status-given">Given</span>';
        
        // Update distribution date
        const dateCell = row.cells[4];
        const today = new Date().toLocaleDateString('en-US', { 
          year: 'numeric', 
          month: 'short', 
          day: 'numeric' 
        });
        dateCell.textContent = today;
        
        // Highlight row briefly
        row.classList.add('table-success');
        setTimeout(() => {
          row.classList.remove('table-success');
        }, 3000);
      }
    }

    // Show success message
    function showSuccessMessage(message) {
      const alertDiv = document.createElement('div');
      alertDiv.className = 'alert alert-success alert-dismissible fade show position-fixed';
      alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
      alertDiv.innerHTML = `
        <i class="bi bi-check-circle me-2"></i>${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      `;
      
      document.body.appendChild(alertDiv);
      
      setTimeout(() => {
        if (alertDiv.parentNode) {
          alertDiv.remove();
        }
      }, 5000);
    }
  </script>
</body>
</html>
