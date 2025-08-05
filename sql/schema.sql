
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
    type TEXT CHECK (type IN ('school_id', 'eaf', 'certificate_of_indigency', 'letter_to_mayor', 'id_picture')),
    file_path TEXT,
    upload_date TIMESTAMP DEFAULT NOW(),
    is_valid BOOLEAN DEFAULT FALSE,  -- Set to FALSE by default, to be validated by admin
    validation_notes TEXT
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