-- Slot Threshold Notifications Tracking Table
-- Prevents duplicate notifications when slot thresholds are reached

CREATE TABLE IF NOT EXISTS slot_threshold_notifications (
    slot_id INTEGER NOT NULL,
    municipality_id INTEGER NOT NULL,
    last_threshold VARCHAR(20) NOT NULL, -- 'notice_80', 'warning_90', 'urgent_95', 'critical_99'
    last_notified_at TIMESTAMP NOT NULL DEFAULT NOW(),
    students_notified INTEGER DEFAULT 0,
    PRIMARY KEY (slot_id, municipality_id),
    FOREIGN KEY (slot_id) REFERENCES signup_slots(slot_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_slot_threshold_notifications_slot 
ON slot_threshold_notifications(slot_id);

CREATE INDEX IF NOT EXISTS idx_slot_threshold_notifications_municipality 
ON slot_threshold_notifications(municipality_id);

COMMENT ON TABLE slot_threshold_notifications IS 
'Tracks when threshold-based slot notifications were sent to avoid duplicate alerts';

COMMENT ON COLUMN slot_threshold_notifications.last_threshold IS 
'Last threshold level that triggered notification: notice_80, warning_90, urgent_95, critical_99';

COMMENT ON COLUMN slot_threshold_notifications.students_notified IS 
'Number of students who were notified at this threshold';
