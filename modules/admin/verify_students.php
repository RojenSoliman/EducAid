<?php
include __DIR__ . '/../../config/database.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

/* ---------------------------
   CONFIG / STATE
----------------------------*/
$isFinalized = false;
$configResult = pg_query($connection, "SELECT value FROM config WHERE key = 'student_list_finalized'");
if ($configResult && $row = pg_fetch_assoc($configResult)) {
    $isFinalized = ($row['value'] === '1');
}

/* ---------------------------
   HELPERS
----------------------------*/
function fetch_students($connection, $status, $sort, $barangayFilter) {
    $query = "
        SELECT s.student_id, s.first_name, s.middle_name, s.last_name, s.mobile, s.email,
               b.name AS barangay, s.payroll_no, s.unique_student_id,
               (
                 SELECT unique_id FROM qr_codes q2
                 WHERE q2.student_unique_id = s.unique_student_id AND q2.payroll_number = s.payroll_no
                 ORDER BY q2.qr_id DESC LIMIT 1
               ) AS unique_id
        FROM students s
        JOIN barangays b ON s.barangay_id = b.barangay_id
        WHERE s.status = $1";
    $params = [$status];
    if (!empty($barangayFilter)) {
        $query .= " AND b.barangay_id = $2";
        $params[] = $barangayFilter;
    }
    $query .= " ORDER BY s.last_name " . ($sort === 'desc' ? 'DESC' : 'ASC');
    return pg_query_params($connection, $query, $params);
}

/* ---------------------------
   POST ACTIONS
----------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Revert selected actives back to applicants
    if (isset($_POST['deactivate']) && !empty($_POST['selected_actives'])) {
        $count = count($_POST['selected_actives']);
        foreach ($_POST['selected_actives'] as $student_id) {
            pg_query_params($connection, "UPDATE students SET status = 'applicant' WHERE student_id = $1", [$student_id]);
        }
        // Reset finalized flag
        pg_query($connection, "UPDATE config SET value = '0' WHERE key = 'student_list_finalized'");
        $isFinalized = false;
        
        // Add admin notification
        $notification_msg = "Reverted " . $count . " student(s) from active to applicant status";
        pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
        
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // Finalize list (for payroll generation)
    if (isset($_POST['finalize_list'])) {
        pg_query($connection, "UPDATE config SET value = '1' WHERE key = 'student_list_finalized'");
        $isFinalized = true;
        
        // Add admin notification
        $notification_msg = "Student list has been finalized";
        pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
    }

    // Revert list (optionally reset payroll numbers)
    if (isset($_POST['revert_list'])) {
        pg_query($connection, "UPDATE config SET value = '0' WHERE key = 'student_list_finalized'");
        $isFinalized = false;
        if (isset($_POST['reset_payroll'])) {
            pg_query($connection, "UPDATE students SET payroll_no = 0 WHERE status = 'active'");
            $notification_msg = "Student list reverted and payroll numbers reset";
        } else {
            $notification_msg = "Student list reverted (payroll numbers preserved)";
        }
        
        // Add admin notification
        pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
    }

    // Generate payroll numbers (sorted A→Z by full name) and QR codes
    if (isset($_POST['generate_payroll'])) {
        // 1. Assign payroll numbers
        $result = pg_query($connection, "
            SELECT student_id, unique_student_id
            FROM students
            WHERE status = 'active'
            ORDER BY last_name ASC, first_name ASC, middle_name ASC
        ");
        $payroll_no = 1;
        $student_payrolls = [];
        if ($result) {
            while ($row = pg_fetch_assoc($result)) {
                $student_id = $row['student_id'];
                $unique_student_id = $row['unique_student_id'];
                // Assign payroll number
                pg_query_params(
                    $connection,
                    "UPDATE students SET payroll_no = $1 WHERE student_id = $2",
                    [$payroll_no, $student_id]
                );
                $student_payrolls[] = [
                    'unique_student_id' => $unique_student_id,
                    'payroll_no' => $payroll_no
                ];
                $payroll_no++;
            }
            
            // Add admin notification
            $total_assigned = $payroll_no - 1;
            $notification_msg = "Payroll numbers generated for " . $total_assigned . " active students";
            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
        }
        // 2. Immediately create QR code records for each student/payroll with a unique_id
        foreach ($student_payrolls as $sp) {
            $qr_exists = pg_query_params(
                $connection,
                "SELECT qr_id FROM qr_codes WHERE student_unique_id = $1 AND payroll_number = $2",
                [$sp['unique_student_id'], $sp['payroll_no']]
            );
            if (!pg_fetch_assoc($qr_exists)) {
                $unique_id = 'qr_' . uniqid();
                pg_query_params(
                    $connection,
                    "INSERT INTO qr_codes (payroll_number, student_unique_id, unique_id, status, created_at) VALUES ($1, $2, $3, 'Pending', NOW())",
                    [$sp['payroll_no'], $sp['unique_student_id'], $unique_id]
                );
            }
        }
        echo "<script>alert('Payroll numbers and QR codes generated successfully!'); window.location.href='verify_students.php';</script>";
        exit;
    }
}

/* ---------------------------
   FILTERS
----------------------------*/
$sort = $_GET['sort'] ?? 'asc';
$barangayFilter = $_GET['barangay'] ?? '';
$searchSurname = trim($_GET['search_surname'] ?? '');

/* Active list for table (search by surname handled separately below if needed) */
$query = "SELECT * FROM students WHERE status = 'active'";
$params = [];
if (!empty($searchSurname)) {
    $query .= " AND last_name ILIKE $1";
    $params[] = "%$searchSurname%";
}
$query .= " ORDER BY last_name " . ($sort === 'desc' ? 'DESC' : 'ASC');
$activesRaw = !empty($params) ? pg_query_params($connection, $query, $params) : pg_query($connection, $query);

/* Barangay options */
$barangayOptions = [];
$barangayResult = pg_query($connection, "SELECT barangay_id, name FROM barangays ORDER BY name ASC");
while ($row = pg_fetch_assoc($barangayResult)) {
    $barangayOptions[] = $row;
}

/* Check if all actives have payroll numbers when finalized */
$allHavePayroll = false;
if ($isFinalized) {
    $payrollCheck = pg_query($connection, "
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN payroll_no > 0 THEN 1 ELSE 0 END) AS with_payroll
        FROM students
        WHERE status = 'active'
    ");
    $row = pg_fetch_assoc($payrollCheck);
    if ($row && (int)$row['total'] > 0 && (int)$row['total'] === (int)$row['with_payroll']) {
        $allHavePayroll = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Verify Students</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <!-- CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet"/>
  <link rel="stylesheet" href="../../assets/css/admin/homepage.css"/>
  <link rel="stylesheet" href="../../assets/css/admin/sidebar.css"/>
  <link rel="stylesheet" href="../../assets/css/admin/verify_students.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet"/>
</head>
<body>
<div id="wrapper">
 <?php include '../../includes/admin/admin_sidebar.php'; ?>
  <div class="sidebar-backdrop d-none" id="sidebar-backdrop"></div>
  <section class="home-section" id="mainContent">
    <nav>
      <div class="sidebar-toggle px-4 py-3">
        <i class="bi bi-list" id="menu-toggle"></i>
      </div>
    </nav>

    <div class="container-fluid py-4 px-4">
      <div class="section-header mb-3">
        <h2 class="fw-bold">
          <i class="bi bi-clipboard-check me-2"></i> Manage Student Status
        </h2>
        <p class="text-muted mb-0">Finalize the active list for payroll generation, or revert students back to applicants.</p>
      </div>

      <!-- Filters -->
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <form method="GET" class="row g-3">
            <div class="col-md-4">
              <label class="form-label fw-semibold" style="color:#1182FF;">Sort by Surname</label>
              <select name="sort" class="form-select">
                <option value="asc"  <?= $sort === 'asc'  ? 'selected' : '' ?>>A to Z</option>
                <option value="desc" <?= $sort === 'desc' ? 'selected' : '' ?>>Z to A</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold" style="color:#1182FF;">Search by Surname</label>
              <input type="text" name="search_surname" class="form-control" value="<?= htmlspecialchars($searchSurname) ?>" placeholder="Enter surname...">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold" style="color:#1182FF;">Filter by Barangay</label>
              <select name="barangay" class="form-select">
                <option value="">All Barangays</option>
                <?php foreach ($barangayOptions as $b): ?>
                  <option value="<?= $b['barangay_id'] ?>" <?= $barangayFilter == $b['barangay_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($b['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 d-flex align-items-end">
              <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-funnel me-1"></i> Apply Filters
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Active Students -->
      <form method="POST" id="activeStudentsForm">
        <div class="card shadow-sm">
          <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
            <span><i class="bi bi-people-fill me-2"></i>Active Students</span>
            <span class="badge bg-light text-success"><?= $isFinalized ? 'Finalized' : 'Not finalized' ?></span>
          </div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-hover table-bordered align-middle">
                <thead class="table-light">
                  <tr>
                    <th style="width:44px;">
                      <input type="checkbox" id="selectAllActive" <?= $isFinalized ? 'disabled' : '' ?>>
                    </th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Mobile Number</th>
                    <th>Barangay</th>
                    <th class="payroll-col<?= $isFinalized ? '' : ' d-none' ?>">Payroll #</th>
                    <th class="qr-col<?= $isFinalized ? '' : ' d-none' ?>">QR Code</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  // Re-apply barangay filter and sort using helper to ensure consistent join with barangays
                  $actives = fetch_students($connection, 'active', $sort, $barangayFilter);
                  if ($actives && pg_num_rows($actives) > 0):
                    while ($row = pg_fetch_assoc($actives)):
                      $id       = (int)$row['student_id'];
                      $name     = htmlspecialchars($row['last_name'] . ', ' . $row['first_name'] . ' ' . $row['middle_name']);
                      $email    = htmlspecialchars($row['email'] ?? '');
                      $mobile   = htmlspecialchars($row['mobile'] ?? '');
                      $barangay = htmlspecialchars($row['barangay'] ?? '');
                  $payroll  = htmlspecialchars((string)($row['payroll_no'] ?? ''));
                  $qr_img = '';
                  $unique_id = $row['unique_id'];
                  // If either payroll_no or QR code is missing, assign both at the same time
                  if (empty($row['payroll_no']) || empty($unique_id)) {
                    // Find the next available payroll number (max + 1)
                    $payrollResult = pg_query($connection, "SELECT MAX(payroll_no) AS max_payroll FROM students WHERE status = 'active'");
                    $maxPayroll = 0;
                    if ($payrollResult && $pr = pg_fetch_assoc($payrollResult)) {
                      $maxPayroll = (int)($pr['max_payroll'] ?? 0);
                    }
                    $newPayroll = $maxPayroll + 1;
                    // Assign payroll number if missing
                    if (empty($row['payroll_no'])) {
                      pg_query_params($connection, "UPDATE students SET payroll_no = $1 WHERE student_id = $2", [$newPayroll, $id]);
                      $payroll = htmlspecialchars((string)$newPayroll);
                    }
                    // Assign QR code if missing
                    if (empty($unique_id)) {
                      $unique_id = 'qr_' . uniqid();
                      pg_query_params(
                        $connection,
                        "INSERT INTO qr_codes (payroll_number, student_unique_id, unique_id, status, created_at) VALUES ($1, $2, $3, 'Pending', NOW())",
                        [$newPayroll, $row['unique_student_id'], $unique_id]
                      );
                    }
                    // No need to fetch again, unique_id is now correct for this payroll
                  }
                  if (!empty($payroll) && !empty($unique_id)) {
                    $qr_img = '../../modules/admin/phpqrcode/generate_qr.php?data=' . urlencode($unique_id);
                  }
                  ?>
                      <tr>
                        <td>
                          <input type="checkbox" name="selected_actives[]" value="<?= $id ?>" <?= $isFinalized ? 'disabled' : '' ?>>
                        </td>
                        <td><?= $name ?></td>
                        <td><?= $email ?></td>
                        <td><?= $mobile ?></td>
                        <td><?= $barangay ?></td>
                        <td class="payroll-col" <?= $isFinalized ? '' : 'style=\"display:none;\"' ?>><?= $payroll ?></td>
                        <td class="qr-col" <?= $isFinalized ? '' : 'style=\"display:none;\"' ?>>
                          <?php if ($qr_img): ?>
                            <img src="<?= $qr_img ?>" alt="QR Code" style="width:60px;height:60px;" onerror="this.onerror=null;this.src='';this.nextElementSibling.style.display='inline';" />
                            <span class="text-danger" style="display:none;">QR Error</span>
                          <?php else: ?>
                            <span class="text-muted">N/A</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                  <?php
                    endwhile;
                  else:
                      echo '<tr><td colspan="7" class="text-center text-muted">No active students found.</td></tr>';
                  endif;
                  ?>
                </tbody>
              </table>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-2">
              <button type="submit" name="deactivate" class="btn btn-danger" id="revertBtn" <?= $isFinalized ? 'disabled' : '' ?>>
                <i class="bi bi-arrow-counterclockwise me-1"></i> Revert to Applicant
              </button>

              <?php if ($isFinalized): ?>
                <button type="button" class="btn btn-warning" id="revertTriggerBtn">
                  <i class="bi bi-backspace-reverse-fill me-1"></i> Revert List
                </button>
                <button type="button" class="btn btn-primary ms-auto" id="generatePayrollBtn" <?= $allHavePayroll ? 'disabled' : '' ?>>
                  <i class="bi bi-gear me-1"></i> Generate Payroll Numbers
                </button>
              <?php else: ?>
                <button type="button" class="btn btn-success" id="finalizeTriggerBtn">
                  <i class="bi bi-check2-circle me-1"></i> Finalize List
                </button>
                <input type="hidden" name="finalize_list" id="finalizeListInput" value="">
                <button type="button" class="btn btn-primary ms-auto d-none" id="generatePayrollBtn">
                  <i class="bi bi-gear me-1"></i> Generate Payroll Numbers
                </button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </form>
    </div>
  </section>
</div>

<!-- Finalize Modal -->
<div class="modal fade" id="finalizeModal" tabindex="-1" aria-labelledby="finalizeModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="finalizeModalLabel">Finalize Student List</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        Are you sure you want to finalize the student list for payroll number generation?
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="finalizeConfirmBtnModal">Finalize</button>
      </div>
    </div>
  </div>
</div>

<!-- Generate Payroll Modal -->
<div class="modal fade" id="generatePayrollModal" tabindex="-1" aria-labelledby="generatePayrollModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="generatePayrollModalLabel">Generate Payroll Numbers</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        Generate payroll numbers for all active students (A→Z by name). This will overwrite any existing payroll numbers.
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="generatePayrollConfirmBtnModal">Generate</button>
      </div>
    </div>
  </div>
</div>

<!-- Revert List Modal -->
<div class="modal fade" id="revertListModal" tabindex="-1" aria-labelledby="revertListModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="revertListModalLabel">Revert List</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        Revert the finalized list? Payroll numbers for actives will be reset to 0.
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="revertListConfirmBtnModal">Yes, Revert</button>
      </div>
    </div>
  </div>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>
<script>
  // ====== Finalize flow ======
  function finalizeHandler(e) {
    e.preventDefault();
    new bootstrap.Modal(document.getElementById('finalizeModal')).show();
  }
  document.getElementById('finalizeTriggerBtn')?.addEventListener('click', finalizeHandler);
  document.getElementById('finalizeConfirmBtnModal')?.addEventListener('click', function () {
    document.getElementById('finalizeListInput').value = '1';
    document.getElementById('activeStudentsForm').submit();
  });

  // ====== Generate Payroll flow ======
  document.getElementById('generatePayrollBtn')?.addEventListener('click', function () {
    new bootstrap.Modal(document.getElementById('generatePayrollModal')).show();
  });
  document.getElementById('generatePayrollConfirmBtnModal')?.addEventListener('click', function () {
    // Hidden POST form
    var form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'generate_payroll';
    input.value = '1';
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
  });

  // ====== Revert List flow ======
  document.getElementById('revertTriggerBtn')?.addEventListener('click', function (e) {
    e.preventDefault();
    new bootstrap.Modal(document.getElementById('revertListModal')).show();
  });
  document.getElementById('revertListConfirmBtnModal')?.addEventListener('click', function () {
    var form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    var input1 = document.createElement('input');
    input1.type = 'hidden';
    input1.name = 'revert_list';
    input1.value = '1';
    var input2 = document.createElement('input');
    input2.type = 'hidden';
    input2.name = 'reset_payroll';
    input2.value = '1';
    form.appendChild(input1);
    form.appendChild(input2);
    document.body.appendChild(form);
    form.submit();
  });

  // ====== Select-all for active students ======
  window.addEventListener('load', function() {
    var isFinalized = <?= $isFinalized ? 'true' : 'false' ?>;
    // Disable/enable inputs based on finalized
    document.querySelectorAll("input[name='selected_actives[]']").forEach(cb => cb.disabled = isFinalized);
    var revertBtn = document.getElementById('revertBtn');
    if (revertBtn) revertBtn.disabled = isFinalized;

    // Show/hide payroll column
    document.querySelectorAll('.payroll-col').forEach(col => {
      if (isFinalized) col.classList.remove('d-none');
      else col.classList.add('d-none');
    });

    // Select all behavior
    var selectAll = document.getElementById('selectAllActive');
    if (selectAll) {
      selectAll.addEventListener('change', function() {
        document.querySelectorAll("input[name='selected_actives[]']").forEach(cb => {
          if (!cb.disabled) cb.checked = selectAll.checked;
        });
      });
    }
  });
</script>
</body>
</html>
<?php pg_close($connection); ?>

