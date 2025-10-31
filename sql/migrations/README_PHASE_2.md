# Phase 2 Migration: Update Students Table

## Overview

This phase adds 7 new columns to the `students` table for year level management and course tracking.

## Quick Start

```bash
cd c:\xampp\htdocs\EducAid\sql\migrations
php run_phase_2_migration.php
```

## What Gets Added

### New Columns

1. **first_registered_academic_year** (VARCHAR)
   - Academic year when student first registered
   - Never changes - permanent record
   - Format: "2024-2025"

2. **current_academic_year** (VARCHAR)
   - Current academic year for the student
   - Updates during year advancement
   - Format: "2025-2026"

3. **year_level_history** (JSONB)
   - Complete progression history
   - Array of: `[{academic_year, year_level, updated_at}]`
   - Preserves all past year levels

4. **last_year_level_update** (TIMESTAMP)
   - When year level was last changed
   - Prevents double advancement

5. **course** (VARCHAR)
   - Student's degree program
   - Normalized from OCR (e.g., "BS Computer Science")
   - References courses_mapping table

6. **course_verified** (BOOLEAN)
   - TRUE if verified from enrollment form
   - Default: FALSE

7. **expected_graduation_year** (INTEGER)
   - Auto-calculated: registration year + program duration
   - Updates when course changes

### Triggers Created

1. **trigger_initialize_year_level_history**
   - Auto-creates initial history entry
   - Runs on INSERT/UPDATE of year_level

2. **trigger_calculate_graduation_year**
   - Auto-calculates graduation year
   - Based on course duration from courses_mapping
   - Runs on INSERT/UPDATE of course

### Indexes Created

- `idx_students_first_registered_year` - Fast lookup by registration year
- `idx_students_current_academic_year` - Fast lookup by current year
- `idx_students_course` - Fast lookup by course
- `idx_students_course_verified` - Fast filtering by verification status
- `idx_students_expected_graduation` - Fast lookup by graduation year
- `idx_students_year_level_history` - GIN index for JSONB queries

## Files

- `003_add_year_level_columns_to_students.sql` - Migration SQL
- `run_phase_2_migration.php` - Migration runner with verification

## Verification

The migration runner automatically verifies:
- ✓ All 7 columns added
- ✓ Both triggers created
- ✓ All indexes created

## Rollback (if needed)

```sql
-- Remove triggers
DROP TRIGGER IF EXISTS trigger_initialize_year_level_history ON students;
DROP TRIGGER IF EXISTS trigger_calculate_graduation_year ON students;

-- Remove functions
DROP FUNCTION IF EXISTS initialize_year_level_history();
DROP FUNCTION IF EXISTS calculate_expected_graduation_year();

-- Remove columns
ALTER TABLE students 
DROP COLUMN IF EXISTS first_registered_academic_year,
DROP COLUMN IF EXISTS current_academic_year,
DROP COLUMN IF EXISTS year_level_history,
DROP COLUMN IF EXISTS last_year_level_update,
DROP COLUMN IF EXISTS course,
DROP COLUMN IF EXISTS course_verified,
DROP COLUMN IF EXISTS expected_graduation_year;
```

## Next Steps

After successful completion:
1. Update student registration flow to set initial values
2. Create year level advancement page
3. Update OCR to extract and verify courses
4. Create course mapping management interface

## Dependencies

- ✅ Phase 1 must be completed first (academic_years, courses_mapping tables)
- ✅ Students table must exist
