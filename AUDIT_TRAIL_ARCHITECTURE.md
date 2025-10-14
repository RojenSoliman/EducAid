# Audit Trail System Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           EDUCAID AUDIT TRAIL SYSTEM                     │
└─────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────┐
│ PRESENTATION LAYER (Super Admin Only)                                   │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ┌────────────────────────────────────────────────────────────────┐   │
│  │  modules/admin/audit_logs.php                                   │   │
│  │                                                                  │   │
│  │  ┌─────────────────────────────────────────────────────────┐  │   │
│  │  │  Statistics Dashboard                                    │  │   │
│  │  │  • Total Events (24h)  • Admin Actions                  │  │   │
│  │  │  • Student Actions     • Failed Events                  │  │   │
│  │  └─────────────────────────────────────────────────────────┘  │   │
│  │                                                                  │   │
│  │  ┌─────────────────────────────────────────────────────────┐  │   │
│  │  │  Advanced Filters                                        │  │   │
│  │  │  • Search   • User Type   • Category   • Status         │  │   │
│  │  │  • Username • Date Range  • IP Address                  │  │   │
│  │  └─────────────────────────────────────────────────────────┘  │   │
│  │                                                                  │   │
│  │  ┌─────────────────────────────────────────────────────────┐  │   │
│  │  │  Audit Log Table (Paginated)                            │  │   │
│  │  │  • ID  • Date/Time  • User  • Event  • Description      │  │   │
│  │  │  • Status  • IP  • [View Details] Button                │  │   │
│  │  └─────────────────────────────────────────────────────────┘  │   │
│  │                                                                  │   │
│  │  ┌─────────────────────────────────────────────────────────┐  │   │
│  │  │  Details Modal                                           │  │   │
│  │  │  • Full event data  • JSON formatting                   │  │   │
│  │  │  • Old/new values   • Metadata                          │  │   │
│  │  └─────────────────────────────────────────────────────────┘  │   │
│  │                                                                  │   │
│  │  [Export to CSV]                                                │   │
│  └────────────────────────────────────────────────────────────────┘   │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
                                    ↕
┌─────────────────────────────────────────────────────────────────────────┐
│ SERVICE LAYER                                                            │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ┌────────────────────────────────────────────────────────────────┐   │
│  │  services/AuditLogger.php                                       │   │
│  │                                                                  │   │
│  │  Public Methods:                                                │   │
│  │  • logLogin()              • logLogout()                        │   │
│  │  • logLoginFailure()       • logSlotOpened()                    │   │
│  │  • logSlotClosed()         • logApplicantRegistered()           │   │
│  │  • logApplicantApproved()  • logApplicantRejected()             │   │
│  │  • logApplicantVerified()  • logPayrollGenerated()              │   │
│  │  • logPayrollNumberChanged() • logScheduleCreated()             │   │
│  │  • logSchedulePublished()  • logScheduleCleared()               │   │
│  │  • logEmailChanged()       • logPasswordChanged()               │   │
│  │  • logDistributionStarted() • logDistributionActivated()        │   │
│  │  • logDistributionCompleted() • logEvent() [Generic]            │   │
│  │                                                                  │   │
│  │  Helper Methods:                                                │   │
│  │  • getClientIP()    (Proxy-aware)                              │   │
│  │  • getUserAgent()                                              │   │
│  │  • getRecentLogs()  (With filters)                             │   │
│  └────────────────────────────────────────────────────────────────┘   │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
                                    ↕
┌─────────────────────────────────────────────────────────────────────────┐
│ INTEGRATION POINTS (Auto-logging enabled)                               │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  unified_login.php               → Login events (admin & student)       │
│  modules/admin/logout.php        → Admin logout                         │
│  modules/student/logout.php      → Student logout                       │
│  modules/admin/manage_slots.php  → Slot open/close                      │
│  modules/admin/manage_applicants.php → Applicant actions                │
│  modules/admin/manage_schedules.php  → Schedule operations              │
│  (More files can be added...)                                           │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
                                    ↕
┌─────────────────────────────────────────────────────────────────────────┐
│ DATA LAYER (PostgreSQL)                                                 │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ┌────────────────────────────────────────────────────────────────┐   │
│  │  audit_logs TABLE                                               │   │
│  │                                                                  │   │
│  │  • audit_id (PRIMARY KEY)    • user_id                          │   │
│  │  • user_type                 • username                         │   │
│  │  • event_type                • event_category                   │   │
│  │  • action_description        • status                           │   │
│  │  • ip_address                • user_agent                       │   │
│  │  • request_method            • request_uri                      │   │
│  │  • affected_table            • affected_record_id               │   │
│  │  • old_values (JSONB)        • new_values (JSONB)               │   │
│  │  • metadata (JSONB)          • created_at                       │   │
│  │  • session_id                                                   │   │
│  │                                                                  │   │
│  │  Indexes (8):                                                   │   │
│  │  • idx_audit_user           • idx_audit_event_type             │   │
│  │  • idx_audit_category       • idx_audit_created_at             │   │
│  │  • idx_audit_affected       • idx_audit_status                 │   │
│  │  • idx_audit_ip             • idx_audit_user_date              │   │
│  │  • idx_audit_category_date                                     │   │
│  └────────────────────────────────────────────────────────────────┘   │
│                                                                          │
│  ┌────────────────────────────────────────────────────────────────┐   │
│  │  Helper Views                                                   │   │
│  │                                                                  │   │
│  │  • v_recent_admin_activity   (Last 100 admin actions)           │   │
│  │  • v_recent_student_activity (Last 100 student actions)         │   │
│  │  • v_failed_logins           (All failed login attempts)        │   │
│  └────────────────────────────────────────────────────────────────┘   │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘


EVENT FLOW EXAMPLE:
═══════════════════

1. Admin clicks "Approve Applicant"
        ↓
2. manage_applicants.php processes approval
        ↓
3. Updates student status in database
        ↓
4. Calls: $auditLogger->logApplicantApproved(...)
        ↓
5. AuditLogger captures:
   • Admin ID, username
   • Student data (old status: applicant, new status: active)
   • IP address, user agent, session
   • Timestamp
        ↓
6. Inserts record into audit_logs table
        ↓
7. Super admin views in Audit Trail page
        ↓
8. Can filter, search, view details, export


EVENT CATEGORIES & TYPES:
════════════════════════

┌────────────────────────────────────────────────────────────┐
│ AUTHENTICATION        │ SLOT MANAGEMENT                    │
│ • admin_login         │ • slot_opened                      │
│ • student_login       │ • slot_closed                      │
│ • admin_logout        │ • slot_updated                     │
│ • student_logout      │ • slot_deleted                     │
│ • login_failed        │                                    │
├────────────────────────────────────────────────────────────┤
│ APPLICANT MANAGEMENT  │ PAYROLL                            │
│ • applicant_registered│ • payroll_generated                │
│ • applicant_approved  │ • payroll_number_changed           │
│ • applicant_rejected  │ • qr_code_generated                │
│ • applicant_verified  │                                    │
│ • applicant_migrated  │                                    │
├────────────────────────────────────────────────────────────┤
│ SCHEDULE              │ PROFILE                            │
│ • schedule_created    │ • email_changed                    │
│ • schedule_published  │ • password_changed                 │
│ • schedule_unpublished│ • profile_updated                  │
│ • schedule_cleared    │                                    │
├────────────────────────────────────────────────────────────┤
│ DISTRIBUTION          │ SYSTEM                             │
│ • distribution_started│ • config_changed                   │
│ • distribution_active │ • bulk_operation                   │
│ • distribution_complete│ • data_export                     │
│ • documents_deadline  │ • system_maintenance               │
└────────────────────────────────────────────────────────────┘


SECURITY MODEL:
═══════════════

┌─────────────────────────────────────┐
│ ROLE: super_admin                   │
│ Access: ✅ FULL                     │
│ Can:                                │
│ • View all audit logs               │
│ • Filter and search                 │
│ • Export to CSV                     │
│ • View detailed event data          │
└─────────────────────────────────────┘
                ↓
┌─────────────────────────────────────┐
│ ROLE: sub_admin / admin             │
│ Access: ❌ DENIED                   │
│ Cannot:                             │
│ • See Audit Trail menu item         │
│ • Access audit_logs.php             │
│ • View any audit data               │
└─────────────────────────────────────┘


PERFORMANCE CHARACTERISTICS:
════════════════════════════

Records    Query Time    Notes
─────────  ────────────  ──────────────────────────────
1K         < 10ms        Instant
10K        < 50ms        Very Fast
100K       < 200ms       Fast (with indexes)
1M         < 1s          Good (with proper filters)
10M+       Varies        Consider archiving

Optimization strategies:
• Use date range filters
• Archive old records (>1 year)
• Regular VACUUM ANALYZE
• Monitor index usage
• Paginate results (50/page)
```

---
**Architecture Version:** 1.0  
**Last Updated:** October 15, 2025
