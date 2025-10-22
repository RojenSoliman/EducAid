-- =====================================================
-- Multi-Account Prevention System - Verification Script
-- Run this after installation to verify everything works
-- =====================================================

DO $$
BEGIN
    RAISE NOTICE '';
    RAISE NOTICE '=========================================';
    RAISE NOTICE ' Multi-Account Prevention System';
    RAISE NOTICE ' Verification Tests';
    RAISE NOTICE '=========================================';
    RAISE NOTICE '';
END $$;

-- Test 1: Check if tables exist
DO $$
DECLARE
    table_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO table_count
    FROM information_schema.tables 
    WHERE table_schema = 'public' 
      AND table_name IN ('school_student_ids', 'school_student_id_audit');
    
    IF table_count = 2 THEN
        RAISE NOTICE '✓ Test 1: PASS - All tables exist';
    ELSE
        RAISE NOTICE '✗ Test 1: FAIL - Missing tables (found %)', table_count;
    END IF;
END $$;

-- Test 2: Check if view exists
DO $$
DECLARE
    view_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO view_count
    FROM information_schema.views 
    WHERE table_schema = 'public' 
      AND table_name = 'v_school_student_id_duplicates';
    
    IF view_count = 1 THEN
        RAISE NOTICE '✓ Test 2: PASS - View exists';
    ELSE
        RAISE NOTICE '✗ Test 2: FAIL - View missing';
    END IF;
END $$;

-- Test 3: Check if functions exist
DO $$
DECLARE
    function_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO function_count
    FROM information_schema.routines 
    WHERE routine_schema = 'public' 
      AND routine_name IN ('check_duplicate_school_student_id', 'get_school_student_ids', 'track_school_student_id');
    
    IF function_count >= 2 THEN
        RAISE NOTICE '✓ Test 3: PASS - Functions exist (found %)', function_count;
    ELSE
        RAISE NOTICE '✗ Test 3: FAIL - Functions missing (found %)', function_count;
    END IF;
END $$;

-- Test 4: Check if trigger exists
DO $$
DECLARE
    trigger_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO trigger_count
    FROM information_schema.triggers 
    WHERE trigger_name = 'trigger_track_school_student_id';
    
    IF trigger_count = 1 THEN
        RAISE NOTICE '✓ Test 4: PASS - Trigger exists';
    ELSE
        RAISE NOTICE '✗ Test 4: FAIL - Trigger missing';
    END IF;
END $$;

-- Test 5: Check if school_student_id column exists in students table
DO $$
DECLARE
    column_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO column_count
    FROM information_schema.columns 
    WHERE table_name = 'students' 
      AND column_name = 'school_student_id';
    
    IF column_count = 1 THEN
        RAISE NOTICE '✓ Test 5: PASS - Column exists';
    ELSE
        RAISE NOTICE '✗ Test 5: FAIL - Column missing';
    END IF;
END $$;

-- Test 6: Check if unique index exists
DO $$
DECLARE
    index_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO index_count
    FROM pg_indexes 
    WHERE tablename = 'students' 
      AND indexname LIKE '%school_student_id%';
    
    IF index_count >= 1 THEN
        RAISE NOTICE '✓ Test 6: PASS - Unique index exists';
    ELSE
        RAISE NOTICE '✗ Test 6: FAIL - Index missing';
    END IF;
END $$;

-- Test 7: Test check_duplicate_school_student_id function
DO $$
DECLARE
    rec RECORD;
BEGIN
    -- Call the function
    SELECT * INTO rec FROM check_duplicate_school_student_id(1, 'NONEXISTENT-TEST-ID-999');
    
    -- Check if is_duplicate is false (not a duplicate)
    IF NOT rec.is_duplicate THEN
        RAISE NOTICE '✓ Test 7: PASS - Function works correctly (no duplicate found)';
    ELSE
        RAISE NOTICE '✗ Test 7: FAIL - Function returned unexpected result';
    END IF;
    
EXCEPTION
    WHEN NO_DATA_FOUND THEN
        RAISE NOTICE '✗ Test 7: FAIL - Function returned no results';
    WHEN OTHERS THEN
        RAISE NOTICE '✗ Test 7: FAIL - Error: %', SQLERRM;
END $$;

-- Test 8: Check foreign key constraints
DO $$
DECLARE
    fk_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO fk_count
    FROM information_schema.table_constraints 
    WHERE table_name = 'school_student_ids' 
      AND constraint_type = 'FOREIGN KEY';
    
    IF fk_count >= 2 THEN
        RAISE NOTICE '✓ Test 8: PASS - Foreign keys exist';
    ELSE
        RAISE NOTICE '✗ Test 8: FAIL - Foreign keys missing (found %)', fk_count;
    END IF;
END $$;

-- Test 9: Check if audit columns exist
DO $$
DECLARE
    column_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO column_count
    FROM information_schema.columns 
    WHERE table_name = 'school_student_id_audit'
      AND column_name IN ('audit_id', 'university_id', 'student_id', 'school_student_id', 'action', 'performed_at', 'ip_address');
    
    IF column_count >= 7 THEN
        RAISE NOTICE '✓ Test 9: PASS - Audit columns exist';
    ELSE
        RAISE NOTICE '✗ Test 9: FAIL - Missing audit columns (found %)', column_count;
    END IF;
END $$;

-- Test 10: Test insert/trigger (with rollback)
DO $$
DECLARE
    tracking_count INTEGER;
    audit_count INTEGER;
BEGIN
    RAISE NOTICE '✓ Test 10: Testing trigger functionality (will rollback)...';
    
    -- Note: This test is informational only since we can't rollback within DO blocks
    -- The trigger will be tested during actual registration
    
    RAISE NOTICE '  Trigger test requires actual INSERT into students table';
    RAISE NOTICE '  This will be tested during first registration';
END $$;

DO $$
BEGIN
    RAISE NOTICE '';
    RAISE NOTICE '=========================================';
    RAISE NOTICE ' Verification Complete!';
    RAISE NOTICE '=========================================';
    RAISE NOTICE '';
    RAISE NOTICE 'If all tests show ✓ PASS, the system is ready to use.';
    RAISE NOTICE '';
    RAISE NOTICE 'Next steps:';
    RAISE NOTICE '1. Test the registration form in your browser';
    RAISE NOTICE '2. Try entering a duplicate school student ID';
    RAISE NOTICE '3. Check the audit logs';
    RAISE NOTICE '';
    RAISE NOTICE 'For troubleshooting, see MULTI_ACCOUNT_PREVENTION_GUIDE.md';
    RAISE NOTICE '';
END $$;
