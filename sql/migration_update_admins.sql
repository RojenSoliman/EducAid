-- Migration: Update admins table for role-based access control
-- Run this script to add the new columns to your existing admins table

-- Add role column with default 'super_admin' for existing admins
ALTER TABLE admins 
ADD COLUMN IF NOT EXISTS role TEXT CHECK (role IN ('super_admin', 'sub_admin')) DEFAULT 'super_admin';

-- Add is_active column (all existing admins will be active by default)
ALTER TABLE admins 
ADD COLUMN IF NOT EXISTS is_active BOOLEAN DEFAULT TRUE;

-- Add created_at column (will be NULL for existing records)
ALTER TABLE admins 
ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT NOW();

-- Add last_login column (will be NULL for existing records)
ALTER TABLE admins 
ADD COLUMN IF NOT EXISTS last_login TIMESTAMP;

-- Update existing admins to have super_admin role (if not already set)
UPDATE admins SET role = 'super_admin' WHERE role IS NULL;

-- Update existing admins to be active (if not already set)
UPDATE admins SET is_active = TRUE WHERE is_active IS NULL;

-- Update created_at for existing records to current timestamp (optional)
UPDATE admins SET created_at = NOW() WHERE created_at IS NULL;

-- Add admin notification about the system upgrade
INSERT INTO admin_notifications (message) 
VALUES ('Admin role-based access control system has been implemented');

-- Show current admins table structure
SELECT column_name, data_type, is_nullable, column_default 
FROM information_schema.columns 
WHERE table_name = 'admins' 
ORDER BY ordinal_position;

-- Show all admins with their new roles
SELECT admin_id, username, first_name, last_name, email, role, is_active, created_at 
FROM admins 
ORDER BY admin_id;
