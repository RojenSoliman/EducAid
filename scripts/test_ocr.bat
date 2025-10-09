@echo off
REM OCR Grade Processing Test Script for Windows
REM Usage: test_ocr.bat <image_file>

setlocal enabledelayedexpansion

REM Configuration
set TESSERACT_PATH=tesseract
set TEMP_DIR=.\temp_ocr
set DPI=350

REM Create temp directory
if not exist "%TEMP_DIR%" mkdir "%TEMP_DIR%"

REM Check if file provided
if "%~1"=="" (
    echo Error: Please provide an image file
    echo Usage: %0 ^<image_file^>
    exit /b 1
)

set INPUT_FILE=%~1
if not exist "%INPUT_FILE%" (
    echo Error: File not found: %INPUT_FILE%
    exit /b 1
)

REM Check if tesseract is installed
%TESSERACT_PATH% --version >nul 2>&1
if errorlevel 1 (
    echo Error: Tesseract not found. Please install tesseract-ocr
    echo Download from: https://github.com/UB-Mannheim/tesseract/wiki
    exit /b 1
)

echo [%time%] Processing: %INPUT_FILE%

REM Get file info
for %%i in ("%INPUT_FILE%") do (
    set FILE_EXT=%%~xi
    set BASENAME=%%~ni
)

set OUTPUT_BASE=%TEMP_DIR%\!BASENAME!_processed

echo [%time%] File type: !FILE_EXT!

REM Use original file (preprocessing would require ImageMagick)
set PROCESSED_FILE=%INPUT_FILE%

REM Run Tesseract OCR with TSV output
echo [%time%] Running Tesseract OCR...
set TSV_FILE=%OUTPUT_BASE%.tsv
set TXT_FILE=%OUTPUT_BASE%.txt

REM TSV output for structured data
%TESSERACT_PATH% "%PROCESSED_FILE%" "%OUTPUT_BASE%" -l eng --oem 1 --psm 6 tsv >nul 2>&1
if errorlevel 1 (
    echo Error: Tesseract OCR failed
    exit /b 1
)

REM Plain text output for readability
%TESSERACT_PATH% "%PROCESSED_FILE%" "%OUTPUT_BASE%_text" -l eng --oem 1 --psm 6 >nul 2>&1

echo Success: OCR completed successfully

REM Display results
if exist "%TSV_FILE%" (
    echo.
    echo [%time%] TSV Output sample:
    echo Level	Page	Block	Par	Line	Word	Left	Top	Width	Height	Conf	Text
    REM Show first few high-confidence lines
    powershell -Command "Get-Content '%TSV_FILE%' | Select-Object -Skip 1 | Where-Object { $_.Split(\"`t\")[10] -gt 50 -and $_.Split(\"`t\")[11] -ne \"\" } | Select-Object -First 5"
)

if exist "%OUTPUT_BASE%_text.txt" (
    echo.
    echo [%time%] Plain text output sample:
    powershell -Command "Get-Content '%OUTPUT_BASE%_text.txt' | Select-Object -First 10 | ForEach-Object { \"  $_\" }"
)

REM Simple grade pattern detection
echo.
echo [%time%] Grade-like patterns found:
powershell -Command "Get-Content '%TSV_FILE%' | Select-Object -Skip 1 | ForEach-Object { $cols = $_.Split(\"`t\"); if ($cols[10] -gt 70 -and $cols[11] -match '^[1-5]\.[0-9]{2}$|^[0-4]\.[0-9]+$|^[89][0-9]$|^[A-D][+-]?$') { \"  $($cols[11]) (confidence: $($cols[10])%%)\" } } | Sort-Object -Unique"

echo.
echo [%time%] Test completed
echo.
echo Files generated:
echo   - TSV: %TSV_FILE%
if exist "%OUTPUT_BASE%_text.txt" echo   - Text: %OUTPUT_BASE%_text.txt
echo.
echo To test with different settings:
echo   - PSM modes: --psm 4 (single column), --psm 6 (uniform block), --psm 7 (single text line)
echo   - OEM modes: --oem 1 (LSTM), --oem 2 (Legacy + LSTM), --oem 3 (default)
echo   - Languages: -l eng+fil (for Filipino text)

pause