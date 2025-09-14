<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blacklist System Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h5><i class="bi bi-bug"></i> Blacklist System Test</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            <strong>Test Purpose:</strong> This page is for testing the blacklist modal and service functionality.
                            It should only be accessible by super admins during development.
                        </div>

                        <h6>Test Scenarios:</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="card border-primary">
                                    <div class="card-body text-center">
                                        <h6 class="card-title">Test Student 1</h6>
                                        <p class="card-text">
                                            <strong>Name:</strong> John Doe<br>
                                            <strong>Email:</strong> john.doe@test.com<br>
                                            <strong>Status:</strong> Applicant
                                        </p>
                                        <button class="btn btn-danger btn-sm" 
                                                onclick="showBlacklistModal(999, 'John Doe', 'john.doe@test.com', {
                                                    barangay: 'Test Barangay',
                                                    status: 'Applicant'
                                                })">
                                            <i class="bi bi-shield-exclamation"></i> Test Blacklist
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="card border-success">
                                    <div class="card-body text-center">
                                        <h6 class="card-title">Test Student 2</h6>
                                        <p class="card-text">
                                            <strong>Name:</strong> Jane Smith<br>
                                            <strong>Email:</strong> jane.smith@test.com<br>
                                            <strong>Status:</strong> Active
                                        </p>
                                        <button class="btn btn-danger btn-sm" 
                                                onclick="showBlacklistModal(998, 'Jane Smith', 'jane.smith@test.com', {
                                                    barangay: 'Another Barangay',
                                                    university: 'Test University',
                                                    status: 'Active Student'
                                                })">
                                            <i class="bi bi-shield-exclamation"></i> Test Blacklist
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <h6>Testing Instructions:</h6>
                            <ol class="small">
                                <li>Click "Test Blacklist" button on any test student</li>
                                <li>Fill in the blacklist form with test data</li>
                                <li>Enter admin password (should match your actual admin password)</li>
                                <li>Click "Send Verification Code" - check your admin email</li>
                                <li>Enter the 6-digit OTP code from email</li>
                                <li>Click "CONFIRM BLACKLIST" to complete the test</li>
                                <li>Check the blacklist archive page to see the result</li>
                            </ol>
                        </div>

                        <div class="mt-3">
                            <a href="modules/admin/blacklist_archive.php" class="btn btn-outline-primary">
                                <i class="bi bi-archive"></i> View Blacklist Archive
                            </a>
                            <a href="modules/admin/manage_applicants.php" class="btn btn-outline-secondary">
                                <i class="bi bi-person-vcard"></i> Manage Applicants
                            </a>
                        </div>

                        <div class="alert alert-warning mt-3">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>Warning:</strong> This is a test page. In production, remove this file for security.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Include Blacklist Modal -->
    <?php include 'includes/admin/blacklist_modal.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>