<?php
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}
include '../../config/database.php';

// Normalize a string for comparison (letters only, lowercase)
function _normalize_token($s) {
    return preg_replace('/[^a-z]/', '', strtolower($s ?? ''));
}

// Find newest file in a folder that matches both first and last name (case-insensitive)
function find_student_documents($first_name, $last_name) {
    $server_base = dirname(__DIR__, 2) . '/assets/uploads/student/'; // absolute server path
    $web_base    = '../../assets/uploads/student/';                   // web path from this PHP file

    $first = _normalize_token($first_name);
    $last  = _normalize_token($last_name);

    $document_types = [
        'eaf' => 'enrollment_forms',
        'letter_to_mayor' => 'letter_to_mayor',
        'certificate_of_indigency' => 'indigency'
    ];

    $found = [];
    foreach ($document_types as $type => $folder) {
        $dir = $server_base . $folder . '/';
        if (!is_dir($dir)) continue;

        // Scan all files and pick the newest that contains both name tokens
        $matches = [];
        foreach (glob($dir . '*.*') as $file) {
            $base = pathinfo($file, PATHINFO_FILENAME);
            $baseNorm = _normalize_token($base);
            if ($first && $last && strpos($baseNorm, $first) !== false && strpos($baseNorm, $last) !== false) {
                $matches[filemtime($file)] = $file;
            }
        }

        if (!empty($matches)) {
            krsort($matches); // newest first
            $picked = reset($matches);
            $found[$type] = $web_base . $folder . '/' . basename($picked);
        }
    }

    return $found;
}

// Helper to find documents by student_id by first fetching the name
function find_student_documents_by_id($connection, $student_id) {
    $res = pg_query_params($connection, "SELECT first_name, last_name FROM students WHERE student_id = $1", [$student_id]);
    if ($res && pg_num_rows($res)) {
        $row = pg_fetch_assoc($res);
        return find_student_documents($row['first_name'] ?? '', $row['last_name'] ?? '');
    }
    return [];
}

// Function to check if all required documents are uploaded
function check_documents($connection, $student_id) {
    $required = ['eaf', 'letter_to_mayor', 'certificate_of_indigency'];
    // First check database records
    $query = pg_query_params($connection, "SELECT type FROM documents WHERE student_id = $1", [$student_id]);
    $uploaded = [];
    while ($row = pg_fetch_assoc($query)) $uploaded[] = $row['type'];
    // Also check file system for new structure by student name
    $found_documents = find_student_documents_by_id($connection, $student_id);
    $uploaded = array_unique(array_merge($uploaded, array_keys($found_documents)));
    
    // Check if grades are uploaded
    $grades_query = pg_query_params($connection, "SELECT COUNT(*) as count FROM grade_uploads WHERE student_id = $1", [$student_id]);
    $grades_row = pg_fetch_assoc($grades_query);
    $has_grades = $grades_row['count'] > 0;
    
    return count(array_diff($required, $uploaded)) === 0 && $has_grades;
}

// Pagination & Filtering logic
$page = max(1, intval($_GET['page'] ?? $_POST['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;
$sort = $_GET['sort'] ?? $_POST['sort'] ?? 'asc';
$search = trim($_GET['search_surname'] ?? $_POST['search_surname'] ?? '');

$where = "status = 'applicant'";
$params = [];
if ($search) {
    $where .= " AND last_name ILIKE $1";
    $params[] = "%$search%";
}
$countQuery = "SELECT COUNT(*) FROM students WHERE $where";
$totalApplicants = pg_fetch_assoc(pg_query_params($connection, $countQuery, $params))['count'];
$totalPages = max(1, ceil($totalApplicants / $perPage));

$query = "SELECT * FROM students WHERE $where ORDER BY last_name " . ($sort === 'desc' ? 'DESC' : 'ASC') . " LIMIT $perPage OFFSET $offset";
$applicants = $params ? pg_query_params($connection, $query, $params) : pg_query($connection, $query);

// Table rendering function with live preview
function render_table($applicants, $connection) {
    ob_start();
    ?>
    <table class="table table-bordered align-middle">
        <thead>
            <tr>
                <th>Name</th>
                <th>Contact</th>
                <th>Email</th>
                <th>Documents</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody id="applicantsTableBody">
        <?php if (pg_num_rows($applicants) === 0): ?>
            <tr><td colspan="5" class="text-center no-applicants">No applicants found.</td></tr>
        <?php else: ?>
            <?php while ($applicant = pg_fetch_assoc($applicants)) {
                $student_id = $applicant['student_id'];
                $isComplete = check_documents($connection, $student_id);
                ?>
                <tr>
                    <td data-label="Name">
                        <?= htmlspecialchars("{$applicant['last_name']}, {$applicant['first_name']} {$applicant['middle_name']}") ?>
                    </td>
                    <td data-label="Contact">
                        <?= htmlspecialchars($applicant['mobile']) ?>
                    </td>
                    <td data-label="Email">
                        <?= htmlspecialchars($applicant['email']) ?>
                    </td>
                    <td data-label="Documents">
                        <span class="badge <?= $isComplete ? 'badge-success' : 'badge-secondary' ?>">
                            <?= $isComplete ? 'Complete' : 'Incomplete' ?>
                        </span>
                    </td>
                    <td data-label="Action">
                        <button class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#modal<?= $student_id ?>">
                            <i class="bi bi-eye"></i> View
                        </button>
                        <?php if ($_SESSION['admin_role'] === 'super_admin'): ?>
                        <button class="btn btn-danger btn-sm ms-1" 
                                onclick="showBlacklistModal('<?= $student_id ?>', '<?= htmlspecialchars($applicant['first_name'] . ' ' . $applicant['last_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($applicant['email'], ENT_QUOTES) ?>', {
                                    barangay: '<?= htmlspecialchars($applicant['barangay'] ?? 'N/A', ENT_QUOTES) ?>',
                                    status: 'Applicant'
                                })"
                                title="Blacklist Student">
                            <i class="bi bi-shield-exclamation"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <!-- Modal -->
                <div class="modal fade" id="modal<?= $student_id ?>" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Documents for <?= htmlspecialchars($applicant['first_name']) ?> <?= htmlspecialchars($applicant['last_name']) ?></h5>
                                <button class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <?php
                                // First, get documents from database
                                $docs = pg_query_params($connection, "SELECT * FROM documents WHERE student_id = $1", [$student_id]);
                                $db_documents = [];
                                while ($doc = pg_fetch_assoc($docs)) {
                                    $db_documents[$doc['type']] = $doc['file_path'];
                                }

                                // Then, search for documents in new file structure by applicant name
                                $found_documents = find_student_documents($applicant['first_name'] ?? '', $applicant['last_name'] ?? '');

                                // Merge both sources, prioritizing new file structure
                                $all_documents = array_merge($db_documents, $found_documents);

                                $document_labels = [
                                    'eaf' => 'EAF',
                                    'letter_to_mayor' => 'Letter to Mayor',
                                    'certificate_of_indigency' => 'Certificate of Indigency'
                                ];

                                // Build cards grid
                                echo "<div class='doc-grid'>";
                                $has_documents = false;
                                foreach ($document_labels as $type => $label) {
                                    $cardTitle = htmlspecialchars($label);
                                    if (isset($all_documents[$type])) {
                                        $has_documents = true;
                                        $filePath = $all_documents[$type];

                                        // Resolve server path for metadata
                                        $server_root = dirname(__DIR__, 2);
                                        $relative_from_root = ltrim(str_replace('../../', '', $filePath), '/');
                                        $server_path = $server_root . '/' . $relative_from_root;

                                        $is_image = preg_match('/\.(jpg|jpeg|png|gif)$/i', $filePath);
                                        $is_pdf   = preg_match('/\.pdf$/i', $filePath);

                                        $size_str = '';
                                        $date_str = '';
                                        if (file_exists($server_path)) {
                                            $size = filesize($server_path);
                                            $units = ['B','KB','MB','GB'];
                                            $pow = $size > 0 ? floor(log($size, 1024)) : 0;
                                            $size_str = number_format($size / pow(1024, $pow), $pow ? 2 : 0) . ' ' . $units[$pow];
                                            $date_str = date('M d, Y h:i A', filemtime($server_path));
                                        }

                                        $thumbHtml = $is_image
                                            ? "<img src='" . htmlspecialchars($filePath) . "' class='doc-thumb' alt='$cardTitle'>"
                                            : "<div class='doc-thumb doc-thumb-pdf'><i class='bi bi-file-earmark-pdf'></i></div>";

                                        $safeSrc = htmlspecialchars($filePath);
                                        echo "<div class='doc-card'>
                                                <div class='doc-card-header'>$cardTitle</div>
                                                <div class='doc-card-body' onclick=\"openDocumentViewer('$safeSrc','$cardTitle')\">$thumbHtml</div>
                                                <div class='doc-meta'>" .
                                                    ($date_str ? "<span><i class='bi bi-calendar-event me-1'></i>$date_str</span>" : "") .
                                                    ($size_str ? "<span><i class='bi bi-hdd me-1'></i>$size_str</span>" : "") .
                                                "</div>
                                                <div class='doc-actions'>
                                                    <button type='button' class='btn btn-sm btn-primary' onclick=\"openDocumentViewer('$safeSrc','$cardTitle')\"><i class='bi bi-eye me-1'></i>View</button>
                                                    <a class='btn btn-sm btn-outline-secondary' href='$safeSrc' target='_blank'><i class='bi bi-box-arrow-up-right me-1'></i>Open</a>
                                                    <a class='btn btn-sm btn-outline-success' href='$safeSrc' download><i class='bi bi-download me-1'></i>Download</a>
                                                </div>
                                              </div>";
                                    } else {
                                        echo "<div class='doc-card doc-card-missing'>
                                                <div class='doc-card-header'>$cardTitle</div>
                                                <div class='doc-card-body missing'>
                                                    <div class='missing-icon'><i class='bi bi-exclamation-triangle'></i></div>
                                                    <div class='missing-text'>Not uploaded</div>
                                                </div>
                                                <div class='doc-actions'>
                                                    <span class='text-muted small'>Awaiting submission</span>
                                                </div>
                                              </div>";
                                    }
                                }
                                echo "</div>"; // end doc-grid

                                if (!$has_documents) {
                                    echo "<p class='text-muted'>No documents uploaded.</p>";
                                }

                                // Check for grades
                                $grades_query = pg_query_params($connection, "SELECT * FROM grade_uploads WHERE student_id = $1 ORDER BY upload_date DESC LIMIT 1", [$student_id]);
                                if (pg_num_rows($grades_query) > 0) {
                                    $grade_upload = pg_fetch_assoc($grades_query);
                                    echo "<hr><div class='grades-section'>";
                                    echo "<h6><i class='bi bi-file-earmark-text me-2'></i>Academic Grades</h6>";
                                    echo "<div class='d-flex justify-content-between align-items-center mb-2'>";
                                    echo "<span><strong>Status:</strong> <span class='badge bg-" . 
                                         ($grade_upload['validation_status'] === 'passed' ? 'success' : 
                                          ($grade_upload['validation_status'] === 'failed' ? 'danger' : 'warning')) . 
                                         "'>" . ucfirst($grade_upload['validation_status']) . "</span></span>";
                                    if ($grade_upload['ocr_confidence']) {
                                        echo "<span><strong>OCR Confidence:</strong> " . round($grade_upload['ocr_confidence'], 1) . "%</span>";
                                    }
                                    echo "</div>";
                                    
                                    if ($grade_upload['file_path']) {
                                        $grades_file_path = htmlspecialchars($grade_upload['file_path']);
                                        if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $grades_file_path)) {
                                            echo "<img src='$grades_file_path' alt='Grades' class='img-fluid rounded border mb-2' style='max-height: 200px; max-width: 100%;' onclick='openImageZoom(this.src, \"Grades\")'>";
                                        } elseif (preg_match('/\.pdf$/i', $grades_file_path)) {
                                            echo "<iframe src='$grades_file_path' width='100%' height='300' style='border: 1px solid #ccc;'></iframe>";
                                        }
                                    }
                                    
                                    echo "<div class='mt-2'>";
                                    echo "<a href='validate_grades.php' class='btn btn-outline-primary btn-sm'>";
                                    echo "<i class='bi bi-eye me-1'></i>Review in Grades Validator</a>";
                                    echo "</div>";
                                    echo "</div>";
                                } else {
                                    echo "<hr><div class='alert alert-warning'>";
                                    echo "<i class='bi bi-exclamation-triangle me-2'></i>";
                                    echo "<strong>Missing:</strong> Academic grades not uploaded.";
                                    echo "</div>";
                                }
                                ?>
                            </div>
                            <div class="modal-footer">
                                <?php if ($isComplete): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Verify this student?');">
                                        <input type="hidden" name="student_id" value="<?= $student_id ?>">
                                        <input type="hidden" name="mark_verified" value="1">
                                        <button class="btn btn-success btn-sm"><i class="bi bi-check-circle me-1"></i> Verify</button>
                                    </form>
                                    <form method="POST" class="d-inline ms-2" onsubmit="return confirm('Reject and reset uploads?');">
                                        <input type="hidden" name="student_id" value="<?= $student_id ?>">
                                        <input type="hidden" name="reject_applicant" value="1">
                                        <button class="btn btn-danger btn-sm"><i class="bi bi-x-circle me-1"></i> Reject</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted">Incomplete documents</span>
                                    <?php if (!empty($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'super_admin'): ?>
                                    <form method="POST" class="d-inline ms-2" onsubmit="return confirm('Override verification and mark this student as Active even without complete grades/documents?');">
                                        <input type="hidden" name="student_id" value="<?= $student_id ?>">
                                        <input type="hidden" name="mark_verified_override" value="1">
                                        <button class="btn btn-warning btn-sm"><i class="bi bi-exclamation-triangle me-1"></i> Override Verify</button>
                                    </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php if ($_SESSION['admin_role'] === 'super_admin'): ?>
                                <div class="ms-auto">
                                    <button class="btn btn-outline-danger btn-sm" 
                                            onclick="showBlacklistModal('<?= $student_id ?>', '<?= htmlspecialchars($applicant['first_name'] . ' ' . $applicant['last_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($applicant['email'], ENT_QUOTES) ?>', {
                                                barangay: '<?= htmlspecialchars($applicant['barangay'] ?? 'N/A', ENT_QUOTES) ?>',
                                                status: 'Applicant'
                                            })"
                                            data-bs-dismiss="modal">
                                        <i class="bi bi-shield-exclamation me-1"></i> Blacklist Student
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php } ?>
        <?php endif; ?>
        </tbody>
    </table>
    <?php
    return ob_get_clean();
}

// Pagination rendering function
function render_pagination($page, $totalPages) {
    if ($totalPages <= 1) return '';
    ?>
    <nav aria-label="Table pagination" class="mt-3">
        <ul class="pagination justify-content-end">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="#" data-page="<?= $page-1 ?>">&lt;</a>
            </li>
            <li class="page-item">
                <span class="page-link">
                    Page <input type="number" min="1" max="<?= $totalPages ?>" value="<?= $page ?>" id="manualPage" style="width:55px; text-align:center;" /> of <?= $totalPages ?>
                </span>
            </li>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="#" data-page="<?= $page+1 ?>">&gt;</a>
            </li>
        </ul>
    </nav>
    <?php
}

// Handle verify/reject actions before AJAX or page render
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify student
    if (!empty($_POST['mark_verified']) && isset($_POST['student_id'])) {
        $sid = trim($_POST['student_id']); // Remove intval for TEXT student_id
        
        // Get student name for notification
        $studentQuery = pg_query_params($connection, "SELECT first_name, last_name FROM students WHERE student_id = $1", [$sid]);
        $student = pg_fetch_assoc($studentQuery);
        
        /** @phpstan-ignore-next-line */
        pg_query_params($connection, "UPDATE students SET status = 'active' WHERE student_id = $1", [$sid]);
        
        // Add admin notification
        if ($student) {
            $student_name = $student['first_name'] . ' ' . $student['last_name'];
            $notification_msg = "Student promoted to active: " . $student_name . " (ID: " . $sid . ")";
            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
        }
        
        // Redirect to refresh list
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    // Override verify even if incomplete (super_admin only)
    if (!empty($_POST['mark_verified_override']) && isset($_POST['student_id'])) {
        if (!empty($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'super_admin') {
            $sid = trim($_POST['student_id']);
            // Get student name for notification
            $studentQuery = pg_query_params($connection, "SELECT first_name, last_name FROM students WHERE student_id = $1", [$sid]);
            $student = pg_fetch_assoc($studentQuery);

            /** @phpstan-ignore-next-line */
            pg_query_params($connection, "UPDATE students SET status = 'active' WHERE student_id = $1", [$sid]);

            // Add admin notification noting override
            if ($student) {
                $student_name = $student['first_name'] . ' ' . $student['last_name'];
                $notification_msg = "OVERRIDE: Student promoted to active without complete grades/docs: " . $student_name . " (ID: " . $sid . ")";
                pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
            }
        }
        // Redirect to refresh list
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    // Reject applicant and reset documents
    if (!empty($_POST['reject_applicant']) && isset($_POST['student_id'])) {
        $sid = trim($_POST['student_id']); // Remove intval for TEXT student_id
        
        // Get student name for notification
        $studentQuery = pg_query_params($connection, "SELECT first_name, last_name FROM students WHERE student_id = $1", [$sid]);
        $student = pg_fetch_assoc($studentQuery);
        
        // Delete uploaded files
        /** @phpstan-ignore-next-line */
        $docs = pg_query_params($connection, "SELECT file_path FROM documents WHERE student_id = $1", [$sid]);
        while ($d = pg_fetch_assoc($docs)) {
            $path = $d['file_path'];
            if ($path && file_exists($path)) {
                @unlink($path);
            }
        }
        /** @phpstan-ignore-next-line */
        pg_query_params($connection, "DELETE FROM documents WHERE student_id = $1", [$sid]);
        
        // Student notification
        $msg = 'Your uploaded documents were rejected on ' . date('F j, Y, g:i a') . '. Please re-upload.';
        /** @phpstan-ignore-next-line */
        pg_query_params($connection, "INSERT INTO notifications (student_id, message) VALUES ($1, $2)", [$sid, $msg]);
        
        // Add admin notification
        if ($student) {
            $student_name = $student['first_name'] . ' ' . $student['last_name'];
            $notification_msg = "Documents rejected for applicant: " . $student_name . " (ID: " . $sid . ")";
            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
        }
        
        // Redirect to refresh list
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}
// --------- AJAX handler ---------
if ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '' === 'XMLHttpRequest' || (isset($_GET['ajax']) && $_GET['ajax'] === '1')) {
    // Return table content and stats for real-time updates
    ob_start();
    ?>
    <div class="section-header mb-3">
        <h2 class="fw-bold text-primary">
            <i class="bi bi-person-vcard"></i>
            Manage Applicants
        </h2>
        <div class="text-end">
            <span class="badge bg-info fs-6"><?php echo $totalApplicants; ?> Total Applicants</span>
        </div>
    </div>
    <?php
    echo render_table($applicants, $connection);
    render_pagination($page, $totalPages);
    echo ob_get_clean();
    exit;
}

// Normal page output below...
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Manage Applicants</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"/>
  <link rel="stylesheet" href="../../assets/css/admin/homepage.css"/>
  <link rel="stylesheet" href="../../assets/css/admin/sidebar.css"/>
  <link rel="stylesheet" href="../../assets/css/admin/manage_applicants.css"/>
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
      <div class="section-header mb-3 d-flex justify-content-between align-items-center">
        <h2 class="fw-bold text-primary mb-0">
          <i class="bi bi-person-vcard" ></i>
          Manage Applicants
        </h2>
        <div class="text-end">
          <span class="badge bg-info fs-6"><?php echo $totalApplicants; ?> Total Applicants</span>
        </div>
      </div>
      <!-- Filter Container -->
      <div class="filter-container card shadow-sm mb-4 p-3">
        <form class="row g-3" id="filterForm" method="GET">
          <div class="col-sm-4">
            <label class="form-label fw-bold" style="color:#1182FF;">Sort by Surname</label>
            <select name="sort" class="form-select">
              <option value="asc" <?= $sort === 'asc' ? 'selected' : '' ?>>A to Z</option>
              <option value="desc" <?= $sort === 'desc' ? 'selected' : '' ?>>Z to A</option>
            </select>
          </div>
          <div class="col-sm-4">
            <label class="form-label fw-bold" style="color:#1182FF;">Search by Surname</label>
            <input type="text" name="search_surname" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Enter surname...">
          </div>
          <div class="col-sm-4 d-flex align-items-end gap-2">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i> Apply Filters</button>
            <button type="button" class="btn btn-secondary w-100" id="clearFiltersBtn">Clear</button>
          </div>
        </form>
      </div>
      <!-- Applicants Table -->
      <div class="table-responsive" id="tableWrapper">
        <?= render_table($applicants, $connection) ?>
      </div>
      <div id="pagination">
        <?php render_pagination($page, $totalPages); ?>
      </div>
    </div>
  </section>
</div>

<!-- Include Blacklist Modal -->
<?php include '../../includes/admin/blacklist_modal.php'; ?>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>
<script src="../../assets/js/admin/manage_applicants.js"></script>
<script>
// Image Zoom Functionality
function openImageZoom(imageSrc, imageTitle) {
    // Create modal if it doesn't exist
    let modal = document.getElementById('imageZoomModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'imageZoomModal';
        modal.className = 'image-zoom-modal';
        modal.innerHTML = `
            <span class="image-zoom-close" onclick="closeImageZoom()">&times;</span>
            <div class="image-zoom-content">
                <div class="image-loading">Loading...</div>
                <img id="zoomedImage" style="display: none;" alt="${imageTitle}">
            </div>
        `;
        document.body.appendChild(modal);
    }

    // Show modal
    modal.style.display = 'block';
    
    // Load image
    const img = document.getElementById('zoomedImage');
    const loading = modal.querySelector('.image-loading');
    
    img.onload = function() {
        loading.style.display = 'none';
        img.style.display = 'block';
    };
    
    img.onerror = function() {
        loading.textContent = 'Failed to load image';
    };
    
    img.src = imageSrc;
    
    // Close on background click
    modal.onclick = function(event) {
        if (event.target === modal) {
            closeImageZoom();
        }
    };
}

function closeImageZoom() {
    const modal = document.getElementById('imageZoomModal');
    if (modal) {
        modal.style.display = 'none';
        // Reset image
        const img = document.getElementById('zoomedImage');
        const loading = modal.querySelector('.image-loading');
        img.style.display = 'none';
        loading.style.display = 'block';
        loading.textContent = 'Loading...';
    }
}

// Close zoom on Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeImageZoom();
    }
});

// Real-time updates
let isUpdating = false;
let lastUpdateData = null;

function updateTableData() {
    if (isUpdating) return;
    isUpdating = true;

    const currentUrl = new URL(window.location);
    const params = new URLSearchParams(currentUrl.search);
    params.set('ajax', '1');

    fetch(window.location.pathname + '?' + params.toString())
        .then(response => response.text())
        .then(data => {
            if (data !== lastUpdateData) {
                // Parse the response to extract content
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = data;
                
                // Update section header with total count
                const newHeader = tempDiv.querySelector('.section-header');
                const currentHeader = document.querySelector('.section-header');
                if (newHeader && currentHeader) {
                    currentHeader.innerHTML = newHeader.innerHTML;
                }

                // Update table content
                const newTable = tempDiv.querySelector('table');
                const currentTable = document.querySelector('#tableWrapper table');
                if (newTable && currentTable && newTable.innerHTML !== currentTable.innerHTML) {
                    currentTable.innerHTML = newTable.innerHTML;
                }

                // Update pagination
                const newPagination = tempDiv.querySelector('nav[aria-label="Table pagination"]');
                const currentPagination = document.querySelector('#pagination nav[aria-label="Table pagination"]');
                if (newPagination && currentPagination) {
                    currentPagination.innerHTML = newPagination.innerHTML;
                } else if (newPagination && !currentPagination) {
                    document.getElementById('pagination').innerHTML = newPagination.outerHTML;
                } else if (!newPagination && currentPagination) {
                    document.getElementById('pagination').innerHTML = '';
                }

                lastUpdateData = data;
            }
        })
        .catch(error => {
            console.log('Update failed:', error);
        })
        .finally(() => {
            isUpdating = false;
            setTimeout(updateTableData, 100); // Update every 100ms
        });
}

// Start real-time updates when page loads
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(updateTableData, 100);
});
</script>
<style>
/* Document grid */
.doc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
.doc-card { border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; display: flex; flex-direction: column; }
.doc-card-header { font-weight: 600; padding: 10px 12px; border-bottom: 1px solid #f0f0f0; }
.doc-card-body { padding: 8px; display: flex; align-items: center; justify-content: center; min-height: 160px; cursor: zoom-in; background: #fafafa; }
.doc-thumb { max-width: 100%; max-height: 150px; border-radius: 4px; }
.doc-thumb-pdf { font-size: 48px; color: #d32f2f; display: flex; align-items: center; justify-content: center; height: 150px; width: 100%; }
.doc-meta { display: flex; justify-content: space-between; gap: 8px; padding: 6px 12px; color: #6b7280; font-size: 12px; border-top: 1px dashed #eee; }
.doc-actions { display: flex; gap: 6px; padding: 8px 12px; border-top: 1px solid #f0f0f0; }
.doc-card-missing .missing { background: #fff7e6; color: #8a6d3b; min-height: 160px; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.doc-card-missing .missing-icon { font-size: 28px; margin-bottom: 6px; }

/* Fullscreen viewer */
.doc-viewer-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.8); display: none; z-index: 1060; }
.doc-viewer { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 95vw; max-width: 1280px; height: 85vh; background: #111; border-radius: 8px; overflow: hidden; display: flex; flex-direction: column; }
.doc-viewer-toolbar { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; justify-content: space-between; padding: 8px 12px; background: #1f2937; color: #fff; }
.doc-viewer-toolbar .btn { padding: 4px 8px; }
.doc-viewer-content { flex: 1; background: #000; display: flex; align-items: center; justify-content: center; overflow: hidden; position: relative; }
.doc-viewer-canvas { touch-action: none; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; }
.doc-viewer-content img { will-change: transform; transform-origin: center center; user-select: none; -webkit-user-drag: none; }
.doc-viewer-content iframe { width: 100%; height: 100%; border: none; }
.doc-viewer-close { background: transparent; border: 0; color: #fff; font-size: 20px; }

@media (max-width: 576px) {
    .doc-grid { grid-template-columns: 1fr; }
    .doc-viewer { width: 100vw; height: 90vh; border-radius: 0; }
}
</style>

<script>
// Lightweight document viewer (image/pdf)
function ensureDocViewer() {
    let backdrop = document.getElementById('docViewerBackdrop');
    if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.id = 'docViewerBackdrop';
        backdrop.className = 'doc-viewer-backdrop';
        backdrop.innerHTML = `
            <div class="doc-viewer">
                <div class="doc-viewer-toolbar">
                    <div id="docViewerTitle"></div>
                    <div class="d-flex flex-wrap gap-1">
                        <button id="docZoomOutBtn" class="btn btn-sm btn-outline-light" title="Zoom Out"><i class="bi bi-zoom-out"></i></button>
                        <button id="docZoomInBtn" class="btn btn-sm btn-outline-light" title="Zoom In"><i class="bi bi-zoom-in"></i></button>
                        <button id="docRotateLeftBtn" class="btn btn-sm btn-outline-light" title="Rotate Left"><i class="bi bi-arrow-counterclockwise"></i></button>
                        <button id="docRotateRightBtn" class="btn btn-sm btn-outline-light" title="Rotate Right"><i class="bi bi-arrow-clockwise"></i></button>
                        <button id="docFitWidthBtn" class="btn btn-sm btn-outline-light" title="Fit Width"><i class="bi bi-arrows-expand"></i></button>
                        <button id="docFitScreenBtn" class="btn btn-sm btn-outline-light" title="Fit Screen"><i class="bi bi-arrows-fullscreen"></i></button>
                        <button id="docResetBtn" class="btn btn-sm btn-outline-secondary" title="Reset"><i class="bi bi-arrow-repeat"></i></button>
                        <div class="vr mx-1"></div>
                        <button id="docOpenBtn" class="btn btn-sm btn-outline-light"><i class="bi bi-box-arrow-up-right"></i> Open</button>
                        <button id="docDownloadBtn" class="btn btn-sm btn-success"><i class="bi bi-download"></i> Download</button>
                        <button class="doc-viewer-close ms-1" onclick="closeDocumentViewer()">&times;</button>
                    </div>
                </div>
                <div class="doc-viewer-content">
                    <div class="doc-viewer-canvas">
                        <img id="docViewerImg" alt="preview" style="display:none;" />
                        <iframe id="docViewerPdf" style="display:none;"></iframe>
                    </div>
                </div>
            </div>`;
        document.body.appendChild(backdrop);
        backdrop.addEventListener('click', (e) => { if (e.target === backdrop) closeDocumentViewer(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeDocumentViewer(); });
    }
    return backdrop;
}

// Viewer state
let _viewState = { scale: 1, rotation: 0, originX: 0, originY: 0, panX: 0, panY: 0, isImage: false };

function applyImageTransform(img) {
    img.style.transform = `translate(${_viewState.panX}px, ${_viewState.panY}px) rotate(${_viewState.rotation}deg) scale(${_viewState.scale})`;
}

function resetView(img) {
    _viewState = { scale: 1, rotation: 0, originX: 0, originY: 0, panX: 0, panY: 0, isImage: _viewState.isImage };
    if (img) applyImageTransform(img);
}

function openDocumentViewer(src, title) {
    const backdrop = ensureDocViewer();
    const img = document.getElementById('docViewerImg');
    const pdf = document.getElementById('docViewerPdf');
    const openBtn = document.getElementById('docOpenBtn');
    const downloadBtn = document.getElementById('docDownloadBtn');
    const zoomInBtn = document.getElementById('docZoomInBtn');
    const zoomOutBtn = document.getElementById('docZoomOutBtn');
    const rotateLeftBtn = document.getElementById('docRotateLeftBtn');
    const rotateRightBtn = document.getElementById('docRotateRightBtn');
    const fitWidthBtn = document.getElementById('docFitWidthBtn');
    const fitScreenBtn = document.getElementById('docFitScreenBtn');
    const resetBtn = document.getElementById('docResetBtn');
    document.getElementById('docViewerTitle').textContent = title || 'Document';

    // Reset
    img.style.display = 'none';
    pdf.style.display = 'none';
    img.src = '';
    pdf.src = '';
    resetView(img);

    const isImage = /\.(jpg|jpeg|png|gif)$/i.test(src);
    const isPdf = /\.pdf$/i.test(src);
    _viewState.isImage = isImage;
    if (isImage) {
        img.src = src;
        img.style.display = 'block';
    } else if (isPdf) {
        pdf.src = src;
        pdf.style.display = 'block';
    }

    openBtn.onclick = () => window.open(src, '_blank');
    downloadBtn.onclick = () => { const a = document.createElement('a'); a.href = src; a.download = ''; a.click(); };

    // Controls
    function setScale(mult) { _viewState.scale = Math.min(8, Math.max(0.25, _viewState.scale * mult)); applyImageTransform(img); }
    function rotate(delta) { _viewState.rotation = (_viewState.rotation + delta + 360) % 360; applyImageTransform(img); }
    function fitWidth() {
        const container = document.querySelector('.doc-viewer-content');
        if (!container || !img.naturalWidth) return; 
        _viewState.scale = (container.clientWidth * 0.95) / img.naturalWidth; _viewState.panX = 0; _viewState.panY = 0; applyImageTransform(img);
    }
    function fitScreen() {
        const container = document.querySelector('.doc-viewer-content');
        if (!container || !img.naturalWidth || !img.naturalHeight) return; 
        const scaleX = (container.clientWidth * 0.95) / img.naturalWidth;
        const scaleY = (container.clientHeight * 0.95) / img.naturalHeight;
        _viewState.scale = Math.min(scaleX, scaleY); _viewState.panX = 0; _viewState.panY = 0; applyImageTransform(img);
    }

    zoomInBtn.onclick = () => _viewState.isImage && setScale(1.2);
    zoomOutBtn.onclick = () => _viewState.isImage && setScale(1/1.2);
    rotateLeftBtn.onclick = () => _viewState.isImage && rotate(-90);
    rotateRightBtn.onclick = () => _viewState.isImage && rotate(90);
    fitWidthBtn.onclick = () => _viewState.isImage ? fitWidth() : (pdf.src = src + '#zoom=page-width');
    fitScreenBtn.onclick = () => _viewState.isImage ? fitScreen() : (pdf.src = src + '#zoom=page-fit');
    resetBtn.onclick = () => { resetView(img); if (!isImage) pdf.src = src; };

    // Pan & wheel zoom for images
    const canvas = document.querySelector('.doc-viewer-canvas');
    let dragging = false, lastX = 0, lastY = 0;
    canvas.onpointerdown = (e) => { if (!_viewState.isImage) return; dragging = true; lastX = e.clientX; lastY = e.clientY; canvas.setPointerCapture(e.pointerId); };
    canvas.onpointermove = (e) => { if (!_viewState.isImage || !dragging) return; _viewState.panX += (e.clientX - lastX); _viewState.panY += (e.clientY - lastY); lastX = e.clientX; lastY = e.clientY; applyImageTransform(img); };
    canvas.onpointerup = () => { dragging = false; };
    canvas.onwheel = (e) => { if (!_viewState.isImage) return; e.preventDefault(); setScale(e.deltaY < 0 ? 1.1 : 1/1.1); };

    // Double-tap/double-click to toggle zoom
    let lastTap = 0;
    canvas.ondblclick = () => { if (!_viewState.isImage) return; _viewState.scale = _viewState.scale < 2 ? 2 : 1; _viewState.panX = 0; _viewState.panY = 0; applyImageTransform(img); };
    canvas.ontouchend = () => { const now = Date.now(); if (now - lastTap < 300) { canvas.ondblclick(); } lastTap = now; };

    backdrop.style.display = 'block';
}

function closeDocumentViewer() {
    const backdrop = document.getElementById('docViewerBackdrop');
    if (backdrop) backdrop.style.display = 'none';
}
</script>
</body>
</html>
<?php pg_close($connection); ?>
