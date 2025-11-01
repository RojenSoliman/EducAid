@echo off
REM Slot Threshold Monitor - Windows Task Scheduler Helper
REM Run this script every 2-4 hours during distribution periods

echo ========================================
echo Slot Threshold Monitor
echo ========================================
echo.

REM Change to script directory
cd /d "%~dp0"

REM Run the PHP script
php.exe check_slot_thresholds.php

REM Log the execution
echo.
echo Execution completed at %date% %time%
echo.

REM Optional: Keep window open for 5 seconds to see results
timeout /t 5
