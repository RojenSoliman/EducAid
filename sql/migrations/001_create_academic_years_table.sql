-- ============================================================================
-- MIGRATION: Create Academic Years Table
-- ============================================================================
-- Purpose: Track academic years and year level advancement status
-- Date: October 31, 2025
-- Author: System Migration
-- Dependencies: None (independent table)
-- ============================================================================

-- Drop table if exists (for clean re-run)
DROP TABLE IF EXISTS academic_years CASCADE;

-- Create academic_years table
CREATE TABLE academic_years (
    academic_year_id SERIAL PRIMARY KEY,
    year_code VARCHAR(20) UNIQUE NOT NULL,  -- e.g., "2024-2025", "2025-2026"
    start_date DATE NOT NULL,               -- When academic year begins (e.g., June 1)
    end_date DATE NOT NULL,                 -- When it ends (e.g., May 31)
    is_current BOOLEAN DEFAULT FALSE,       -- Only one year should be current at a time
    year_levels_advanced BOOLEAN DEFAULT FALSE,  -- Has advancement been run for this year?
    advanced_by INTEGER REFERENCES admins(admin_id) ON DELETE SET NULL,  -- Which admin ran advancement
    advanced_at TIMESTAMP,                  -- When advancement was executed
    status VARCHAR(20) DEFAULT 'upcoming' CHECK (status IN ('upcoming', 'current', 'completed')),
    notes TEXT,                             -- Optional notes about this academic year
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Create indexes for performance
CREATE INDEX idx_academic_years_year_code ON academic_years(year_code);
CREATE INDEX idx_academic_years_is_current ON academic_years(is_current);
CREATE INDEX idx_academic_years_status ON academic_years(status);

-- Create trigger to update updated_at timestamp
CREATE OR REPLACE FUNCTION update_academic_years_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_update_academic_years_timestamp
    BEFORE UPDATE ON academic_years
    FOR EACH ROW
    EXECUTE FUNCTION update_academic_years_updated_at();

-- Create function to ensure only one academic year is current
CREATE OR REPLACE FUNCTION ensure_single_current_academic_year()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.is_current = TRUE THEN
        -- Set all other years to not current
        UPDATE academic_years 
        SET is_current = FALSE 
        WHERE academic_year_id != NEW.academic_year_id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_ensure_single_current_year
    BEFORE INSERT OR UPDATE ON academic_years
    FOR EACH ROW
    WHEN (NEW.is_current = TRUE)
    EXECUTE FUNCTION ensure_single_current_academic_year();

-- ============================================================================
-- SEED INITIAL DATA
-- ============================================================================

-- Insert past, current, and future academic years
INSERT INTO academic_years (year_code, start_date, end_date, is_current, status, notes) VALUES
    ('2023-2024', '2023-06-01', '2024-05-31', FALSE, 'completed', 'Past academic year - completed'),
    ('2024-2025', '2024-06-01', '2025-05-31', FALSE, 'completed', 'Past academic year - completed'),
    ('2025-2026', '2025-06-01', '2026-05-31', TRUE, 'current', 'Current academic year'),
    ('2026-2027', '2026-06-01', '2027-05-31', FALSE, 'upcoming', 'Future academic year'),
    ('2027-2028', '2027-06-01', '2028-05-31', FALSE, 'upcoming', 'Future academic year')
ON CONFLICT (year_code) DO NOTHING;

-- ============================================================================
-- VERIFICATION QUERIES
-- ============================================================================

-- Verify table creation
SELECT 
    'academic_years table created successfully' AS status,
    COUNT(*) AS total_years,
    SUM(CASE WHEN is_current THEN 1 ELSE 0 END) AS current_years,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_years,
    SUM(CASE WHEN status = 'upcoming' THEN 1 ELSE 0 END) AS upcoming_years
FROM academic_years;

-- Show current academic year
SELECT 
    year_code,
    start_date,
    end_date,
    status,
    year_levels_advanced,
    notes
FROM academic_years 
WHERE is_current = TRUE;

-- ============================================================================
-- COMMENTS FOR DOCUMENTATION
-- ============================================================================

COMMENT ON TABLE academic_years IS 'Tracks academic years and year level advancement status for the scholarship system';
COMMENT ON COLUMN academic_years.year_code IS 'Academic year in format YYYY-YYYY (e.g., 2024-2025)';
COMMENT ON COLUMN academic_years.is_current IS 'Only one academic year should be marked as current at any time';
COMMENT ON COLUMN academic_years.year_levels_advanced IS 'Prevents double advancement - set to TRUE after running year advancement';
COMMENT ON COLUMN academic_years.advanced_by IS 'Admin who executed the year level advancement';
COMMENT ON COLUMN academic_years.status IS 'Status: upcoming (future), current (active), completed (past)';

-- ============================================================================
-- ROLLBACK SCRIPT (if needed)
-- ============================================================================
-- DROP TRIGGER IF EXISTS trigger_ensure_single_current_year ON academic_years;
-- DROP TRIGGER IF EXISTS trigger_update_academic_years_timestamp ON academic_years;
-- DROP FUNCTION IF EXISTS ensure_single_current_academic_year();
-- DROP FUNCTION IF EXISTS update_academic_years_updated_at();
-- DROP TABLE IF EXISTS academic_years CASCADE;
