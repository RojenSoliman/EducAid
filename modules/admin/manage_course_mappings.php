<?php
include __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/CourseMappingService.php';

session_start();

if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

$courseMappingService = new CourseMappingService($connection);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'verify_course') {
        $mapping_id = intval($_POST['mapping_id'] ?? 0);
        $normalized_course = trim($_POST['normalized_course'] ?? '');
        $program_duration = intval($_POST['program_duration'] ?? 4);
        $course_category = trim($_POST['course_category'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($mapping_id > 0) {
            // Update the mapping
            $updateQuery = "UPDATE courses_mapping 
                           SET is_verified = TRUE,
                               verified_by = $1,
                               normalized_course = $2,
                               program_duration = $3,
                               course_category = $4,
                               notes = $5,
                               updated_at = NOW()
                           WHERE mapping_id = $6";
            
            $result = pg_query_params($connection, $updateQuery, [
                $_SESSION['admin_id'],
                $normalized_course,
                $program_duration,
                $course_category,
                $notes,
                $mapping_id
            ]);
            
            if ($result) {
                $_SESSION['success_message'] = "Course mapping verified successfully!";
                
                // Log audit trail
                $auditQuery = "INSERT INTO audit_logs (user_id, user_type, username, event_type, event_category, 
                              action_description, status, ip_address, affected_table, affected_record_id, metadata)
                              VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11)";
                
                pg_query_params($connection, $auditQuery, [
                    $_SESSION['admin_id'],
                    'admin',
                    $_SESSION['admin_username'],
                    'course_mapping_verified',
                    'course_management',
                    "Verified course mapping ID $mapping_id: $normalized_course",
                    'success',
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    'courses_mapping',
                    $mapping_id,
                    json_encode([
                        'mapping_id' => $mapping_id,
                        'normalized_course' => $normalized_course,
                        'program_duration' => $program_duration,
                        'category' => $course_category
                    ])
                ]);
            } else {
                $_SESSION['error_message'] = "Error verifying course mapping.";
            }
        }
    } elseif ($action === 'edit_course') {
        $mapping_id = intval($_POST['mapping_id'] ?? 0);
        $normalized_course = trim($_POST['normalized_course'] ?? '');
        $program_duration = intval($_POST['program_duration'] ?? 4);
        $course_category = trim($_POST['course_category'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($mapping_id > 0) {
            // Update the mapping (keep verification status)
            $updateQuery = "UPDATE courses_mapping 
                           SET normalized_course = $1,
                               program_duration = $2,
                               course_category = $3,
                               notes = $4,
                               updated_at = NOW()
                           WHERE mapping_id = $5";
            
            $result = pg_query_params($connection, $updateQuery, [
                $normalized_course,
                $program_duration,
                $course_category,
                $notes,
                $mapping_id
            ]);
            
            if ($result) {
                $_SESSION['success_message'] = "Course mapping updated successfully!";
                
                // Log audit trail
                $auditQuery = "INSERT INTO audit_logs (user_id, user_type, username, event_type, event_category, 
                              action_description, status, ip_address, affected_table, affected_record_id, metadata)
                              VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11)";
                
                pg_query_params($connection, $auditQuery, [
                    $_SESSION['admin_id'],
                    'admin',
                    $_SESSION['admin_username'],
                    'course_mapping_edited',
                    'course_management',
                    "Edited course mapping ID $mapping_id: $normalized_course",
                    'success',
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    'courses_mapping',
                    $mapping_id,
                    json_encode([
                        'mapping_id' => $mapping_id,
                        'normalized_course' => $normalized_course,
                        'program_duration' => $program_duration,
                        'category' => $course_category
                    ])
                ]);
            } else {
                $_SESSION['error_message'] = "Error updating course mapping.";
            }
        }
    } elseif ($action === 'reject_course') {
        $mapping_id = intval($_POST['mapping_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if ($mapping_id > 0) {
            // Delete the unverified mapping
            $deleteQuery = "DELETE FROM courses_mapping WHERE mapping_id = $1 AND is_verified = FALSE";
            $result = pg_query_params($connection, $deleteQuery, [$mapping_id]);
            
            if ($result) {
                $_SESSION['success_message'] = "Course mapping rejected and removed.";
                
                // Log audit trail
                $auditQuery = "INSERT INTO audit_logs (user_id, user_type, username, event_type, event_category, 
                              action_description, status, ip_address, affected_table, affected_record_id, metadata)
                              VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11)";
                
                pg_query_params($connection, $auditQuery, [
                    $_SESSION['admin_id'],
                    'admin',
                    $_SESSION['admin_username'],
                    'course_mapping_rejected',
                    'course_management',
                    "Rejected and deleted course mapping ID $mapping_id",
                    'success',
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    'courses_mapping',
                    $mapping_id,
                    json_encode(['notes' => $notes])
                ]);
            }
        }
    } elseif ($action === 'bulk_verify') {
        $mapping_ids = explode(',', $_POST['mapping_ids'] ?? '');
        $verified_count = 0;
        
        foreach ($mapping_ids as $id) {
            $id = intval(trim($id));
            if ($id > 0) {
                $result = pg_query_params($connection, 
                    "UPDATE courses_mapping SET is_verified = TRUE, verified_by = $1, updated_at = NOW() WHERE mapping_id = $2",
                    [$_SESSION['admin_id'], $id]
                );
                if ($result && pg_affected_rows($result) > 0) {
                    $verified_count++;
                }
            }
        }
        
        $_SESSION['success_message'] = "$verified_count course mapping(s) verified successfully!";
    }
    
    header("Location: manage_course_mappings.php?" . http_build_query($_GET));
    exit;
}

// Pagination and filtering
$limit = 25;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// Filter parameters
$status_filter = $_GET['status'] ?? 'unverified'; // unverified, verified, all
$university_filter = $_GET['university'] ?? '';
$category_filter = $_GET['category'] ?? '';
$search = trim($_GET['search'] ?? '');
$sort_by = $_GET['sort'] ?? 'last_seen';
$sort_order = $_GET['order'] ?? 'DESC';

// Build WHERE clause
$whereConditions = [];
$params = [];
$paramCount = 1;

if ($status_filter === 'unverified') {
    $whereConditions[] = "cm.is_verified = FALSE";
} elseif ($status_filter === 'verified') {
    $whereConditions[] = "cm.is_verified = TRUE";
}

if (!empty($university_filter)) {
    $whereConditions[] = "cm.university_id = $" . $paramCount;
    $params[] = $university_filter;
    $paramCount++;
}

if (!empty($category_filter)) {
    $whereConditions[] = "cm.course_category = $" . $paramCount;
    $params[] = $category_filter;
    $paramCount++;
}

if (!empty($search)) {
    $whereConditions[] = "(cm.raw_course_name ILIKE $" . $paramCount . " OR cm.normalized_course ILIKE $" . $paramCount . ")";
    $params[] = "%$search%";
    $paramCount++;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Valid sort columns
$validSorts = ['last_seen', 'raw_course_name', 'normalized_course', 'occurrence_count', 'program_duration', 'created_at'];
if (!in_array($sort_by, $validSorts)) $sort_by = 'last_seen';
if (!in_array($sort_order, ['ASC', 'DESC'])) $sort_order = 'DESC';

// Count total records
$countQuery = "SELECT COUNT(*) FROM courses_mapping cm $whereClause";
$countResult = pg_query_params($connection, $countQuery, $params);
$totalRecords = intval(pg_fetch_result($countResult, 0, 0));
$totalPages = ceil($totalRecords / $limit);

// Fetch course mappings
$query = "SELECT cm.*,
                 u.name as university_name,
                 a_created.username as created_by_username,
                 a_verified.username as verified_by_username,
                 (SELECT COUNT(*) FROM students s WHERE s.course = cm.raw_course_name AND s.university_id = cm.university_id) as student_count
          FROM courses_mapping cm
          LEFT JOIN universities u ON cm.university_id = u.university_id
          LEFT JOIN admins a_created ON cm.created_by = a_created.admin_id
          LEFT JOIN admins a_verified ON cm.verified_by = a_verified.admin_id
          $whereClause
          ORDER BY cm.$sort_by $sort_order
          LIMIT $limit OFFSET $offset";

$result = pg_query_params($connection, $query, $params);
$courseMappings = [];
while ($row = pg_fetch_assoc($result)) {
    $courseMappings[] = $row;
}

// Get filter options
$universities = pg_fetch_all(pg_query($connection, "SELECT university_id, name FROM universities ORDER BY name"));
$categories = pg_fetch_all(pg_query($connection, "SELECT DISTINCT course_category FROM courses_mapping WHERE course_category IS NOT NULL ORDER BY course_category"));

// Get statistics
$statsQuery = "SELECT 
                COUNT(*) as total,
                COUNT(*) FILTER (WHERE is_verified = TRUE) as verified,
                COUNT(*) FILTER (WHERE is_verified = FALSE) as unverified,
                SUM(occurrence_count) as total_occurrences
               FROM courses_mapping";
$statsResult = pg_query($connection, $statsQuery);
$stats = pg_fetch_assoc($statsResult);
?>

<?php $page_title = 'Manage Course Mappings'; include '../../includes/admin/admin_head.php'; ?>
<style>
    .stats-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }
    .stats-card .stat-item {
        text-align: center;
        padding: 10px;
    }
    .stats-card .stat-number {
        font-size: 2rem;
        font-weight: 700;
        display: block;
    }
    .stats-card .stat-label {
        font-size: 0.9rem;
        opacity: 0.9;
    }
    .course-badge {
        display: inline-block;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
    }
    .badge-unverified {
        background: #ffc107;
        color: #000;
    }
    .badge-verified {
        background: #28a745;
        color: #fff;
    }
    .occurrence-badge {
        background: #6c757d;
        color: white;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 0.8rem;
        font-weight: 600;
    }
    .duration-badge {
        background: #17a2b8;
        color: white;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 0.8rem;
    }
    .table-responsive {
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
        border-radius: 8px;
        overflow: hidden;
    }
    .table thead th {
        background: #495057;
        color: white;
        border: none;
        font-weight: 600;
    }
    .filter-section {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
    }
    .action-buttons {
        display: flex;
        gap: 5px;
    }
</style>
</head>
<body>
    <?php include '../../includes/admin/admin_topbar.php'; ?>
    <div id="wrapper" class="admin-wrapper">
        <?php include '../../includes/admin/admin_sidebar.php'; ?>
        <?php include '../../includes/admin/admin_header.php'; ?>
        
        <section class="home-section" id="mainContent">
            <div class="container-fluid py-4 px-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="fw-bold mb-1">Course Mapping Management</h1>
                        <p class="text-muted mb-0">Review and verify course mappings detected from enrollment forms</p>
                    </div>
                </div>

                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($_SESSION['success_message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($_SESSION['error_message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['error_message']); ?>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="stats-card">
                    <div class="row">
                        <div class="col-md-3 stat-item">
                            <span class="stat-number"><?php echo $stats['total']; ?></span>
                            <span class="stat-label">Total Mappings</span>
                        </div>
                        <div class="col-md-3 stat-item">
                            <span class="stat-number"><?php echo $stats['verified']; ?></span>
                            <span class="stat-label">Verified</span>
                        </div>
                        <div class="col-md-3 stat-item">
                            <span class="stat-number"><?php echo $stats['unverified']; ?></span>
                            <span class="stat-label">Pending Verification</span>
                        </div>
                        <div class="col-md-3 stat-item">
                            <span class="stat-number"><?php echo $stats['total_occurrences']; ?></span>
                            <span class="stat-label">Total Occurrences</span>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filter-section">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <input type="text" name="search" class="form-control" placeholder="Search course name..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-2">
                            <select name="status" class="form-select">
                                <option value="unverified" <?php echo $status_filter === 'unverified' ? 'selected' : ''; ?>>Unverified Only</option>
                                <option value="verified" <?php echo $status_filter === 'verified' ? 'selected' : ''; ?>>Verified Only</option>
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="university" class="form-select">
                                <option value="">All Universities</option>
                                <?php foreach ($universities as $univ): ?>
                                    <option value="<?php echo $univ['university_id']; ?>" <?php echo $university_filter == $univ['university_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($univ['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="category" class="form-select">
                                <option value="">All Categories</option>
                                <?php if ($categories): foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat['course_category']); ?>" <?php echo $category_filter === $cat['course_category'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['course_category']); ?>
                                    </option>
                                <?php endforeach; endif; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search"></i> Filter
                            </button>
                        </div>
                    </form>
                </div>

                <?php if (empty($courseMappings)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-journal-check display-1 text-muted"></i>
                        <h3 class="mt-3 text-muted">No Course Mappings Found</h3>
                        <p class="text-muted">Course mappings will appear here when students register with enrollment forms.</p>
                    </div>
                <?php else: ?>
                    <!-- Results Table -->
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 5%">
                                        <input type="checkbox" id="selectAll" onclick="toggleSelectAll()">
                                    </th>
                                    <th>Raw Course Name</th>
                                    <th>Normalized Course</th>
                                    <th>University</th>
                                    <th>Category</th>
                                    <th>Duration</th>
                                    <th>Occurrences</th>
                                    <th>Students</th>
                                    <th>Status</th>
                                    <th>Last Seen</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($courseMappings as $mapping): ?>
                                    <tr>
                                        <td class="text-center">
                                            <input type="checkbox" class="row-select" value="<?php echo $mapping['mapping_id']; ?>" onchange="updateSelection()">
                                        </td>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($mapping['raw_course_name']); ?></div>
                                            <?php if ($mapping['notes']): ?>
                                                <small class="text-muted"><i class="bi bi-sticky"></i> <?php echo htmlspecialchars($mapping['notes']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($mapping['normalized_course']); ?></td>
                                        <td>
                                            <small><?php echo htmlspecialchars($mapping['university_name'] ?? 'All Universities'); ?></small>
                                        </td>
                                        <td>
                                            <?php if ($mapping['course_category']): ?>
                                                <span class="badge bg-info"><?php echo htmlspecialchars($mapping['course_category']); ?></span>
                                            <?php else: ?>
                                                <small class="text-muted">Not set</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="duration-badge"><?php echo $mapping['program_duration']; ?> years</span>
                                        </td>
                                        <td>
                                            <span class="occurrence-badge"><?php echo $mapping['occurrence_count']; ?>x</span>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo $mapping['student_count']; ?> students</span>
                                        </td>
                                        <td>
                                            <?php if ($mapping['is_verified'] === 't'): ?>
                                                <span class="course-badge badge-verified">
                                                    <i class="bi bi-check-circle"></i> Verified
                                                </span>
                                                <?php if ($mapping['verified_by_username']): ?>
                                                    <br><small class="text-muted">by <?php echo htmlspecialchars($mapping['verified_by_username']); ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="course-badge badge-unverified">
                                                    <i class="bi bi-exclamation-triangle"></i> Unverified
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?php echo date('M d, Y', strtotime($mapping['last_seen'])); ?></small>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($mapping['is_verified'] === 'f'): ?>
                                                    <button class="btn btn-success btn-sm" 
                                                            onclick="showVerifyModal(<?php echo $mapping['mapping_id']; ?>, '<?php echo htmlspecialchars($mapping['raw_course_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($mapping['normalized_course'], ENT_QUOTES); ?>', <?php echo $mapping['program_duration']; ?>, '<?php echo htmlspecialchars($mapping['course_category'] ?? '', ENT_QUOTES); ?>')">
                                                        <i class="bi bi-check-circle"></i> Verify
                                                    </button>
                                                    <button class="btn btn-danger btn-sm" 
                                                            onclick="showRejectModal(<?php echo $mapping['mapping_id']; ?>, '<?php echo htmlspecialchars($mapping['raw_course_name'], ENT_QUOTES); ?>')">
                                                        <i class="bi bi-x-circle"></i> Reject
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn btn-info btn-sm" 
                                                            onclick="showViewModal(<?php echo $mapping['mapping_id']; ?>, <?php echo htmlspecialchars(json_encode($mapping), ENT_QUOTES); ?>)">
                                                        <i class="bi bi-eye"></i> View
                                                    </button>
                                                    <button class="btn btn-warning btn-sm" 
                                                            onclick="showEditModal(<?php echo $mapping['mapping_id']; ?>, '<?php echo htmlspecialchars($mapping['raw_course_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($mapping['normalized_course'], ENT_QUOTES); ?>', <?php echo $mapping['program_duration']; ?>, '<?php echo htmlspecialchars($mapping['course_category'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($mapping['notes'] ?? '', ENT_QUOTES); ?>', <?php echo $mapping['student_count']; ?>)">
                                                        <i class="bi bi-pencil"></i> Edit
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Bulk Actions -->
                    <div class="mt-3" id="bulkActions" style="display: none;">
                        <button class="btn btn-success" onclick="bulkVerify()">
                            <i class="bi bi-check-all"></i> Bulk Verify Selected (<span id="selectedCount">0</span>)
                        </button>
                        <button class="btn btn-outline-secondary" onclick="clearSelection()">Clear Selection</button>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="d-flex justify-content-between align-items-center mt-4">
                            <div class="pagination-info">
                                Showing <?php echo min(($page - 1) * $limit + 1, $totalRecords); ?> to <?php echo min($page * $limit, $totalRecords); ?> of <?php echo $totalRecords; ?> entries
                            </div>
                            <nav>
                                <ul class="pagination mb-0">
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Previous</a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $totalPages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- Verify Modal -->
    <div class="modal fade" id="verifyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Verify Course Mapping</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="verify_course">
                    <input type="hidden" name="mapping_id" id="verify_mapping_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Raw Course Name (from OCR)</label>
                            <input type="text" class="form-control" id="verify_raw_course" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Normalized Course Name <span class="text-danger">*</span></label>
                            <input type="text" name="normalized_course" class="form-control" id="verify_normalized_course" required>
                            <small class="form-text text-muted">Standard course name (e.g., BS Information Technology)</small>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Program Duration <span class="text-danger">*</span></label>
                                <select name="program_duration" class="form-select" id="verify_program_duration" required>
                                    <option value="4">4 years</option>
                                    <option value="5">5 years</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Course Category</label>
                                <select name="course_category" class="form-select" id="verify_course_category">
                                    <option value="">Select Category</option>
                                    <option value="IT/Computer Science">IT/Computer Science</option>
                                    <option value="Engineering">Engineering</option>
                                    <option value="Natural Sciences">Natural Sciences</option>
                                    <option value="Business">Business</option>
                                    <option value="Education">Education</option>
                                    <option value="Health Sciences">Health Sciences</option>
                                    <option value="Arts & Humanities">Arts & Humanities</option>
                                    <option value="Social Sciences">Social Sciences</option>
                                    <option value="Agriculture">Agriculture</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Admin Notes</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="Optional notes about this mapping..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> Verify Course
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reject Modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reject Course Mapping</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="reject_course">
                    <input type="hidden" name="mapping_id" id="reject_mapping_id">
                    <div class="modal-body">
                        <p>Are you sure you want to reject and delete this course mapping?</p>
                        <div class="alert alert-warning">
                            <strong id="reject_course_name"></strong>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Reason (optional)</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="Why is this mapping being rejected?"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-x-circle"></i> Reject & Delete
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Modal -->
    <div class="modal fade" id="viewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-eye"></i> Course Mapping Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="fw-bold text-muted small">Raw Course Name (OCR)</label>
                            <p class="mb-0" id="view_raw_course"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold text-muted small">Normalized Course Name</label>
                            <p class="mb-0" id="view_normalized_course"></p>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="fw-bold text-muted small">Program Duration</label>
                            <p class="mb-0"><span class="badge bg-info" id="view_duration"></span></p>
                        </div>
                        <div class="col-md-4">
                            <label class="fw-bold text-muted small">Category</label>
                            <p class="mb-0"><span class="badge bg-primary" id="view_category"></span></p>
                        </div>
                        <div class="col-md-4">
                            <label class="fw-bold text-muted small">Verification Status</label>
                            <p class="mb-0"><span class="badge bg-success">Verified</span></p>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="fw-bold text-muted small">University</label>
                            <p class="mb-0" id="view_university"></p>
                        </div>
                        <div class="col-md-3">
                            <label class="fw-bold text-muted small">Student Count</label>
                            <p class="mb-0"><span class="badge bg-secondary" id="view_student_count"></span></p>
                        </div>
                        <div class="col-md-3">
                            <label class="fw-bold text-muted small">Occurrences</label>
                            <p class="mb-0"><span class="badge bg-secondary" id="view_occurrences"></span></p>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="fw-bold text-muted small">Verified By</label>
                            <p class="mb-0" id="view_verified_by"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold text-muted small">Last Seen</label>
                            <p class="mb-0" id="view_last_seen"></p>
                        </div>
                    </div>
                    <div class="row mb-3" id="view_notes_section" style="display: none;">
                        <div class="col-md-12">
                            <label class="fw-bold text-muted small">Admin Notes</label>
                            <div class="alert alert-light mb-0" id="view_notes"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil"></i> Edit Course Mapping
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="edit_course">
                    <input type="hidden" name="mapping_id" id="edit_mapping_id">
                    <div class="modal-body">
                        <div id="edit_student_warning" class="alert alert-warning" style="display: none;">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>Warning:</strong> <span id="edit_student_count_text"></span> student(s) are currently enrolled in this course.
                            Changing the program duration or normalized name may affect their records.
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Raw Course Name (from OCR)</label>
                            <input type="text" class="form-control" id="edit_raw_course" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Normalized Course Name <span class="text-danger">*</span></label>
                            <input type="text" name="normalized_course" class="form-control" id="edit_normalized_course" required>
                            <small class="form-text text-muted">Standard course name (e.g., BS Information Technology)</small>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Program Duration <span class="text-danger">*</span></label>
                                <select name="program_duration" class="form-select" id="edit_program_duration" required>
                                    <option value="4">4 years</option>
                                    <option value="5">5 years</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Course Category</label>
                                <select name="course_category" class="form-select" id="edit_course_category">
                                    <option value="">Select Category</option>
                                    <option value="IT/Computer Science">IT/Computer Science</option>
                                    <option value="Engineering">Engineering</option>
                                    <option value="Natural Sciences">Natural Sciences</option>
                                    <option value="Business">Business</option>
                                    <option value="Education">Education</option>
                                    <option value="Health Sciences">Health Sciences</option>
                                    <option value="Arts & Humanities">Arts & Humanities</option>
                                    <option value="Social Sciences">Social Sciences</option>
                                    <option value="Agriculture">Agriculture</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Admin Notes</label>
                            <textarea name="notes" class="form-control" id="edit_notes" rows="3" placeholder="Optional notes about this mapping..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/admin/sidebar.js"></script>
    
    <script>
        let selectedMappings = [];

        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.row-select');
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            updateSelection();
        }

        function updateSelection() {
            const checkboxes = document.querySelectorAll('.row-select:checked');
            selectedMappings = Array.from(checkboxes).map(cb => cb.value);
            
            const count = selectedMappings.length;
            document.getElementById('selectedCount').textContent = count;
            document.getElementById('bulkActions').style.display = count > 0 ? 'block' : 'none';
            
            const allCheckboxes = document.querySelectorAll('.row-select');
            const selectAll = document.getElementById('selectAll');
            selectAll.indeterminate = count > 0 && count < allCheckboxes.length;
            selectAll.checked = count === allCheckboxes.length && count > 0;
        }

        function clearSelection() {
            document.querySelectorAll('.row-select').forEach(cb => cb.checked = false);
            document.getElementById('selectAll').checked = false;
            updateSelection();
        }

        function bulkVerify() {
            if (selectedMappings.length === 0) {
                alert('Please select courses to verify.');
                return;
            }

            if (!confirm(`Verify ${selectedMappings.length} course mapping(s)?\n\nThis will mark them as verified with default settings.`)) {
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="bulk_verify">
                <input type="hidden" name="mapping_ids" value="${selectedMappings.join(',')}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function showVerifyModal(id, rawCourse, normalizedCourse, duration, category) {
            document.getElementById('verify_mapping_id').value = id;
            document.getElementById('verify_raw_course').value = rawCourse;
            document.getElementById('verify_normalized_course').value = normalizedCourse;
            document.getElementById('verify_program_duration').value = duration;
            document.getElementById('verify_course_category').value = category || '';
            
            new bootstrap.Modal(document.getElementById('verifyModal')).show();
        }

        function showRejectModal(id, courseName) {
            document.getElementById('reject_mapping_id').value = id;
            document.getElementById('reject_course_name').textContent = courseName;
            
            new bootstrap.Modal(document.getElementById('rejectModal')).show();
        }

        function showViewModal(id, mappingData) {
            const data = mappingData;
            
            // Populate view modal fields
            document.getElementById('view_raw_course').textContent = data.raw_course_name;
            document.getElementById('view_normalized_course').textContent = data.normalized_course;
            document.getElementById('view_duration').textContent = data.program_duration + ' years';
            document.getElementById('view_category').textContent = data.course_category || 'Not set';
            document.getElementById('view_university').textContent = data.university_name || 'All Universities';
            document.getElementById('view_student_count').textContent = data.student_count + ' students';
            document.getElementById('view_occurrences').textContent = data.occurrence_count + 'x';
            document.getElementById('view_verified_by').textContent = data.verified_by_username || 'Unknown';
            
            // Format last seen date
            if (data.last_seen) {
                const date = new Date(data.last_seen);
                document.getElementById('view_last_seen').textContent = date.toLocaleDateString('en-US', {
                    month: 'short', day: 'numeric', year: 'numeric'
                });
            }
            
            // Show/hide notes section
            if (data.notes && data.notes.trim() !== '') {
                document.getElementById('view_notes').textContent = data.notes;
                document.getElementById('view_notes_section').style.display = 'block';
            } else {
                document.getElementById('view_notes_section').style.display = 'none';
            }
            
            new bootstrap.Modal(document.getElementById('viewModal')).show();
        }

        function showEditModal(id, rawCourse, normalizedCourse, duration, category, notes, studentCount) {
            document.getElementById('edit_mapping_id').value = id;
            document.getElementById('edit_raw_course').value = rawCourse;
            document.getElementById('edit_normalized_course').value = normalizedCourse;
            document.getElementById('edit_program_duration').value = duration;
            document.getElementById('edit_course_category').value = category || '';
            document.getElementById('edit_notes').value = notes || '';
            
            // Show warning if students are enrolled
            if (studentCount > 0) {
                document.getElementById('edit_student_count_text').textContent = studentCount;
                document.getElementById('edit_student_warning').style.display = 'block';
            } else {
                document.getElementById('edit_student_warning').style.display = 'none';
            }
            
            new bootstrap.Modal(document.getElementById('editModal')).show();
        }
    </script>
</body>
</html>

<?php pg_close($connection); ?>
