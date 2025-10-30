<?php
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/DistributionManager.php';

$distManager = new DistributionManager();

// Get storage statistics
$storageStats = $distManager->getStorageStatistics();
$compressionStats = $distManager->getCompressionStatistics();

// Get recent logs without distribution_id join (since file_archive_log might not have it)
$recentLogsQuery = "SELECT 
                        fal.*,
                        TRIM(CONCAT(COALESCE(a.first_name,''),' ',COALESCE(a.last_name,''))) as admin_name,
                        s.student_id as lrn,
                        s.first_name || ' ' || s.last_name as student_name
                     FROM file_archive_log fal
                     LEFT JOIN admins a ON fal.performed_by = a.admin_id
                     LEFT JOIN students s ON fal.student_id = s.student_id
                     ORDER BY fal.performed_at DESC
                     LIMIT 15";
$recentLogsResult = pg_query($connection, $recentLogsQuery);
$recentLogs = $recentLogsResult ? pg_fetch_all($recentLogsResult) ?: [] : [];

// Calculate total storage and percentages
$totalStorage = 0;
$storageByCategory = [];
foreach ($storageStats as $stat) {
    $totalStorage += $stat['total_size'];
    $storageByCategory[$stat['category']] = $stat;
}

// Get max storage from settings
$settingsFile = __DIR__ . '/../../data/municipal_settings.json';
$maxStorageGB = 100; // default
if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true);
    $maxStorageGB = $settings['max_storage_gb'] ?? 100;
}
$maxStorageBytes = $maxStorageGB * 1024 * 1024 * 1024;
$storagePercent = ($totalStorage / $maxStorageBytes) * 100;

$pageTitle = "Storage Dashboard";
?>
<?php $page_title='Storage Dashboard'; include '../../includes/admin/admin_head.php'; ?>
<body>
<?php include '../../includes/admin/admin_topbar.php'; ?>
    <div id="wrapper" class="admin-wrapper">
        <?php include __DIR__ . '/../../includes/admin/admin_sidebar.php'; ?>
        <?php include '../../includes/admin/admin_header.php'; ?>
        <section class="home-section" id="mainContent">
            <div class="container-fluid py-4 px-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="bi bi-hdd-stack"></i> Storage Dashboard</h2>
                </div>

                <!-- Storage Capacity Progress -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-pie-chart"></i> Storage Capacity</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <span>
                                <strong><?php echo number_format($totalStorage / (1024*1024*1024), 2); ?> GB</strong> 
                                of <?php echo $maxStorageGB; ?> GB used
                            </span>
                            <span><strong><?php echo number_format($storagePercent, 1); ?>%</strong></span>
                        </div>
                <?php
                $progressColor = 'bg-success';
                if ($storagePercent > 80) {
                    $progressColor = 'bg-danger';
                } elseif ($storagePercent > 60) {
                    $progressColor = 'bg-warning';
                }
                ?>
                        <div class="progress" style="height: 30px;">
                            <div class="progress-bar <?php echo $progressColor; ?>" 
                                 role="progressbar" 
                                 style="width: <?php echo min($storagePercent, 100); ?>%;">
                                <?php echo number_format($storagePercent, 1); ?>%
                            </div>
                                <?php if ($storagePercent > 80): ?>
                            <div class="alert alert-danger mt-3 mb-0">
                                <i class="bi bi-exclamation-triangle"></i>
                                <strong>Warning:</strong> Storage capacity is critically high (<?php echo number_format($storagePercent, 1); ?>%). Consider archiving or compressing old distributions.
                            </div>
                        <?php elseif ($storagePercent > 60): ?>
                            <div class="alert alert-warning mt-3 mb-0">
                                <i class="bi bi-exclamation-circle"></i>
                                <strong>Notice:</strong> Storage usage is above 60%. Monitor capacity and plan for archiving.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Metric Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card border-left-success shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Storage Used</div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($totalStorage / (1024*1024*1024), 2); ?> GB</div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-hdd fs-2 text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card border-left-primary shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Active Students</div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php 
                                            $activeStudents = isset($storageByCategory['active']) ? $storageByCategory['active']['student_count'] : 0;
                                            echo number_format($activeStudents); 
                                            ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-people fs-2 text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card border-left-info shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Archived Students</div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php 
                                            $archivedStudents = isset($storageByCategory['archived']) ? $storageByCategory['archived']['student_count'] : 0;
                                            echo number_format($archivedStudents); 
                                            ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-archive fs-2 text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card border-left-warning shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Space Saved</div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?php echo number_format(($compressionStats['total_space_saved'] ?? 0) / (1024*1024), 2); ?> MB
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="bi bi-file-earmark-zip fs-2 text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="row mb-4">
                    <div class="col-lg-6 mb-3">
                        <div class="card shadow">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary"><i class="bi bi-pie-chart-fill"></i> Storage Distribution</h6>
                            </div>
                            <div class="card-body">
                                <div style="position: relative; height: 300px;">
                                    <canvas id="storageChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-6 mb-3">
                        <div class="card shadow">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary"><i class="bi bi-bar-chart-fill"></i> Files by Category</h6>
                            </div>
                            <div class="card-body">
                                <div style="position: relative; height: 300px;">
                                    <canvas id="filesChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Storage Breakdown Table -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="bi bi-table"></i> Storage Breakdown</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Category</th>
                                        <th class="text-end">Students</th>
                                        <th class="text-end">Files</th>
                                        <th class="text-end">Storage Size</th>
                                        <th class="text-end">% of Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($storageStats as $stat): 
                                        $percentage = $totalStorage > 0 ? ($stat['total_size'] / $totalStorage) * 100 : 0;
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo $stat['category'] === 'active' ? 'success' : 
                                                    ($stat['category'] === 'distributions' ? 'primary' :
                                                    ($stat['category'] === 'archived' ? 'info' : 'secondary')); 
                                            ?>">
                                                <?php 
                                                    if ($stat['category'] === 'distributions') {
                                                        echo 'Past Distributions';
                                                    } else {
                                                        echo ucfirst($stat['category']);
                                                    }
                                                ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <?php 
                                                if ($stat['category'] === 'distributions') {
                                                    echo number_format($stat['student_count']) . ' unique';
                                                } else {
                                                    echo number_format($stat['student_count']);
                                                }
                                            ?>
                                        </td>
                                        <td class="text-end"><?php echo number_format($stat['file_count']); ?></td>
                                        <td class="text-end">
                                            <strong><?php echo number_format($stat['total_size'] / (1024*1024), 2); ?> MB</strong>
                                        </td>
                                        <td class="text-end">
                                            <?php echo number_format($percentage, 1); ?>%
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($storageStats)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">
                                            <i class="bi bi-inbox fs-1"></i><br>
                                            No storage data available
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Recent Archive Operations -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="bi bi-clock-history"></i> Recent Archive Operations</h6>
                        <span class="badge bg-secondary"><?php echo count($recentLogs); ?> records</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Timestamp</th>
                                        <th>Operation</th>
                                        <th>Student</th>
                                        <th>Performed By</th>
                                        <th>Status</th>
                                        <th class="text-end">Size</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentLogs as $log): ?>
                                    <tr>
                                        <td>
                                            <small><?php echo date('M d, Y H:i', strtotime($log['performed_at'] ?? 'now')); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-info">
                                                <?php echo strtoupper($log['operation'] ?? 'UNKNOWN'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($log['student_name'])): ?>
                                                <small><?php echo htmlspecialchars($log['student_name']); ?></small><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($log['lrn'] ?? ''); ?></small>
                                            <?php else: ?>
                                                <small class="text-muted">—</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small><?php echo htmlspecialchars($log['admin_name'] ?? 'System'); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                $status = strtoupper($log['operation_status'] ?? 'UNKNOWN');
                                                echo $status === 'SUCCESS' ? 'success' : 
                                                    ($status === 'FAILED' ? 'danger' : 'secondary');
                                            ?>">
                                                <?php echo $status; ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <small><?php echo number_format(($log['total_size_before'] ?? 0) / (1024*1024), 2); ?> MB</small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($recentLogs)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">
                                            <i class="bi bi-inbox fs-1"></i><br>
                                            No archive operations recorded
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </section>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
        // Storage Distribution Pie Chart
        const storageCtx = document.getElementById('storageChart');
        if (storageCtx) {
            new Chart(storageCtx.getContext('2d'), {
                type: 'pie',
                data: {
                    labels: [
                        <?php foreach ($storageStats as $stat): ?>
                            '<?php echo ucfirst($stat['category']); ?>',
                        <?php endforeach; ?>
                    ],
                    datasets: [{
                        data: [
                            <?php foreach ($storageStats as $stat): ?>
                                <?php echo round($stat['total_size'] / (1024*1024), 2); ?>,
                            <?php endforeach; ?>
                        ],
                        backgroundColor: ['#3BB54A', '#1A70F0', '#3BC5DC', '#8DD672', '#6c757d']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': ' + context.parsed.toFixed(2) + ' MB';
                                }
                            }
                        }
                    }
                }
            });
        }

        // Files by Category Bar Chart
        const filesCtx = document.getElementById('filesChart');
        if (filesCtx) {
            new Chart(filesCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: [
                        <?php foreach ($storageStats as $stat): ?>
                            '<?php echo ucfirst($stat['category']); ?>',
                        <?php endforeach; ?>
                    ],
                    datasets: [
                        {
                            label: 'Students',
                            data: [
                                <?php foreach ($storageStats as $stat): ?>
                                    <?php echo $stat['student_count']; ?>,
                                <?php endforeach; ?>
                            ],
                            backgroundColor: '#1A70F0'
                        },
                        {
                            label: 'Files',
                            data: [
                                <?php foreach ($storageStats as $stat): ?>
                                    <?php echo $stat['file_count']; ?>,
                                <?php endforeach; ?>
                            ],
                            backgroundColor: '#3BB54A'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' } },
                    scales: { y: { beginAtZero: true } }
                }
            });
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>