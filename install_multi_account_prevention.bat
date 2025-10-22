@echo off
REM =====================================================
REM Multi-Account Prevention System - Installation Script
REM =====================================================

echo.
echo ========================================
echo  Multi-Account Prevention System
echo  Database Schema Installation
echo ========================================
echo.

REM Set your PostgreSQL details here
set PGHOST=localhost
set PGPORT=5432
set PGUSER=postgres
set PGDATABASE=educaid

echo Database: %PGDATABASE%
echo User: %PGUSER%
echo.

echo Installing schema...
echo.

REM Run the SQL file
psql -h %PGHOST% -p %PGPORT% -U %PGUSER% -d %PGDATABASE% -f create_school_student_id_schema.sql

if %ERRORLEVEL% EQU 0 (
    echo.
    echo ========================================
    echo  Installation Successful!
    echo ========================================
    echo.
    echo Tables created:
    echo  - school_student_ids
    echo  - school_student_id_audit
    echo.
    echo Views created:
    echo  - v_school_student_id_duplicates
    echo.
    echo Functions created:
    echo  - check_duplicate_school_student_id()
    echo  - get_school_student_ids()
    echo.
    echo Triggers created:
    echo  - trigger_track_school_student_id
    echo.
    echo Next steps:
    echo 1. Test the registration form
    echo 2. Try entering a duplicate school student ID
    echo 3. Check the audit logs
    echo.
    echo For more information, see MULTI_ACCOUNT_PREVENTION_GUIDE.md
    echo.
) else (
    echo.
    echo ========================================
    echo  Installation Failed!
    echo ========================================
    echo.
    echo Error: Could not install schema
    echo.
    echo Possible causes:
    echo 1. PostgreSQL not running
    echo 2. Wrong database name
    echo 3. Wrong username/password
    echo 4. Permission issues
    echo.
    echo Please check your PostgreSQL settings and try again.
    echo.
)

pause
