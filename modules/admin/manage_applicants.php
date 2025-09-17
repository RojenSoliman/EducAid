<?php
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}
include '../../config/database.php';

// Function to check if all required documents are uploaded
function check_documents($connection, $student_id) {
    $required = ['eaf', 'letter_to_mayor', 'certificate_of_indigency'];
    $query = pg_query_params($connection, "SELECT type FROM documents WHERE student_id = $1", [$student_id]);
    $uploaded = [];
    while ($row = pg_fetch_assoc($query)) $uploaded[] = $row['type'];
    
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
                                $docs = pg_query_params($connection, "SELECT * FROM documents WHERE student_id = $1", [$student_id]);
                                if (pg_num_rows($docs)) {
                                    while ($doc = pg_fetch_assoc($docs)) {
                                        $label = ucfirst(str_replace('_', ' ', $doc['type']));
                                        $filePath = htmlspecialchars($doc['file_path']);
                                        if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $filePath)) {
                                            // Show image preview with zoom functionality
                                            echo "<div class='doc-preview mb-3'>
                                                    <strong>$label:</strong><br>
                                                    <img src='$filePath' alt='$label' class='img-fluid rounded border zoomable-image' 
                                                         style='max-height: 200px; max-width: 100%;' 
                                                         onclick='openImageZoom(this.src, \"$label\")'>
                                                  </div>";
                                        } elseif (preg_match('/\.pdf$/i', $filePath)) {
                                            // Show embedded PDF
                                            echo "<div class='doc-preview mb-3'>
                                                    <strong>$label:</strong><br>
                                                    <iframe src='$filePath' width='100%' height='400' style='border: 1px solid #ccc;'></iframe>
                                                  </div>";
                                        } else {
                                            // Fallback link
                                            echo "<p><strong>$label:</strong> <a href='$filePath' target='_blank'>View</a></p>";
                                        }
                                    }
                                } else echo "<p class='text-muted'>No documents uploaded.</p>";
                                
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
</body>
</html>
<?php pg_close($connection); ?>
