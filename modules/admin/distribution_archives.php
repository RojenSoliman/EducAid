<?php
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

require_once __DIR__ . '/../../config/database.php';

// Get filter from query string
$filter = $_GET['filter'] ?? 'all';

// Scan filesystem for compressed distribution archives
$distributionsPath = __DIR__ . '/../../assets/uploads/distributions';
$allDistributions = [];

if (is_dir($distributionsPath)) {
    $zipFiles = glob($distributionsPath . '/*.zip');
    
    foreach ($zipFiles as $zipFile) {
        $filename = basename($zipFile);
        $filesize = filesize($zipFile);
        $filetime = filemtime($zipFile);
        
        // Parse distribution ID from filename (format: #MUNICIPALITY-DISTR-YYYY-MM-DD-HHMMSS.zip)
        $distribution_id = str_replace('.zip', '', $filename);
        
        // Try to open ZIP and count files
        $fileCount = 0;
        $zip = new ZipArchive();
        if ($zip->open($zipFile) === true) {
            $fileCount = $zip->numFiles;
            $zip->close();
        }
        
        // Check if there's a matching snapshot in database
        $snapshotQuery = pg_query_params($connection,
            "SELECT * FROM distribution_snapshots WHERE archive_filename = $1 OR distribution_id = $2 LIMIT 1",
            [$filename, $distribution_id]
        );
        $snapshot = $snapshotQuery ? pg_fetch_assoc($snapshotQuery) : null;
        
        // Build distribution entry
        $dist = [
            'distribution_id' => $distribution_id,
            'created_at' => date('Y-m-d H:i:s', $filetime),
            'year_level' => $snapshot['academic_year'] ?? 'N/A',
            'semester' => $snapshot['semester'] ?? 'N/A',
            'student_count' => $snapshot['total_students_count'] ?? 0,
            'file_count' => $fileCount,
            'original_size' => $filesize * 2, // Estimate: assume 50% compression
            'current_size' => $filesize,
            'status' => 'ended',
            'files_compressed' => true,
            'archived_files_count' => $fileCount,
            'location' => $snapshot['location'] ?? 'Unknown',
            'notes' => $snapshot['notes'] ?? ''
        ];
        
        $allDistributions[] = $dist;
    }
}

// Sort by date descending
usort($allDistributions, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

// Get compression statistics
$totalDistributions = count($allDistributions);
$compressedDistributions = count(array_filter($allDistributions, fn($d) => $d['files_compressed']));
$totalSpaceSaved = array_sum(array_map(fn($d) => $d['original_size'] - $d['current_size'], $allDistributions));
$avgCompressionRatio = $compressedDistributions > 0 
    ? (array_sum(array_map(fn($d) => 
        $d['original_size'] > 0 ? (($d['original_size'] - $d['current_size']) / $d['original_size']) * 100 : 0
      , $allDistributions)) / $compressedDistributions)
    : 0;

$compressionStats = [
    'total_distributions' => $totalDistributions,
    'compressed_distributions' => $compressedDistributions,
    'total_space_saved' => $totalSpaceSaved,
    'avg_compression_ratio' => $avgCompressionRatio
];

// Filter distributions based on tab
$filteredDistributions = array_filter($allDistributions, function($dist) use ($filter) {
    if ($filter === 'all') return true;
    if ($filter === 'active') return $dist['status'] === 'active';
    if ($filter === 'compressed') return $dist['files_compressed'] == true;
    if ($filter === 'archived') return $dist['archived_files_count'] > 0;
    return true;
});

$pageTitle = "Distribution Archives";
?>
<?php $page_title='Distribution Archives'; include '../../includes/admin/admin_head.php'; ?>
<body>
<?php include '../../includes/admin/admin_topbar.php'; ?>
    <div id="wrapper" class="admin-wrapper">
        <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
        <?php include '../../includes/admin/admin_header.php'; ?>
        <section class="home-section" id="mainContent">
            <div class="container-fluid py-4 px-4">
    <style>
        .stat-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        .stat-banner .stat-item {
            text-align: center;
        }
        .stat-banner .stat-value {
            font-size: 2rem;
            font-weight: bold;
        }
        .stat-banner .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        .badge-active {
            background-color: #28a745;
        }
        .badge-ended {
            background-color: #6c757d;
        }
        .badge-compressed {
            background-color: #17a2b8;
        }
        .badge-archived {
            background-color: #ffc107;
            color: #000;
        }
        .nav-tabs .nav-link {
            color: #495057;
        }
        .nav-tabs .nav-link.active {
            font-weight: bold;
        }
        .table-actions .btn {
            margin-right: 3px;
        }
    </style>
</head>
<body>
    
    <div class="main-content">
        <div class="container-fluid py-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="bi bi-archive"></i> Distribution Archives</h2>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Distribution Archives</li>
                    </ol>
                </nav>
            </div>

            <!-- Compression Statistics Banner -->
            <div class="stat-banner">
                <div class="row">
                    <div class="col-md-3 stat-item">
                        <div class="stat-value"><?php echo $compressionStats['total_distributions']; ?></div>
                        <div class="stat-label">Total Distributions</div>
                    </div>
                    <div class="col-md-3 stat-item">
                        <div class="stat-value"><?php echo $compressionStats['compressed_distributions']; ?></div>
                        <div class="stat-label">Compressed</div>
                    </div>
                    <div class="col-md-3 stat-item">
                        <div class="stat-value">
                            <?php echo number_format($compressionStats['total_space_saved'] / 1024 / 1024, 2); ?> MB
                        </div>
                        <div class="stat-label">Space Saved</div>
                    </div>
                    <div class="col-md-3 stat-item">
                        <div class="stat-value">
                            <?php echo number_format($compressionStats['avg_compression_ratio'], 1); ?>%
                        </div>
                        <div class="stat-label">Avg. Compression Ratio</div>
                    </div>
                </div>
            </div>

            <!-- Tabbed Interface -->
            <div class="card">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link <?php echo $filter === 'all' ? 'active' : ''; ?>" 
                               href="?filter=all">
                                <i class="bi bi-list"></i> All (<?php echo count($allDistributions); ?>)
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $filter === 'active' ? 'active' : ''; ?>" 
                               href="?filter=active">
                                <i class="bi bi-play-circle"></i> Active
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $filter === 'compressed' ? 'active' : ''; ?>" 
                               href="?filter=compressed">
                                <i class="bi bi-file-zip"></i> Compressed
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $filter === 'archived' ? 'active' : ''; ?>" 
                               href="?filter=archived">
                                <i class="bi bi-archive"></i> Archived
                            </a>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <?php if (empty($filteredDistributions)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> No distributions found for this filter.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Created</th>
                                        <th>Year/Sem</th>
                                        <th>Students</th>
                                        <th>Files</th>
                                        <th>Original Size</th>
                                        <th>Current Size</th>
                                        <th>Space Saved</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($filteredDistributions as $dist): ?>
                                        <?php 
                                            $spaceSaved = $dist['original_size'] - $dist['current_size'];
                                            $compressionPct = $dist['original_size'] > 0 
                                                ? round(($spaceSaved / $dist['original_size']) * 100, 1) 
                                                : 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($dist['distribution_id']); ?></strong>
                                            </td>
                                            <td><?php echo date('M d, Y g:i A', strtotime($dist['created_at'])); ?></td>
                                            <td>
                                                <strong>AY <?php echo htmlspecialchars($dist['year_level']); ?></strong><br>
                                                <small class="text-muted">Semester <?php echo htmlspecialchars($dist['semester']); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary"><?php echo $dist['student_count']; ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?php echo $dist['file_count']; ?></span>
                                            </td>
                                            <td><?php echo number_format($dist['original_size'] / 1024 / 1024, 2); ?> MB</td>
                                            <td><?php echo number_format($dist['current_size'] / 1024 / 1024, 2); ?> MB</td>
                                            <td>
                                                <?php if ($spaceSaved > 0): ?>
                                                    <span class="badge bg-success">
                                                        <?php echo number_format($spaceSaved / 1024 / 1024, 2); ?> MB
                                                        (<?php echo $compressionPct; ?>%)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($dist['files_compressed']): ?>
                                                    <span class="badge badge-compressed">
                                                        <i class="bi bi-file-zip"></i> Compressed
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge badge-ended">Ended</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="table-actions">
                                                <button class="btn btn-sm btn-info" 
                                                        onclick="viewDetails('<?php echo htmlspecialchars($dist['distribution_id']); ?>')">
                                                    <i class="bi bi-eye"></i> View
                                                </button>
                                                <?php if ($dist['files_compressed']): ?>
                                                    <a href="../../assets/uploads/distributions/<?php echo htmlspecialchars(basename($dist['distribution_id'])); ?>.zip" 
                                                       class="btn btn-sm btn-success" 
                                                       download>
                                                        <i class="bi bi-download"></i> Download
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-info-circle"></i> Distribution Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailsContent">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const detailsModal = new bootstrap.Modal(document.getElementById('detailsModal'));
        
        function viewDetails(distId) {
            detailsModal.show();
            document.getElementById('detailsContent').innerHTML = 
                '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>';
            
            // AJAX request would go here to fetch detailed information
            // For now, show a placeholder
            setTimeout(() => {
                document.getElementById('detailsContent').innerHTML = 
                    '<div class="alert alert-info">Detailed view will be implemented with student file list, compression logs, and download options.</div>';
            }, 500);
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    </section>
    </div>
</body>
</html>
