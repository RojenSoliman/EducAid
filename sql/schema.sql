
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
    username TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL,
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
    status TEXT CHECK (status IN ('applicant', 'active', 'disabled', 'given')) DEFAULT 'applicant',
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
    type TEXT CHECK (type IN ('school_id', 'eaf', 'certificate_of_indigency', 'letter_to_mayor')),
    file_path TEXT,
    upload_date TIMESTAMP DEFAULT NOW(),
    is_valid BOOLEAN DEFAULT TRUE,
    validation_notes TEXT
);

-- Announcements (only one active per LGU)
CREATE TABLE announcements (
    announcement_id SERIAL PRIMARY KEY,
    municipality_id INT REFERENCES municipalities(municipality_id),
    title TEXT,
    location TEXT,
    announcement_date DATE,
    time TIME,
    reminder TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE
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