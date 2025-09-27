<?php
include __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

// Pagination and filtering
$limit = 25;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// Search and filter parameters
$search = trim($_GET['search'] ?? '');
$reason_filter = $_GET['reason'] ?? '';
$sort_by = $_GET['sort'] ?? 'blacklisted_at';
$sort_order = $_GET['order'] ?? 'DESC';

// Build WHERE clause
$whereConditions = ["s.status = 'blacklisted'"];
$params = [];
$paramCount = 1;

if (!empty($search)) {
    $whereConditions[] = "(s.first_name ILIKE $" . $paramCount . " OR s.last_name ILIKE $" . $paramCount . " OR s.email ILIKE $" . $paramCount . ")";
    $params[] = "%$search%";
    $paramCount++;
}

if (!empty($reason_filter)) {
    $whereConditions[] = "bl.reason_category = $" . $paramCount;
    $params[] = $reason_filter;
    $paramCount++;
}

$whereClause = implode(' AND ', $whereConditions);

// Valid sort columns
$validSorts = ['blacklisted_at', 'first_name', 'last_name', 'reason_category'];
if (!in_array($sort_by, $validSorts)) $sort_by = 'blacklisted_at';
if (!in_array($sort_order, ['ASC', 'DESC'])) $sort_order = 'DESC';

// Count total records
$countQuery = "SELECT COUNT(*) FROM students s
               JOIN blacklisted_students bl ON s.student_id = bl.student_id
               LEFT JOIN admins a ON bl.blacklisted_by = a.admin_id
               WHERE $whereClause";

$countResult = pg_query_params($connection, $countQuery, $params);
$totalRecords = intval(pg_fetch_result($countResult, 0, 0));
$totalPages = ceil($totalRecords / $limit);

// Fetch blacklisted students with pagination
$query = "SELECT s.*, bl.*, 
                 CONCAT(a.first_name, ' ', a.last_name) as blacklisted_by_name,
                 b.name as barangay_name
          FROM students s
          JOIN blacklisted_students bl ON s.student_id = bl.student_id
          LEFT JOIN admins a ON bl.blacklisted_by = a.admin_id
          LEFT JOIN barangays b ON s.barangay_id = b.barangay_id
          WHERE $whereClause
          ORDER BY bl.$sort_by $sort_order
          LIMIT $limit OFFSET $offset";

$result = pg_query_params($connection, $query, $params);
$blacklistedStudents = [];
while ($row = pg_fetch_assoc($result)) {
    $blacklistedStudents[] = $row;
}

// Get reason categories for filter
$reasonCategories = [
    'fraudulent_activity' => 'Fraudulent Activity',
    'academic_misconduct' => 'Academic Misconduct',
    'system_abuse' => 'System Abuse',
    'other' => 'Other'
];
?>

<?php $page_title='Blacklist Archive'; include '../../includes/admin/admin_head.php'; ?>
    <style>
        .blacklist-header {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        
        .filter-section {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }
        
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .table thead th {
            background: #dc3545;
            color: white;
            border: none;
            font-weight: 600;
        }
        
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .reason-badge {
            font-size: 0.875rem;
            padding: 0.5rem 0.75rem;
        }
        
        .reason-fraudulent { background-color: #dc3545; }
        .reason-academic { background-color: #fd7e14; }
        .reason-system { background-color: #6f42c1; }
        .reason-other { background-color: #6c757d; }
        
        .sort-link {
            color: white;
            text-decoration: none;
        }
        
        .sort-link:hover {
            color: #f8f9fa;
        }
        
        .sort-active {
            font-weight: bold;
        }
        
        .pagination-info {
            color: #6c757d;
            font-size: 0.875rem;
        }
        
        .details-btn {
            background: none;
            border: none;
            color: #007bff;
            text-decoration: underline;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div id="wrapper">
        <?php include '../../includes/admin/admin_sidebar.php'; ?>
        
        <section class="home-section" id="mainContent">
            <div class="blacklist-header">
                <div class="container">
                    <h1 class="display-5 fw-bold mb-0">
                        <i class="bi bi-person-x-fill"></i> Blacklist Archive
                        </head>
                    <body>
                        <?php include '../../includes/admin/admin_topbar.php'; ?>
                        <div id="wrapper" class="admin-wrapper">
                            <?php include '../../includes/admin/admin_sidebar.php'; ?>
                            <?php include '../../includes/admin/admin_header.php'; ?>
                            <section class="home-section" id="mainContent">
                                <div class="container-fluid py-4 px-4">
                            <input type="text" class="form-control" name="search" 
                                   value="<?= htmlspecialchars($search) ?>" 
                                   placeholder="Name or email...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Reason Category</label>
                            <select class="form-select" name="reason">
                                <option value="">All Reasons</option>
                                <?php foreach ($reasonCategories as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= $reason_filter === $key ? 'selected' : '' ?>>
                                        <?= $label ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Sort By</label>
                            <select class="form-select" name="sort">
                                <option value="blacklisted_at" <?= $sort_by === 'blacklisted_at' ? 'selected' : '' ?>>Date Blacklisted</option>
                                <option value="first_name" <?= $sort_by === 'first_name' ? 'selected' : '' ?>>First Name</option>
                                <option value="last_name" <?= $sort_by === 'last_name' ? 'selected' : '' ?>>Last Name</option>
                                <option value="reason_category" <?= $sort_by === 'reason_category' ? 'selected' : '' ?>>Reason</option>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">Order</label>
                            <select class="form-select" name="order">
                                <option value="DESC" <?= $sort_order === 'DESC' ? 'selected' : '' ?>>↓</option>
                                <option value="ASC" <?= $sort_order === 'ASC' ? 'selected' : '' ?>>↑</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="bi bi-search"></i> Filter
                            </button>
                            <a href="blacklist_archive.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-clockwise"></i>
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Results Section -->
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-shield-x"></i> Blacklisted Students
                            <span class="badge bg-light text-danger ms-2"><?= $totalRecords ?></span>
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($blacklistedStudents)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-shield-check display-1 text-success"></i>
                                <h3 class="mt-3">No Blacklisted Students</h3>
                                <p class="text-muted">No students match your search criteria.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Student Info</th>
                                            <th>Contact</th>
                                            <th>Reason</th>
                                            <th>Blacklisted By</th>
                                            <th>Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($blacklistedStudents as $student): ?>
                                            <tr>
                                                <td>
                                                    <div>
                                                        <strong><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></strong>
                                                        <br>
                                                        <small class="text-muted"><?= htmlspecialchars($student['barangay_name'] ?? 'N/A') ?></small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div>
                                                        <i class="bi bi-envelope"></i> <?= htmlspecialchars($student['email']) ?>
                                                        <br>
                                                        <i class="bi bi-phone"></i> <?= htmlspecialchars($student['mobile'] ?? 'N/A') ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php
                                                    $reasonClass = 'reason-' . str_replace('_', '-', $student['reason_category']);
                                                    ?>
                                                    <span class="badge <?= $reasonClass ?> reason-badge">
                                                        <?= $reasonCategories[$student['reason_category']] ?>
                                                    </span>
                                                    <?php if (!empty($student['detailed_reason'])): ?>
                                                        <br>
                                                        <small class="text-muted"><?= htmlspecialchars(substr($student['detailed_reason'], 0, 50)) ?>...</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div>
                                                        <strong><?= htmlspecialchars($student['blacklisted_by_name'] ?? 'System') ?></strong>
                                                        <br>
                                                        <small class="text-muted"><?= htmlspecialchars($student['admin_email']) ?></small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div>
                                                        <?= date('M j, Y', strtotime($student['blacklisted_at'])) ?>
                                                        <br>
                                                        <small class="text-muted"><?= date('g:i A', strtotime($student['blacklisted_at'])) ?></small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-info" onclick="viewDetails('<?= $student['student_id'] ?>')">
                                                        <i class="bi bi-eye"></i> Details
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <div class="d-flex justify-content-between align-items-center p-3 border-top">
                                <div class="pagination-info">
                                    Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $totalRecords) ?> of <?= $totalRecords ?> entries
                                </div>
                                
                                <?php if ($totalPages > 1): ?>
                                    <nav>
                                        <ul class="pagination mb-0">
                                            <?php
                                            $currentUrl = $_SERVER['REQUEST_URI'];
                                            $urlParts = parse_url($currentUrl);
                                            parse_str($urlParts['query'] ?? '', $queryParams);
                                            unset($queryParams['page']);
                                            $baseUrl = $urlParts['path'] . '?' . http_build_query($queryParams);
                                            $baseUrl .= empty($queryParams) ? 'page=' : '&page=';
                                            ?>
                                            
                                            <?php if ($page > 1): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="<?= $baseUrl . ($page - 1) ?>">Previous</a>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <?php
                                            $start = max(1, $page - 2);
                                            $end = min($totalPages, $page + 2);
                                            
                                            for ($i = $start; $i <= $end; $i++): ?>
                                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                    <a class="page-link" href="<?= $baseUrl . $i ?>"><?= $i ?></a>
                                                </li>
                                            <?php endfor; ?>
                                            
                                            <?php if ($page < $totalPages): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="<?= $baseUrl . ($page + 1) ?>">Next</a>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </nav>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-person-x-fill"></i> Blacklist Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailsContent">
                    <!-- Content will be loaded via AJAX -->
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/admin/sidebar.js"></script>
    
    <script>
        function viewDetails(studentId) {
            // Show loading state
            document.getElementById('detailsContent').innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-danger" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading details...</p>
                </div>
            `;
            
            // Show modal
            new bootstrap.Modal(document.getElementById('detailsModal')).show();
            
            // Fetch details via AJAX
            fetch('blacklist_details.php?student_id=' + studentId)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('detailsContent').innerHTML = html;
                })
                .catch(error => {
                    document.getElementById('detailsContent').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle"></i>
                            Error loading details. Please try again.
                        </div>
                    `;
                });
        }
    </script>
</body>
</html>

<?php pg_close($connection); ?>