<?php
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

include '../../config/database.php';
require_once '../../services/AuditLogger.php';

// Get admin info
$adminId = $_SESSION['admin_id'] ?? null;
$adminUsername = $_SESSION['admin_username'] ?? null;
$adminRole = $_SESSION['admin_role'] ?? null;

// Get admin's municipality
$adminMunicipalityId = null;
if ($adminId) {
    $admRes = pg_query_params($connection, 
        "SELECT municipality_id FROM admins WHERE admin_id = $1", 
        [$adminId]
    );
    if ($admRes && pg_num_rows($admRes)) {
        $adminMunicipalityId = pg_fetch_assoc($admRes)['municipality_id'];
    }
}

// Initialize AuditLogger
$auditLogger = new AuditLogger($connection);

// Handle unarchive action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unarchive') {
    header('Content-Type: application/json');
    
    $studentId = $_POST['student_id'] ?? null;
    
    if (!$studentId) {
        echo json_encode(['success' => false, 'message' => 'Student ID is required']);
        exit;
    }
    
    // Get student data before unarchiving for audit log
    $studentQuery = pg_query_params($connection,
        "SELECT student_id, first_name, last_name, middle_name, archive_reason, archived_at 
         FROM students WHERE student_id = $1",
        [$studentId]
    );
    
    if (!$studentQuery || pg_num_rows($studentQuery) === 0) {
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit;
    }
    
    $student = pg_fetch_assoc($studentQuery);
    $fullName = trim($student['first_name'] . ' ' . ($student['middle_name'] ?? '') . ' ' . $student['last_name']);
    
    // Unarchive student
    $result = pg_query_params($connection,
        "SELECT unarchive_student($1, $2) as success",
        [$studentId, $adminId]
    );
    
    if ($result && pg_fetch_assoc($result)['success'] === 't') {
        // Extract archived files back to permanent storage
        require_once '../../services/FileManagementService.php';
        $fileService = new FileManagementService($connection);
        $extractResult = $fileService->extractArchivedStudent($studentId);
        
        // Delete the ZIP file after successful extraction
        $zipDeleted = false;
        if ($extractResult['success']) {
            $zipDeleted = $fileService->deleteArchivedZip($studentId);
        }
        
        // Log to audit trail
        $auditLogger->logStudentUnarchived(
            $adminId,
            $adminUsername,
            $studentId,
            [
                'full_name' => $fullName,
                'archive_reason' => $student['archive_reason'],
                'archived_at' => $student['archived_at'],
                'files_restored' => $extractResult['files_extracted'] ?? 0,
                'zip_deleted' => $zipDeleted
            ]
        );
        
        $message = 'Student successfully unarchived';
        if (($extractResult['files_extracted'] ?? 0) > 0) {
            $message .= ' and ' . $extractResult['files_extracted'] . ' files restored';
        }
        if ($zipDeleted) {
            $message .= '. Archive ZIP file removed';
        }
        
        echo json_encode(['success' => true, 'message' => $message]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to unarchive student']);
    }
    exit;
}

// Handle ZIP file download
if (isset($_GET['download_zip']) && !empty($_GET['student_id'])) {
    $studentId = $_GET['student_id'];
    
    // Security check - verify student is archived and admin has permission
    $checkQuery = pg_query_params($connection,
        "SELECT first_name, last_name, is_archived FROM students WHERE student_id = $1",
        [$studentId]
    );
    
    if (!$checkQuery || pg_num_rows($checkQuery) === 0) {
        $_SESSION['error_message'] = 'Student not found';
        header('Location: archived_students.php');
        exit;
    }
    
    $studentData = pg_fetch_assoc($checkQuery);
    
    if ($studentData['is_archived'] !== 't') {
        $_SESSION['error_message'] = 'Student is not archived';
        header('Location: archived_students.php');
        exit;
    }
    
    // Get ZIP file path
    require_once '../../services/FileManagementService.php';
    $fileService = new FileManagementService($connection);
    $zipFile = $fileService->getArchivedStudentZip($studentId);
    
    if (!$zipFile || !file_exists($zipFile)) {
        $_SESSION['error_message'] = 'Archive file not found for this student';
        header('Location: archived_students.php');
        exit;
    }
    
    // Log the download action
    $auditLogger->logEvent(
        'student_archive_download',
        'student_management',
        "Downloaded archived documents for student: {$studentData['first_name']} {$studentData['last_name']} (ID: {$studentId})",
        [
            'admin_id' => $adminId,
            'admin_username' => $adminUsername,
            'student_id' => $studentId,
            'student_name' => $studentData['first_name'] . ' ' . $studentData['last_name']
        ]
    );
    
    // Send file for download
    $fileName = $studentId . '_archived_documents.zip';
    
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($zipFile));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    
    readfile($zipFile);
    exit;
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $whereConditions = ["s.is_archived = TRUE"];
    $params = [];
    $paramCount = 1;
    
    // Apply filters for export
    if (!empty($_GET['search'])) {
        $searchTerm = '%' . $_GET['search'] . '%';
        $whereConditions[] = "(s.first_name ILIKE $" . $paramCount . " OR s.last_name ILIKE $" . ($paramCount+1) . " OR s.email ILIKE $" . ($paramCount+2) . " OR s.student_id ILIKE $" . ($paramCount+3) . ")";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $paramCount += 4;
    }
    
    if (!empty($_GET['archive_type'])) {
        if ($_GET['archive_type'] === 'manual') {
            $whereConditions[] = "s.archived_by IS NOT NULL";
        } elseif ($_GET['archive_type'] === 'automatic') {
            $whereConditions[] = "s.archived_by IS NULL";
        }
    }
    
    if (!empty($_GET['year_level'])) {
        $whereConditions[] = "s.year_level_id = $" . $paramCount++;
        $params[] = $_GET['year_level'];
    }
    
    if (!empty($_GET['date_from'])) {
        $whereConditions[] = "s.archived_at >= $" . $paramCount++;
        $params[] = $_GET['date_from'] . ' 00:00:00';
    }
    
    if (!empty($_GET['date_to'])) {
        $whereConditions[] = "s.archived_at <= $" . $paramCount++;
        $params[] = $_GET['date_to'] . ' 23:59:59';
    }
    
    // Municipality filter - include students with NULL municipality (visible to all)
    if ($adminMunicipalityId) {
        $whereConditions[] = "(s.municipality_id = $" . $paramCount . " OR s.municipality_id IS NULL)";
        $params[] = $adminMunicipalityId;
        $paramCount++;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    $query = "
        SELECT 
            s.student_id,
            s.first_name,
            s.middle_name,
            s.last_name,
            s.email,
            s.mobile,
            yl.name as year_level,
            u.name as university,
            s.archived_at,
            s.archive_reason,
            CASE WHEN s.archived_by IS NULL THEN 'Automatic' ELSE 'Manual' END as archive_type,
            CONCAT(a.first_name, ' ', a.last_name) as archived_by_name
        FROM students s
        LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
        LEFT JOIN universities u ON s.university_id = u.university_id
        LEFT JOIN admins a ON s.archived_by = a.admin_id
        WHERE {$whereClause}
        ORDER BY s.archived_at DESC
    ";
    
    $result = pg_query_params($connection, $query, $params);
    
    // Generate CSV
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="archived_students_' . date('Y-m-d_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Student ID', 'First Name', 'Middle Name', 'Last Name', 'Email', 'Mobile', 'Year Level', 'University', 'Archived At', 'Archive Type', 'Archived By', 'Reason']);
    
    while ($row = pg_fetch_assoc($result)) {
        fputcsv($output, [
            $row['student_id'],
            $row['first_name'],
            $row['middle_name'],
            $row['last_name'],
            $row['email'],
            $row['mobile'],
            $row['year_level'],
            $row['university'],
            $row['archived_at'],
            $row['archive_type'],
            $row['archived_by_name'] ?? 'System',
            $row['archive_reason']
        ]);
    }
    
    fclose($output);
    exit;
}

// Get filter values
$searchTerm = $_GET['search'] ?? '';
$archiveType = $_GET['archive_type'] ?? '';
$yearLevel = $_GET['year_level'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Build query with filters
$whereConditions = ["s.is_archived = TRUE"];
$params = [];
$paramCount = 1;

if (!empty($searchTerm)) {
    $searchParam = '%' . $searchTerm . '%';
    $whereConditions[] = "(s.first_name ILIKE $" . $paramCount . " OR s.last_name ILIKE $" . ($paramCount+1) . " OR s.email ILIKE $" . ($paramCount+2) . " OR s.student_id ILIKE $" . ($paramCount+3) . ")";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $paramCount += 4;
}

if (!empty($archiveType)) {
    if ($archiveType === 'manual') {
        $whereConditions[] = "s.archived_by IS NOT NULL";
    } elseif ($archiveType === 'automatic') {
        $whereConditions[] = "s.archived_by IS NULL";
    }
}

if (!empty($yearLevel)) {
    $whereConditions[] = "s.year_level_id = $" . $paramCount++;
    $params[] = $yearLevel;
}

if (!empty($dateFrom)) {
    $whereConditions[] = "s.archived_at >= $" . $paramCount++;
    $params[] = $dateFrom . ' 00:00:00';
}

if (!empty($dateTo)) {
    $whereConditions[] = "s.archived_at <= $" . $paramCount++;
    $params[] = $dateTo . ' 23:59:59';
}

// Municipality filter - include students with NULL municipality (they're visible to all)
if ($adminMunicipalityId) {
    $whereConditions[] = "(s.municipality_id = $" . $paramCount . " OR s.municipality_id IS NULL)";
    $params[] = $adminMunicipalityId;
    $paramCount++;
}

$whereClause = implode(' AND ', $whereConditions);

// Get total count
$countQuery = "SELECT COUNT(*) as total FROM students s WHERE {$whereClause}";
$countResult = pg_query_params($connection, $countQuery, $params);
$totalRecords = pg_fetch_assoc($countResult)['total'];
$totalPages = ceil($totalRecords / $perPage);

// Get archived students
$params[] = $perPage;
$params[] = $offset;

$query = "
    SELECT 
        s.student_id,
        s.first_name,
        s.middle_name,
        s.last_name,
        s.extension_name,
        s.email,
        s.mobile,
        s.bdate,
        yl.name as year_level_name,
        u.name as university_name,
        s.academic_year_registered,
        s.expected_graduation_year,
        s.archived_at,
        s.archived_by,
        s.archive_reason,
        s.last_login,
        CONCAT(a.first_name, ' ', a.last_name) as archived_by_name,
        CASE 
            WHEN s.archived_by IS NULL THEN 'Automatic'
            ELSE 'Manual'
        END as archive_type
    FROM students s
    LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
    LEFT JOIN universities u ON s.university_id = u.university_id
    LEFT JOIN admins a ON s.archived_by = a.admin_id
    WHERE {$whereClause}
    ORDER BY s.archived_at DESC
    LIMIT $" . $paramCount++ . " OFFSET $" . $paramCount;

$result = pg_query_params($connection, $query, $params);

// Check for query errors
if (!$result) {
    error_log("Archived students query error: " . pg_last_error($connection));
    error_log("Query: " . $query);
    error_log("Params: " . print_r($params, true));
}

// Fetch all results into an array to avoid pointer issues later
$students = $result ? pg_fetch_all($result) : [];
$resultRowCount = $students ? count($students) : 0;

// Get year levels for filter
$yearLevelsQuery = pg_query($connection, "SELECT year_level_id, name FROM year_levels ORDER BY sort_order");
$yearLevels = pg_fetch_all($yearLevelsQuery);

// Get statistics
$statsQuery = "
    SELECT 
        COUNT(*) as total_archived,
        COUNT(CASE WHEN archived_by IS NULL THEN 1 END) as auto_archived,
        COUNT(CASE WHEN archived_by IS NOT NULL THEN 1 END) as manual_archived,
        COUNT(CASE WHEN archived_at >= CURRENT_DATE - INTERVAL '30 days' THEN 1 END) as archived_last_30_days
    FROM students
    WHERE is_archived = TRUE" . 
    ($adminMunicipalityId ? " AND (municipality_id = " . $adminMunicipalityId . " OR municipality_id IS NULL)" : "");

$statsResult = pg_query($connection, $statsQuery);
$stats = pg_fetch_assoc($statsResult);
?>
<?php $page_title='Archived Students'; $extra_css=['../../assets/css/admin/manage_applicants.css']; include '../../includes/admin/admin_head.php'; ?>
<style>
    :root {
        --primary-color: #2c3e50;
        --secondary-color: #3498db;
        --success-color: #27ae60;
        --warning-color: #f39c12;
        --danger-color: #e74c3c;
        --info-color: #3498db;
        --light-bg: #ecf0f1;
    }

    .stats-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 25px;
    }

    .stat-card {
        background: white;
        padding: 20px;
        border-radius: 10px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        transition: transform 0.3s ease;
        text-align: center;
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }

    .stat-number {
        font-size: 2.5rem;
        font-weight: bold;
        margin: 10px 0;
    }

    .stat-label {
        color: #7f8c8d;
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 500;
    }

    .text-primary { color: var(--primary-color) !important; }
    .text-success { color: var(--success-color) !important; }
    .text-info { color: var(--info-color) !important; }
    .text-warning { color: var(--warning-color) !important; }

    .filter-card {
        background: white;
        padding: 20px;
        border-radius: 10px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        margin-bottom: 20px;
    }

    .table-card {
        background: white;
        border-radius: 10px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        overflow: hidden;
    }

    .table {
        margin-bottom: 0;
    }

    .table thead th {
        background-color: var(--primary-color);
        color: white;
        font-weight: 600;
        border: none;
        padding: 15px;
    }

    .table tbody tr:hover {
        background-color: #f8f9fa;
    }

    .badge.automatic {
        background-color: #27ae60 !important;
        color: white;
    }

    .badge.manual {
        background-color: #3498db !important;
        color: white;
    }
    
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #7f8c8d;
    }
    
    .empty-state i {
        font-size: 4rem;
        margin-bottom: 20px;
        opacity: 0.3;
    }
    
    .table-section {
        background: white;
        padding: 20px;
        border-radius: 10px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .btn-action {
        padding: 5px 10px;
        font-size: 0.875rem;
    }

    .pagination-container {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px;
        background: white;
        border-top: 1px solid #dee2e6;
    }

    /* Fix modal z-index to appear above sidebar/topbar */
    .modal {
        z-index: 9999 !important;
    }
    
    .modal-backdrop {
        z-index: 9998 !important;
    }
</style>
<body>
<?php include '../../includes/admin/admin_topbar.php'; ?>
<div id="wrapper" class="admin-wrapper">
    <?php include '../../includes/admin/admin_sidebar.php'; ?>
    <?php include '../../includes/admin/admin_header.php'; ?>

    <section class="home-section" id="mainContent">
        <div class="container-fluid py-4 px-4">
            <div class="section-header mb-4 d-flex justify-content-between align-items-center">
                <h2 class="fw-bold text-primary mb-0">
                    <i class="bi bi-archive-fill"></i>
                    Archived Students
                </h2>
                <span class="badge bg-secondary fs-6"><?php echo $stats['total_archived']; ?> Archived</span>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-icon primary">
                        <i class="bi bi-archive"></i>
                    </div>
                    <div class="stat-details">
                        <h3><?php echo $stats['total_archived']; ?></h3>
                        <p>Total Archived</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-number text-success"><?php echo $stats['auto_archived']; ?></div>
                    <div class="stat-label">Automatic Archives</div>
                </div>

                <div class="stat-card">
                    <div class="stat-number text-info"><?php echo $stats['manual_archived']; ?></div>
                    <div class="stat-label">Manual Archives</div>
                </div>

                <div class="stat-card">
                    <div class="stat-number text-warning"><?php echo $stats['archived_last_30_days']; ?></div>
                    <div class="stat-label">Last 30 Days</div>
                </div>
            </div>

            <!-- Filter Card -->
            <div class="filter-card">
                <form method="GET" id="filterForm">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Search</label>
                        <input type="text" class="form-control" name="search" 
                               placeholder="Name, Email, or ID" 
                               value="<?php echo htmlspecialchars($searchTerm); ?>">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Archive Type</label>
                        <select class="form-select" name="archive_type">
                            <option value="">All Types</option>
                            <option value="automatic" <?php echo $archiveType === 'automatic' ? 'selected' : ''; ?>>Automatic</option>
                            <option value="manual" <?php echo $archiveType === 'manual' ? 'selected' : ''; ?>>Manual</option>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Year Level</label>
                        <select class="form-select" name="year_level">
                            <option value="">All Levels</option>
                            <?php foreach ($yearLevels as $yl): ?>
                                <option value="<?php echo $yl['year_level_id']; ?>" 
                                        <?php echo $yearLevel == $yl['year_level_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($yl['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Date From</label>
                        <input type="date" class="form-control" name="date_from" 
                               value="<?php echo htmlspecialchars($dateFrom); ?>">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Date To</label>
                        <input type="date" class="form-control" name="date_to" 
                               value="<?php echo htmlspecialchars($dateTo); ?>">
                    </div>

                    <div class="col-md-1 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-funnel"></i> Filter
                        </button>
                    </div>
                </div>

                <div class="row mt-3">
                    <div class="col-12">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearFilters()">
                            <i class="bi bi-x-circle"></i> Clear Filters
                        </button>
                        <a href="?export=csv<?php 
                            echo !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '';
                            echo !empty($archiveType) ? '&archive_type=' . urlencode($archiveType) : '';
                            echo !empty($yearLevel) ? '&year_level=' . urlencode($yearLevel) : '';
                            echo !empty($dateFrom) ? '&date_from=' . urlencode($dateFrom) : '';
                            echo !empty($dateTo) ? '&date_to=' . urlencode($dateTo) : '';
                        ?>" class="btn btn-success btn-sm">
                            <i class="bi bi-download"></i> Export to CSV
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Table Section -->
        <div class="table-section">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Archived Students (<?php echo number_format($totalRecords); ?>)</h5>
                <span class="text-muted">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
            </div>

            <?php if ($result && $resultRowCount > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Year Level</th>
                                <th>University</th>
                                <th>Archived At</th>
                                <th>Type</th>
                                <th>Archived By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            foreach ($students as $student):
                                $fullName = trim($student['first_name'] . ' ' . 
                                          ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . 
                                          $student['last_name'] . ' ' . 
                                          ($student['extension_name'] ?? ''));
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($student['student_id']); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($fullName); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($student['email']); ?></td>
                                    <td><?php echo htmlspecialchars($student['year_level_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($student['university_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('M d, Y h:i A', strtotime($student['archived_at'])); ?></td>
                                    <td>
                                        <span class="badge <?php echo strtolower($student['archive_type']); ?>">
                                            <?php echo $student['archive_type']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $student['archived_by_name'] ?? '<em>System</em>'; ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-info" 
                                                onclick="viewDetails('<?php echo htmlspecialchars($student['student_id'], ENT_QUOTES); ?>')">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <a href="?download_zip=1&student_id=<?php echo urlencode($student['student_id']); ?>" 
                                           class="btn btn-sm btn-primary"
                                           title="Download archived documents">
                                            <i class="bi bi-download"></i> ZIP
                                        </a>
                                        <button class="btn btn-sm btn-success btn-unarchive" 
                                                onclick="unarchiveStudent('<?php echo htmlspecialchars($student['student_id'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($fullName, ENT_QUOTES); ?>')">
                                            <i class="bi bi-arrow-counterclockwise"></i> Unarchive
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <nav>
                        <ul class="pagination">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?><?php 
                                        echo !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '';
                                        echo !empty($archiveType) ? '&archive_type=' . urlencode($archiveType) : '';
                                        echo !empty($yearLevel) ? '&year_level=' . urlencode($yearLevel) : '';
                                        echo !empty($dateFrom) ? '&date_from=' . urlencode($dateFrom) : '';
                                        echo !empty($dateTo) ? '&date_to=' . urlencode($dateTo) : '';
                                    ?>">Previous</a>
                                </li>
                            <?php endif; ?>

                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?><?php 
                                        echo !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '';
                                        echo !empty($archiveType) ? '&archive_type=' . urlencode($archiveType) : '';
                                        echo !empty($yearLevel) ? '&year_level=' . urlencode($yearLevel) : '';
                                        echo !empty($dateFrom) ? '&date_from=' . urlencode($dateFrom) : '';
                                        echo !empty($dateTo) ? '&date_to=' . urlencode($dateTo) : '';
                                    ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?><?php 
                                        echo !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '';
                                        echo !empty($archiveType) ? '&archive_type=' . urlencode($archiveType) : '';
                                        echo !empty($yearLevel) ? '&year_level=' . urlencode($yearLevel) : '';
                                        echo !empty($dateFrom) ? '&date_from=' . urlencode($dateFrom) : '';
                                        echo !empty($dateTo) ? '&date_to=' . urlencode($dateTo) : '';
                                    ?>">Next</a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>

            <?php elseif (!$result): ?>
                <div class="alert alert-danger">
                    <h4><i class="bi bi-exclamation-triangle"></i> Database Error</h4>
                    <p>Failed to retrieve archived students. Error: <?php echo htmlspecialchars(pg_last_error($connection)); ?></p>
                    <p><small>Please check the error log or contact system administrator.</small></p>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-archive"></i>
                    <h4>No Archived Students Found</h4>
                    <p>There are no archived students matching your criteria.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</body>div>

<!-- Student Details Modal - MUST BE OUTSIDE WRAPPER -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-info-circle"></i> Student Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailsContent">
                <!-- Content loaded via AJAX -->
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function clearFilters() {
            window.location.href = 'archived_students.php';
        }

        function viewDetails(studentId) {
            const modalEl = document.getElementById('detailsModal');
            const content = document.getElementById('detailsContent');
            
            // Clean up any existing modal instances and backdrops
            const existingBackdrops = document.querySelectorAll('.modal-backdrop');
            existingBackdrops.forEach(backdrop => backdrop.remove());
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
            
            // Dispose of any existing modal instance
            const existingModal = bootstrap.Modal.getInstance(modalEl);
            if (existingModal) {
                existingModal.dispose();
            }
            
            // Create fresh modal instance
            const modal = new bootstrap.Modal(modalEl);
            
            content.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"></div><p>Loading...</p></div>';
            modal.show();
            
            // Fetch student details
            fetch(`get_archived_student_details.php?student_id=${encodeURIComponent(studentId)}`)
                .then(response => response.text())
                .then(html => {
                    content.innerHTML = html;
                })
                .catch(error => {
                    content.innerHTML = '<div class="alert alert-danger">Error loading student details.</div>';
                });
        }
        
        // Clean up modal on close
        document.addEventListener('DOMContentLoaded', function() {
            const modalEl = document.getElementById('detailsModal');
            if (modalEl) {
                modalEl.addEventListener('hidden.bs.modal', function() {
                    // Clean up any lingering backdrops
                    const backdrops = document.querySelectorAll('.modal-backdrop');
                    backdrops.forEach(backdrop => backdrop.remove());
                    document.body.classList.remove('modal-open');
                    document.body.style.removeProperty('overflow');
                    document.body.style.removeProperty('padding-right');
                });
            }
        });

        function unarchiveStudent(studentId, studentName) {
            if (!confirm(`Are you sure you want to unarchive ${studentName}?\n\nThis will restore their account and they will be able to log in again.`)) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'unarchive');
            formData.append('student_id', studentId);

            fetch('archived_students.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('An error occurred. Please try again.');
                console.error(error);
            });
        }
    </script>

<script src="../../assets/js/admin/sidebar.js"></script>
</body>
</html>
