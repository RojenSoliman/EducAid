-- Student Notification Preferences (email-only) schema
-- Created: 2025-10-31 15:25

-- Create preferences table to store per-student email notification settings
CREATE TABLE IF NOT EXISTS student_notification_preferences (
    student_id TEXT PRIMARY KEY REFERENCES students(student_id) ON DELETE CASCADE,
    email_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    email_frequency VARCHAR(16) NOT NULL DEFAULT 'immediate', -- immediate | daily
    -- Per-type email toggles
    email_announcement BOOLEAN NOT NULL DEFAULT TRUE,
    email_document     BOOLEAN NOT NULL DEFAULT TRUE,
    email_schedule     BOOLEAN NOT NULL DEFAULT TRUE,
    email_warning      BOOLEAN NOT NULL DEFAULT TRUE,
    email_error        BOOLEAN NOT NULL DEFAULT TRUE,
    email_success      BOOLEAN NOT NULL DEFAULT TRUE,
    email_system       BOOLEAN NOT NULL DEFAULT TRUE,
    email_info         BOOLEAN NOT NULL DEFAULT TRUE,
    -- Digest tracking
    last_digest_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

COMMENT ON TABLE student_notification_preferences IS 'Per-student preferences for email notification delivery and digest timing.';
COMMENT ON COLUMN student_notification_preferences.email_enabled IS 'Master switch for emailing notifications to this student.';
COMMENT ON COLUMN student_notification_preferences.email_frequency IS 'Email delivery mode: immediate (send instantly) or daily (one daily digest).';
COMMENT ON COLUMN student_notification_preferences.last_digest_at IS 'Timestamp of the last successfully sent digest; prevents re-sending historical notifications.';

-- Ensure valid frequency values
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint 
        WHERE conname = 'chk_student_notif_pref_email_frequency'
    ) THEN
        ALTER TABLE student_notification_preferences
        ADD CONSTRAINT chk_student_notif_pref_email_frequency
        CHECK (email_frequency IN ('immediate','daily'));
    END IF;
END $$;

-- Trigger to keep updated_at current
CREATE OR REPLACE FUNCTION trg_student_notif_prefs_updated_at()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at := CURRENT_TIMESTAMP;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger 
        WHERE tgname = 'set_student_notif_prefs_updated_at'
    ) THEN
        CREATE TRIGGER set_student_notif_prefs_updated_at
        BEFORE UPDATE ON student_notification_preferences
        FOR EACH ROW
        EXECUTE FUNCTION trg_student_notif_prefs_updated_at();
    END IF;
END $$;
