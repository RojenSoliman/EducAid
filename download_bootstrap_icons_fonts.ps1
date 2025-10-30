Write-Host "Downloading Bootstrap Icons Fonts..." -ForegroundColor Cyan

# Create directory
$fontsDir = "c:\xampp\htdocs\EducAid\assets\css\fonts"
if (-not (Test-Path $fontsDir)) {
    New-Item -ItemType Directory -Path $fontsDir -Force | Out-Null
    Write-Host "Created directory: $fontsDir" -ForegroundColor Green
}

# Download woff2
Write-Host "Downloading bootstrap-icons.woff2..." -ForegroundColor Yellow
try {
    Invoke-WebRequest -Uri "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/fonts/bootstrap-icons.woff2" `
                      -OutFile "$fontsDir\bootstrap-icons.woff2"
    Write-Host "SUCCESS: bootstrap-icons.woff2 downloaded" -ForegroundColor Green
} catch {
    Write-Host "ERROR: Failed to download woff2: $_" -ForegroundColor Red
}

# Download woff
Write-Host "Downloading bootstrap-icons.woff..." -ForegroundColor Yellow
try {
    Invoke-WebRequest -Uri "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/fonts/bootstrap-icons.woff" `
                      -OutFile "$fontsDir\bootstrap-icons.woff"
    Write-Host "SUCCESS: bootstrap-icons.woff downloaded" -ForegroundColor Green
} catch {
    Write-Host "ERROR: Failed to download woff: $_" -ForegroundColor Red
}

# Verify
Write-Host ""
Write-Host "Verifying files..." -ForegroundColor Cyan
$files = Get-ChildItem "$fontsDir\bootstrap-icons.*" -ErrorAction SilentlyContinue
if ($files.Count -eq 2) {
    Write-Host "SUCCESS! Both font files are present:" -ForegroundColor Green
    $files | ForEach-Object {
        $sizeKB = [math]::Round($_.Length / 1KB, 2)
        Write-Host "   - $($_.Name) ($sizeKB KB)" -ForegroundColor White
    }
} else {
    Write-Host "WARNING: Expected 2 files, found $($files.Count)" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "Done! Icons should now work offline." -ForegroundColor Green
Write-Host "Remember to clear your browser cache (Ctrl+Shift+Delete) and test!" -ForegroundColor Cyan
