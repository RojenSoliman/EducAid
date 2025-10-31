# Phase 1 Migration: Create Independent Tables

## Overview

This phase creates two new tables for the Year Level Management System:
1. `academic_years` - Tracks academic school years and advancement status
2. `courses_mapping` - Normalizes course names and stores program duration

## Quick Start

```bash
cd c:\xampp\htdocs\EducAid\sql\migrations
php run_phase_1_migration.php
```

## What Gets Created

### 1. academic_years Table
- 5 academic years (2023-2024 through 2027-2028)
- Current year: 2025-2026
- Tracks advancement status
- Prevents double advancement

### 2. courses_mapping Table
- 46 common courses pre-loaded
- 9 categories (Engineering, Science, Business, etc.)
- Program duration (4 or 5 years)
- Fuzzy text matching enabled (pg_trgm)

## Files

- `001_create_academic_years_table.sql` - Academic years table
- `002_create_courses_mapping_table.sql` - Courses mapping table
- `run_phase_1_migration.php` - Master migration runner
- `check_tables.php` - Verification script

## Verification

Check if migration was successful:

```bash
php check_tables.php
```

## Rollback (if needed)

```sql
DROP TABLE IF EXISTS courses_mapping CASCADE;
DROP TABLE IF EXISTS academic_years CASCADE;
DROP EXTENSION IF EXISTS pg_trgm;
```

## Next Steps

After successful completion, proceed to **Phase 2**: Add columns to students table
