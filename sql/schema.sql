
-- ===========================================
-- EDUCAID PostgreSQL SCHEMA (Updated)
-- ===========================================

-- Municipalities (for CMS multi-tenancy)
CREATE TABLE municipalities (
    municipality_id SERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    color_theme TEXT,
    banner_image TEXT,
    logo_image TEXT
);

-- Admin users
CREATE TABLE admins (
    admin_id SERIAL PRIMARY KEY,
    municipality_id INT REFERENCES municipalities(municipality_id),
    first_name TEXT NOT NULL,
    middle_name TEXT,
    last_name TEXT NOT NULL,
    email TEXT UNIQUE NOT NULL,
    username TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL
);

-- Students
CREATE TABLE students (
    student_id SERIAL PRIMARY KEY,
    municipality_id INT REFERENCES municipalities(municipality_id),
    first_name TEXT,
    middle_name TEXT,
    last_name TEXT,
    email TEXT UNIQUE,
    mobile TEXT,
    password TEXT NOT NULL,
    sex TEXT CHECK (sex IN ('Male', 'Female')),
    status TEXT CHECK (status IN ('under_registration', 'applicant', 'active', 'disabled', 'given')) DEFAULT 'under_registration',
    payroll_no INT,
    qr_code TEXT,
    has_received BOOLEAN DEFAULT FALSE,
    application_date TIMESTAMP DEFAULT NOW(),
    bdate DATE,
    barangay_id INT REFERENCES barangays(barangay_id)
);

-- Applications (No GPA, used for tracking per semester)
CREATE TABLE applications (
    application_id SERIAL PRIMARY KEY,
    student_id INT REFERENCES students(student_id),
    semester TEXT,
    academic_year TEXT,
    is_valid BOOLEAN DEFAULT TRUE,
    remarks TEXT
);

-- Student-uploaded documents
CREATE TABLE documents (
    document_id SERIAL PRIMARY KEY,
    student_id INT REFERENCES students(student_id),
    type TEXT CHECK (type IN ('school_id', 'eaf', 'certificate_of_indigency', 'letter_to_mayor', 'id_picture')),
    file_path TEXT,
    upload_date TIMESTAMP DEFAULT NOW(),
    is_valid BOOLEAN DEFAULT FALSE,  -- Set to FALSE by default, to be validated by admin
    validation_notes TEXT
);

-- Enrollment Assessment Forms uploaded during registration
CREATE TABLE enrollment_forms (
    form_id SERIAL PRIMARY KEY,
    student_id INT REFERENCES students(student_id),
    file_path TEXT NOT NULL,
    original_filename TEXT NOT NULL,
    upload_date TIMESTAMP DEFAULT NOW()
);

-- Announcements (only one active per LGU)
-- Announcements (general broadcasts)
CREATE TABLE announcements (
    announcement_id SERIAL PRIMARY KEY,
    title TEXT NOT NULL,
    remarks TEXT,
    posted_at TIMESTAMP NOT NULL DEFAULT NOW(),
    is_active BOOLEAN NOT NULL DEFAULT FALSE
);

-- Aid distribution tracking
CREATE TABLE distributions (
    distribution_id SERIAL PRIMARY KEY,
    student_id INT REFERENCES students(student_id),
    date_given DATE,
    verified_by INT REFERENCES admins(admin_id),
    remarks TEXT
);

-- QR scan logs (optional analytics)
CREATE TABLE qr_logs (
    log_id SERIAL PRIMARY KEY,
    student_id INT REFERENCES students(student_id),
    scanned_at TIMESTAMP DEFAULT NOW(),
    scanned_by INT REFERENCES admins(admin_id)
);

CREATE TABLE barangays (
    barangay_id SERIAL PRIMARY KEY,
    municipality_id INT REFERENCES municipalities(municipality_id),
    name TEXT NOT NULL
);
-- Signup slots for students
-- This table allows municipalities to manage the number of students they can accommodate
CREATE TABLE signup_slots (
    slot_id SERIAL PRIMARY KEY,
    municipality_id INT REFERENCES municipalities(municipality_id),
    academic_year TEXT,
    semester TEXT,
    slot_count INT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE config (
    key TEXT PRIMARY KEY,
    value TEXT
);

INSERT INTO config (key, value) VALUES ('student_list_finalized', '0');

-- Notifications for students (rejections, announcements, schedule postings)
CREATE TABLE IF NOT EXISTS notifications (
    notification_id SERIAL PRIMARY KEY,
    student_id INT REFERENCES students(student_id),
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE schedules (
    schedule_id SERIAL PRIMARY KEY,
    student_id INT REFERENCES students(student_id),
    payroll_no INT NOT NULL,
    batch_no INT NOT NULL,
    distribution_date DATE NOT NULL,
    time_slot TEXT NOT NULL,
    location TEXT NOT NULL DEFAULT '',
    status TEXT CHECK (status IN ('scheduled', 'completed', 'missed')) DEFAULT 'scheduled',
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Admin notifications for system events (e.g. profile updates)
CREATE TABLE IF NOT EXISTS admin_notifications (
    admin_notification_id SERIAL PRIMARY KEY,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Create universities table
CREATE TABLE IF NOT EXISTS universities (
    university_id SERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    code TEXT UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Create year levels table
CREATE TABLE IF NOT EXISTS year_levels (
    year_level_id SERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    code TEXT UNIQUE NOT NULL,
    sort_order INT NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Add university and year level columns to students table
ALTER TABLE students 
ADD COLUMN IF NOT EXISTS university_id INT REFERENCES universities(university_id),
ADD COLUMN IF NOT EXISTS year_level_id INT REFERENCES year_levels(year_level_id);

-- Insert year levels
INSERT INTO year_levels (name, code, sort_order) VALUES
('1st Year', '1ST', 1),
('2nd Year', '2ND', 2),
('3rd Year', '3RD', 3),
('4th Year', '4TH', 4),
('5th Year', '5TH', 5)
ON CONFLICT (code) DO NOTHING;

-- Add unique student identifier column to students table
ALTER TABLE students 
ADD COLUMN IF NOT EXISTS unique_student_id TEXT UNIQUE;

-- Create index for faster lookups
CREATE INDEX IF NOT EXISTS idx_students_unique_id ON students(unique_student_id);

CREATE TABLE qr_codes (
    qr_id SERIAL PRIMARY KEY,
    payroll_number INT NOT NULL,
    unique_id TEXT UNIQUE NOT NULL,
    student_unique_id TEXT REFERENCES students(unique_student_id),
    status TEXT CHECK (status IN ('Pending', 'Done')) DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT NOW(),
    -- Optionally, you can add admin_id if you want to track who generated it
    admin_id INT REFERENCES admins(admin_id),
    -- Optionally, you can add a name field if you want to store a name
    name TEXT
);
CREATE TABLE qr_codes (
    qr_id SERIAL PRIMARY KEY,
    payroll_number INT NOT NULL,
    student_unique_id TEXT REFERENCES students(unique_student_id),
    status TEXT CHECK (status IN ('Pending', 'Done')) DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT NOW()
    -- Optionally, you can add admin_id if you want to track who generated it
);

ALTER TABLE qr_codes ADD COLUMN unique_id TEXT;