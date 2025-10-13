<?php
/**
 * Archive Viewer - View archived documents from previous distribution cycles
 */
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

include '../../config/database.php';

// Get filter parameters
$student_search = isset($_GET['student']) ? trim($_GET['student']) : '';
$distribution_filter = isset($_GET['distribution']) ? intval($_GET['distribution']) : 0;
$document_type_filter = isset($_GET['doc_type']) ? trim($_GET['doc_type']) : '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build query conditions
$where_conditions = ["1=1"];
$params = [];
$param_count = 0;

if (!empty($student_search)) {
    $param_count++;
    $where_conditions[] = "(da.student_id ILIKE $" . $param_count . " OR s.first_name ILIKE $" . $param_count . " OR s.last_name ILIKE $" . $param_count . ")";
    $params[] = "%$student_search%";
}

if ($distribution_filter > 0) {
    $param_count++;
    $where_conditions[] = "da.distribution_snapshot_id = $" . $param_count;
    $params[] = $distribution_filter;
}

if (!empty($document_type_filter)) {
    $param_count++;
    $where_conditions[] = "da.document_type = $" . $param_count;
    $params[] = $document_type_filter;
}

$where_clause = implode(' AND ', $where_conditions);

// Count total records
$count_query = "
    SELECT COUNT(*) as total
    FROM document_archives da
    LEFT JOIN students s ON da.student_id = s.student_id
    WHERE $where_clause
";

$count_result = !empty($params) ? 
    pg_query_params($connection, $count_query, $params) : 
    pg_query($connection, $count_query);

$total_records = $count_result ? pg_fetch_assoc($count_result)['total'] : 0;
$total_pages = ceil($total_records / $per_page);

// Get archived documents
$query = "
    SELECT 
        da.*,
        CONCAT(s.first_name, ' ', s.last_name) as student_name,
        s.email as student_email,
        ds.distribution_date,
        ds.location as distribution_location
    FROM document_archives da
    LEFT JOIN students s ON da.student_id = s.student_id
    LEFT JOIN distribution_snapshots ds ON da.distribution_snapshot_id = ds.snapshot_id
    WHERE $where_clause
    ORDER BY da.archived_date DESC
    LIMIT $per_page OFFSET $offset
";

$result = !empty($params) ? 
    pg_query_params($connection, $query, $params) : 
    pg_query($connection, $query);

// Get available distributions for filter
$distributions_query = "SELECT snapshot_id, distribution_date, academic_year, semester, total_students_count FROM distribution_snapshots ORDER BY distribution_date DESC";
$distributions_result = pg_query($connection, $distributions_query);

// Get document types for filter
$doc_types_query = "SELECT DISTINCT document_type FROM document_archives ORDER BY document_type";
$doc_types_result = pg_query($connection, $doc_types_query);
?>

<!DOCTYPE html>
<html lang="en">
<?php $page_title='Document Archives'; include '../../includes/admin/admin_head.php'; ?>
<body>
<?php include '../../includes/admin/admin_topbar.php'; ?>

<div id="wrapper" class="admin-wrapper">
    <?php include '../../includes/admin/admin_sidebar.php'; ?>
    <?php include '../../includes/admin/admin_header.php'; ?>
    
    <section class="home-section" id="mainContent">
        <div class="container-fluid py-4">
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <div>
                                    <h1 class="h3 mb-1">
                                        <i class="bi bi-archive text-primary me-2"></i>
                                        Document Archives
                                    </h1>
                                    <p class="text-muted mb-0">View archived documents from previous distribution cycles</p>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-info fs-6"><?= number_format($total_records) ?> Total Archives</span>
                                </div>
                            </div>
                            
                            <!-- Filters -->
                            <form method="GET" class="row g-3 mb-4">
                                <div class="col-md-3">
                                    <label class="form-label">Student Search</label>
                                    <input type="text" class="form-control" name="student" value="<?= htmlspecialchars($student_search) ?>" placeholder="Name or Student ID">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Distribution</label>
                                    <select class="form-select" name="distribution">
                                        <option value="">All Distributions</option>
                                        <?php if ($distributions_result): ?>
                                            <?php while ($dist = pg_fetch_assoc($distributions_result)): ?>
                                                <option value="<?= $dist['snapshot_id'] ?>" <?= $distribution_filter == $dist['snapshot_id'] ? 'selected' : '' ?>>
                                                    <?= date('M j, Y', strtotime($dist['distribution_date'])) ?> - 
                                                    <?= htmlspecialchars($dist['academic_year']) ?> <?= htmlspecialchars($dist['semester']) ?>
                                                    (<?= $dist['total_students_count'] ?> students)
                                                </option>
                                            <?php endwhile; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Document Type</label>
                                    <select class="form-select" name="doc_type">
                                        <option value="">All Types</option>
                                        <?php if ($doc_types_result): ?>
                                            <?php while ($type = pg_fetch_assoc($doc_types_result)): ?>
                                                <option value="<?= htmlspecialchars($type['document_type']) ?>" <?= $document_type_filter == $type['document_type'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $type['document_type']))) ?>
                                                </option>
                                            <?php endwhile; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">&nbsp;</label>
                                    <div class="d-grid gap-2 d-md-block">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-search me-1"></i> Filter
                                        </button>
                                        <a href="?" class="btn btn-outline-secondary">
                                            <i class="bi bi-arrow-clockwise"></i>
                                        </a>
                                    </div>
                                </div>
                            </form>
                            
                            <!-- Results Table -->
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Student</th>
                                            <th>Document Type</th>
                                            <th>Distribution</th>
                                            <th>Academic Period</th>
                                            <th>Archived Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($result && pg_num_rows($result) > 0): ?>
                                            <?php while ($archive = pg_fetch_assoc($result)): ?>
                                                <tr>
                                                    <td>
                                                        <div>
                                                            <strong><?= htmlspecialchars($archive['student_name']) ?></strong>
                                                            <br>
                                                            <small class="text-muted"><?= htmlspecialchars($archive['student_id']) ?></small>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-secondary">
                                                            <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $archive['document_type']))) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($archive['distribution_date']): ?>
                                                            <?= date('M j, Y', strtotime($archive['distribution_date'])) ?>
                                                            <br>
                                                            <small class="text-muted"><?= htmlspecialchars($archive['distribution_location']) ?></small>
                                                        <?php else: ?>
                                                            <span class="text-muted">N/A</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($archive['academic_year'] && $archive['semester']): ?>
                                                            <?= htmlspecialchars($archive['academic_year']) ?><br>
                                                            <small class="text-muted"><?= htmlspecialchars($archive['semester']) ?></small>
                                                        <?php else: ?>
                                                            <span class="text-muted">N/A</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?= date('M j, Y g:i A', strtotime($archive['archived_date'])) ?>
                                                    </td>
                                                    <td>
                                                        <?php if (file_exists($archive['file_path'])): ?>
                                                            <a href="<?= htmlspecialchars($archive['file_path']) ?>" 
                                                               class="btn btn-sm btn-outline-primary" 
                                                               target="_blank"
                                                               title="View Document">
                                                                <i class="bi bi-eye"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="text-muted" title="File not found">
                                                                <i class="bi bi-exclamation-triangle"></i>
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-4">
                                                    <i class="bi bi-inbox display-6 text-muted d-block mb-2"></i>
                                                    <p class="text-muted">No archived documents found matching your criteria.</p>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                                <nav aria-label="Archive pagination" class="mt-4">
                                    <ul class="pagination justify-content-center">
                                        <?php if ($page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                                    <i class="bi bi-chevron-left"></i>
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                        
                                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                                    <?= $i ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <?php if ($page < $total_pages): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                                    <i class="bi bi-chevron-right"></i>
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>
</body>
</html>

<?php 
if ($result) pg_free_result($result);
if ($distributions_result) pg_free_result($distributions_result);
if ($doc_types_result) pg_free_result($doc_types_result);
pg_close($connection); 
?>