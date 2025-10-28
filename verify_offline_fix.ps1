# Verify Offline Mode CSS Fix
# This script checks that all Bootstrap Icon CDN references have been replaced with local paths

Write-Host "=== Offline Mode CSS Fix Verification ===" -ForegroundColor Cyan
Write-Host ""

# Check if local Bootstrap Icons CSS exists
$bootstrapIconsPath = "c:\xampp\htdocs\EducAid\assets\css\bootstrap-icons.css"
if (Test-Path $bootstrapIconsPath) {
    Write-Host "[OK] Local Bootstrap Icons CSS exists" -ForegroundColor Green
    Write-Host "     Path: $bootstrapIconsPath" -ForegroundColor Gray
} else {
    Write-Host "[ERROR] Local Bootstrap Icons CSS NOT FOUND!" -ForegroundColor Red
    Write-Host "        Expected: $bootstrapIconsPath" -ForegroundColor Red
    exit 1
}

Write-Host ""

# Check for any remaining CDN references in student pages
$studentPath = "c:\xampp\htdocs\EducAid\modules\student\"
Write-Host "Checking for CDN references in student pages..." -ForegroundColor Yellow

$cdnPattern = "cdn\.jsdelivr.*bootstrap-icons"
$foundCDN = $false

Get-ChildItem -Path $studentPath -Filter "*.php" | Where-Object {
    # Skip test and ignore files
    $_.Name -notlike "*test*" -and 
    $_.Name -notlike "ignore_*"
} | ForEach-Object {
    $content = Get-Content $_.FullName -Raw
    if ($content -match $cdnPattern) {
        Write-Host "[WARNING] CDN reference found in: $($_.Name)" -ForegroundColor Yellow
        $foundCDN = $true
    }
}

if (-not $foundCDN) {
    Write-Host "[OK] No CDN references found in production files" -ForegroundColor Green
} else {
    Write-Host ""
    Write-Host "[ACTION NEEDED] Some files still have CDN references" -ForegroundColor Red
}

Write-Host ""

# Check modified files specifically
$modifiedFiles = @(
    "student_notifications.php",
    "student_homepage.php",
    "student_profile.php",
    "student_settings.php",
    "upload_document.php",
    "student_register.php",
    "qr_code.php",
    "index.php"
)

Write-Host "Verifying modified files use local Bootstrap Icons..." -ForegroundColor Yellow

$allCorrect = $true
foreach ($file in $modifiedFiles) {
    $filePath = Join-Path $studentPath $file
    if (Test-Path $filePath) {
        $content = Get-Content $filePath -Raw
        
        if ($content -match "bootstrap-icons\.css") {
            if ($content -match "cdn\.jsdelivr.*bootstrap-icons") {
                Write-Host "[FAIL] $file still uses CDN" -ForegroundColor Red
                $allCorrect = $false
            } elseif ($content -match "\.\./\.\./assets/css/bootstrap-icons\.css") {
                Write-Host "[OK] $file uses local path" -ForegroundColor Green
            } else {
                Write-Host "[?] $file has unusual path" -ForegroundColor Yellow
            }
        } else {
            Write-Host "[?] $file doesn't include Bootstrap Icons" -ForegroundColor Gray
        }
    } else {
        Write-Host "[SKIP] $file not found" -ForegroundColor Gray
    }
}

Write-Host ""

if ($allCorrect -and -not $foundCDN) {
    Write-Host "=== VERIFICATION PASSED ===" -ForegroundColor Green
    Write-Host "All files correctly use local Bootstrap Icons CSS" -ForegroundColor Green
    Write-Host ""
    Write-Host "Next steps:" -ForegroundColor Cyan
    Write-Host "1. Test offline mode by disconnecting internet" -ForegroundColor White
    Write-Host "2. Navigate to each student page" -ForegroundColor White
    Write-Host "3. Verify all icons display correctly" -ForegroundColor White
} else {
    Write-Host "=== VERIFICATION FAILED ===" -ForegroundColor Red
    Write-Host "Some files still need fixing" -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "Press any key to exit..."
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
