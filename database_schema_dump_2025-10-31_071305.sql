--
-- PostgreSQL database dump
--

-- Dumped from database version 17.5
-- Dumped by pg_dump version 17.5

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: grading; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA grading;


--
-- Name: grading_is_passing(text, text); Type: FUNCTION; Schema: grading; Owner: -
--

CREATE FUNCTION grading.grading_is_passing(p_university_key text, p_raw_grade text) RETURNS boolean
    LANGUAGE plpgsql STABLE
    AS $$
DECLARE
    policy_record RECORD;
    grade_numeric NUMERIC;
    passing_numeric NUMERIC;
    letter_index INT;
    passing_index INT;
BEGIN
    -- Get the active grading policy for the university
    SELECT scale_type, higher_is_better, highest_value, passing_value, letter_order
    INTO policy_record
    FROM grading.university_passing_policy
    WHERE university_key = p_university_key AND is_active = TRUE;
    
    -- If no policy found, return false (strict default)
    IF NOT FOUND THEN
        RETURN FALSE;
    END IF;
    
    -- Handle different scale types
    CASE policy_record.scale_type
        WHEN 'NUMERIC_1_TO_5', 'NUMERIC_0_TO_4' THEN
            -- Try to convert grades to numeric
            BEGIN
                grade_numeric := p_raw_grade::NUMERIC;
                passing_numeric := policy_record.passing_value::NUMERIC;
                
                -- Apply direction logic
                IF policy_record.higher_is_better THEN
                    -- For 0-4 scale, grade must be >= passing value
                    RETURN grade_numeric >= passing_numeric;
                ELSE
                    -- For 1-5 scale, grade must be <= passing value
                    RETURN grade_numeric <= passing_numeric;
                END IF;
            EXCEPTION WHEN OTHERS THEN
                -- Non-numeric grade fails
                RETURN FALSE;
            END;
            
        WHEN 'PERCENT' THEN
            -- Handle percentage grades
            BEGIN
                grade_numeric := p_raw_grade::NUMERIC;
                passing_numeric := policy_record.passing_value::NUMERIC;
                
                -- For percentages, higher is always better
                RETURN grade_numeric >= passing_numeric;
            EXCEPTION WHEN OTHERS THEN
                RETURN FALSE;
            END;
            
        WHEN 'LETTER' THEN
            -- Handle letter grades using letter_order array
            IF policy_record.letter_order IS NULL THEN
                RETURN FALSE;
            END IF;
            
            -- Find index of grade and passing grade in letter_order array
            SELECT idx INTO letter_index FROM unnest(policy_record.letter_order) WITH ORDINALITY AS t(letter, idx) WHERE letter = p_raw_grade;
            SELECT idx INTO passing_index FROM unnest(policy_record.letter_order) WITH ORDINALITY AS t(letter, idx) WHERE letter = policy_record.passing_value;
            
            -- If either grade not found in order, fail
            IF letter_index IS NULL OR passing_index IS NULL THEN
                RETURN FALSE;
            END IF;
            
            -- Pass if grade index <= passing index (earlier in array means better)
            RETURN letter_index <= passing_index;
            
        ELSE
            -- Unknown scale type
            RETURN FALSE;
    END CASE;
END;
$$;


--
-- Name: update_updated_at_column(); Type: FUNCTION; Schema: grading; Owner: -
--

CREATE FUNCTION grading.update_updated_at_column() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$;


--
-- Name: archive_graduated_students(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.archive_graduated_students() RETURNS TABLE(archived_count integer, student_ids text[])
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_archived_count INTEGER;
    v_student_ids TEXT[];
BEGIN
    -- Archive eligible students
    WITH archived AS (
        UPDATE students
        SET 
            is_archived = TRUE,
            archived_at = CURRENT_TIMESTAMP,
            archived_by = NULL, -- NULL indicates automatic archiving
            archive_reason = CASE 
                WHEN EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER > expected_graduation_year THEN 'Automatically archived: Graduated (past expected graduation year)'
                WHEN EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER = expected_graduation_year AND EXTRACT(MONTH FROM CURRENT_DATE) >= 6 THEN 'Automatically archived: Graduated (current graduation year)'
                WHEN last_login IS NOT NULL AND last_login < (CURRENT_DATE - INTERVAL '2 years') THEN 'Automatically archived: Inactive account (no login for 2+ years)'
                WHEN last_login IS NULL AND application_date < (CURRENT_DATE - INTERVAL '2 years') THEN 'Automatically archived: Inactive account (never logged in)'
                ELSE 'Automatically archived'
            END,
            status = 'archived'
        WHERE student_id IN (
            SELECT student_id FROM v_students_eligible_for_archiving
        )
        RETURNING student_id
    )
    SELECT 
        COUNT(*)::INTEGER,
        ARRAY_AGG(student_id)
    INTO v_archived_count, v_student_ids
    FROM archived;
    
    RETURN QUERY SELECT v_archived_count, v_student_ids;
END;
$$;


--
-- Name: FUNCTION archive_graduated_students(); Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON FUNCTION public.archive_graduated_students() IS 'Automatically archives students who have graduated or been inactive for 2+ years. Run annually or as needed.';


--
-- Name: archive_student(text, integer, text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.archive_student(p_student_id text, p_admin_id integer, p_archive_reason text) RETURNS boolean
    LANGUAGE plpgsql
    AS $$
BEGIN
    -- Update student record
    UPDATE students
    SET 
        is_archived = TRUE,
        archived_at = NOW(),
        archived_by = p_admin_id,
        archive_reason = p_archive_reason,
        status = 'archived'
    WHERE student_id = p_student_id
    AND is_archived = FALSE; -- Only archive if not already archived
    
    -- Return true if a row was updated
    RETURN FOUND;
END;
$$;


--
-- Name: FUNCTION archive_student(p_student_id text, p_admin_id integer, p_archive_reason text); Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON FUNCTION public.archive_student(p_student_id text, p_admin_id integer, p_archive_reason text) IS 'Archives a student by setting is_archived flag and related metadata';


--
-- Name: archive_student_documents(character varying, integer, character varying, character varying); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.archive_student_documents(p_student_id character varying, p_distribution_snapshot_id integer, p_academic_year character varying, p_semester character varying) RETURNS void
    LANGUAGE plpgsql
    AS $$
BEGIN
    -- Archive documents table entries
    INSERT INTO document_archives (
        student_id, original_document_id, document_type, file_path, 
        original_upload_date, distribution_snapshot_id, academic_year, semester
    )
    SELECT 
        d.student_id, d.document_id, d.type, d.file_path,
        d.upload_date, p_distribution_snapshot_id, p_academic_year, p_semester
    FROM documents d
    WHERE d.student_id = p_student_id;
    
    -- Archive grade uploads
    INSERT INTO document_archives (
        student_id, original_document_id, document_type, file_path,
        original_upload_date, distribution_snapshot_id, academic_year, semester
    )
    SELECT 
        g.student_id, g.upload_id, 'grades', g.file_path,
        g.upload_date, p_distribution_snapshot_id, p_academic_year, p_semester
    FROM grade_uploads g
    WHERE g.student_id = p_student_id;
END;
$$;


--
-- Name: archive_student_manual(text, integer, text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.archive_student_manual(p_student_id text, p_admin_id integer, p_reason text) RETURNS boolean
    LANGUAGE plpgsql
    AS $$
BEGIN
    -- Archive the student
    UPDATE students 
    SET 
        is_archived = TRUE,
        archived_at = NOW(),
        archived_by = p_admin_id,
        archive_reason = p_reason
    WHERE student_id = p_student_id
    AND is_archived = FALSE; -- Only archive if not already archived
    
    -- Return TRUE if row was updated, FALSE otherwise
    IF FOUND THEN
        RETURN TRUE;
    ELSE
        RETURN FALSE;
    END IF;
END;
$$;


--
-- Name: archive_students_automatic(integer, integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.archive_students_automatic(p_graduation_year integer, p_admin_id integer) RETURNS TABLE(archived_count integer, student_ids text[])
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_student_ids TEXT[];
    v_count INTEGER;
BEGIN
    -- Collect student IDs that will be archived
    SELECT ARRAY_AGG(student_id)
    INTO v_student_ids
    FROM students
    WHERE expected_graduation_year = p_graduation_year
    AND is_archived = FALSE
    AND status IN ('active', 'given'); -- Only archive active/given students
    
    -- Archive matching students
    UPDATE students
    SET 
        is_archived = TRUE,
        archived_at = NOW(),
        archived_by = p_admin_id,
        archive_reason = 'Automatic archiving: Graduated in ' || p_graduation_year
    WHERE expected_graduation_year = p_graduation_year
    AND is_archived = FALSE
    AND status IN ('active', 'given');
    
    GET DIAGNOSTICS v_count = ROW_COUNT;
    
    -- Return results
    archived_count := v_count;
    student_ids := v_student_ids;
    RETURN NEXT;
END;
$$;


--
-- Name: archive_students_automatic(integer, text, integer, integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.archive_students_automatic(p_admin_id integer, p_reason text, p_graduation_year integer DEFAULT NULL::integer, p_inactive_days integer DEFAULT 365) RETURNS TABLE(student_id integer, full_name text, email text, year_level text, last_login timestamp without time zone)
    LANGUAGE plpgsql
    AS $$
BEGIN
    -- Archive students based on criteria and return affected rows
    RETURN QUERY
    WITH archived_students AS (
        UPDATE students s
        SET 
            is_archived = TRUE,
            archived_at = NOW(),
            archived_by = p_admin_id,
            archive_reason = p_reason
        WHERE is_archived = FALSE
        AND (
            -- Archive by graduation year
            (p_graduation_year IS NOT NULL AND s.expected_graduation_year <= p_graduation_year)
            OR
            -- Archive by inactivity (if graduation year not specified)
            (p_graduation_year IS NULL AND 
             s.last_login < NOW() - (p_inactive_days || ' days')::INTERVAL)
        )
        RETURNING s.student_id, s.first_name, s.middle_name, s.last_name, s.email, s.year_level_id, s.last_login
    )
    SELECT 
        a.student_id,
        CONCAT(a.first_name, ' ', COALESCE(a.middle_name, ''), ' ', a.last_name) as full_name,
        a.email,
        COALESCE(yl.name, 'N/A') as year_level,
        a.last_login
    FROM archived_students a
    LEFT JOIN year_levels yl ON yl.year_level_id = a.year_level_id;
END;
$$;


--
-- Name: FUNCTION archive_students_automatic(p_admin_id integer, p_reason text, p_graduation_year integer, p_inactive_days integer); Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON FUNCTION public.archive_students_automatic(p_admin_id integer, p_reason text, p_graduation_year integer, p_inactive_days integer) IS 'Automatically archive students by graduation year or inactivity. Returns details of archived students.';


--
-- Name: calculate_confidence_score(character varying); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.calculate_confidence_score(student_id_param character varying) RETURNS numeric
    LANGUAGE plpgsql
    AS $$
DECLARE
    score DECIMAL(5,2) := 0.00;
    doc_count INT := 0;
    avg_ocr_confidence DECIMAL(5,2) := 0.00;
    avg_verification_score DECIMAL(5,2) := 0.00;
    verified_docs INT := 0;
    total_uploaded_docs INT := 0;
    temp_score DECIMAL(5,2);
BEGIN
    -- Personal Information (25 points)
    SELECT 
        CASE WHEN first_name IS NOT NULL AND first_name != '' 
             AND last_name IS NOT NULL AND last_name != ''
             AND email IS NOT NULL AND email != ''
             AND mobile IS NOT NULL AND mobile != ''
             AND bdate IS NOT NULL
             AND sex IS NOT NULL
             AND barangay_id IS NOT NULL
             AND university_id IS NOT NULL
             AND year_level_id IS NOT NULL
        THEN 25.00 
        ELSE 
            (CASE WHEN first_name IS NOT NULL AND first_name != '' THEN 3.00 ELSE 0.00 END +
             CASE WHEN last_name IS NOT NULL AND last_name != '' THEN 3.00 ELSE 0.00 END +
             CASE WHEN email IS NOT NULL AND email != '' THEN 3.00 ELSE 0.00 END +
             CASE WHEN mobile IS NOT NULL AND mobile != '' THEN 3.00 ELSE 0.00 END +
             CASE WHEN bdate IS NOT NULL THEN 3.00 ELSE 0.00 END +
             CASE WHEN sex IS NOT NULL THEN 2.00 ELSE 0.00 END +
             CASE WHEN barangay_id IS NOT NULL THEN 2.00 ELSE 0.00 END +
             CASE WHEN university_id IS NOT NULL THEN 3.00 ELSE 0.00 END +
             CASE WHEN year_level_id IS NOT NULL THEN 3.00 ELSE 0.00 END)
        END
    INTO temp_score
    FROM students 
    WHERE student_id = student_id_param;
    
    score := score + COALESCE(temp_score, 0.00);
    
    -- Document Upload (35 points) - FIXED: Uses document_type_code
    SELECT COUNT(DISTINCT document_type_code) INTO doc_count
    FROM documents d
    WHERE d.student_id = student_id_param 
    AND d.document_type_code IN ('00', '01', '02', '03', '04')
    AND d.status != 'rejected';
    
    score := score + (doc_count * 7.00);
    
    -- OCR Quality (20 points) - FIXED: No d.type reference
    SELECT 
        COALESCE(AVG(ocr_confidence), 0.00),
        COUNT(*) 
    INTO avg_ocr_confidence, total_uploaded_docs
    FROM documents d
    WHERE d.student_id = student_id_param 
    AND d.ocr_confidence IS NOT NULL
    AND d.status != 'rejected';
    
    IF total_uploaded_docs > 0 THEN
        score := score + (avg_ocr_confidence * 0.20);
    END IF;
    
    -- Verification Status (15 points) - FIXED: No d.type reference
    SELECT 
        COALESCE(AVG(verification_score), 0.00),
        COUNT(CASE WHEN verification_status = 'passed' THEN 1 END)
    INTO avg_verification_score, verified_docs
    FROM documents d
    WHERE d.student_id = student_id_param 
    AND d.verification_score IS NOT NULL
    AND d.status != 'rejected';
    
    IF total_uploaded_docs > 0 THEN
        score := score + (avg_verification_score * 0.10);
    END IF;
    
    score := score + LEAST(verified_docs * 1.00, 5.00);
    
    -- Email Verification (5 points)
    SELECT 
        CASE WHEN status IN ('applicant', 'active', 'on_hold') 
        THEN 5.00 ELSE 0.00 END
    INTO temp_score
    FROM students 
    WHERE student_id = student_id_param;
    
    score := score + COALESCE(temp_score, 0.00);
    
    score := GREATEST(0.00, LEAST(100.00, score));
    
    RETURN score;
END;
$$;


--
-- Name: check_duplicate_school_student_id(integer, character varying); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.check_duplicate_school_student_id(p_university_id integer, p_school_student_id character varying) RETURNS TABLE(is_duplicate boolean, system_student_id character varying, student_name text, student_email text, student_mobile text, student_status text, registered_at timestamp without time zone, university_name character varying, first_name character varying, last_name character varying)
    LANGUAGE plpgsql
    AS $$
BEGIN
    RETURN QUERY
    SELECT 
        TRUE as is_duplicate,
        s.student_id as system_student_id,
        s.first_name || ' ' || COALESCE(s.middle_name || ' ', '') || s.last_name as student_name,
        s.email,
        s.mobile,
        s.status,
        ssi.registered_at,
        ssi.university_name,
        ssi.first_name,
        ssi.last_name
    FROM school_student_ids ssi
    JOIN students s ON ssi.student_id = s.student_id
    WHERE ssi.university_id = p_university_id
      AND ssi.school_student_id = p_school_student_id
      AND ssi.status = 'active'
    LIMIT 1;
    
    -- If no duplicate found, return false
    IF NOT FOUND THEN
        RETURN QUERY SELECT FALSE, NULL::VARCHAR, NULL::TEXT, NULL::TEXT, NULL::TEXT, NULL::TEXT, NULL::TIMESTAMP, NULL::VARCHAR, NULL::VARCHAR, NULL::VARCHAR;
    END IF;
END;
$$;


--
-- Name: FUNCTION check_duplicate_school_student_id(p_university_id integer, p_school_student_id character varying); Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON FUNCTION public.check_duplicate_school_student_id(p_university_id integer, p_school_student_id character varying) IS 'Checks if a school student ID is already registered for a given university';


--
-- Name: generate_document_id(character varying, character varying, integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.generate_document_id(p_student_id character varying, p_document_type_code character varying, p_year integer DEFAULT NULL::integer) RETURNS character varying
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_year INTEGER;
    v_document_id VARCHAR(100);
BEGIN
    -- Use provided year or current year
    v_year := COALESCE(p_year, EXTRACT(YEAR FROM NOW()));
    
    -- Format: STUDENTID-DOCU-YEAR-TYPE
    -- Example: GENERALTRIAS-2025-3-DWXA3N-DOCU-2025-01
    v_document_id := p_student_id || '-DOCU-' || v_year::TEXT || '-' || p_document_type_code;
    
    RETURN v_document_id;
END;
$$;


--
-- Name: FUNCTION generate_document_id(p_student_id character varying, p_document_type_code character varying, p_year integer); Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON FUNCTION public.generate_document_id(p_student_id character varying, p_document_type_code character varying, p_year integer) IS 'Generates standardized document ID: STUDENTID-DOCU-YEAR-TYPE';


--
-- Name: get_active_distribution_snapshot(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.get_active_distribution_snapshot() RETURNS TABLE(snapshot_id integer, distribution_id text, academic_year text, semester text)
    LANGUAGE plpgsql
    AS $$
BEGIN
    RETURN QUERY
    SELECT 
        ds.snapshot_id,
        ds.distribution_id,
        ds.academic_year,
        ds.semester
    FROM distribution_snapshots ds
    WHERE ds.finalized_at IS NULL 
       OR ds.finalized_at >= CURRENT_DATE - INTERVAL '7 days'
    ORDER BY ds.finalized_at DESC NULLS FIRST
    LIMIT 1;
END;
$$;


--
-- Name: FUNCTION get_active_distribution_snapshot(); Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON FUNCTION public.get_active_distribution_snapshot() IS 'Returns the currently active distribution snapshot (unfinalzed or recently finalized)';


--
-- Name: get_archived_students(integer, integer, integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.get_archived_students(p_municipality_id integer DEFAULT NULL::integer, p_limit integer DEFAULT 50, p_offset integer DEFAULT 0) RETURNS TABLE(student_id text, first_name text, middle_name text, last_name text, extension_name text, email text, mobile text, year_level_name text, university_name text, archived_at timestamp without time zone, archived_by_name text, archive_reason text, archive_type text)
    LANGUAGE plpgsql
    AS $$
BEGIN
    RETURN QUERY
    SELECT 
        s.student_id,
        s.first_name,
        s.middle_name,
        s.last_name,
        s.extension_name,
        s.email,
        s.mobile,
        yl.name as year_level_name,
        u.name as university_name,
        s.archived_at,
        CONCAT(a.first_name, ' ', a.last_name) as archived_by_name,
        s.archive_reason,
        CASE 
            WHEN s.archived_by IS NULL THEN 'Automatic'
            ELSE 'Manual'
        END as archive_type
    FROM students s
    LEFT JOIN year_levels yl ON s.year_level_id = yl.year_level_id
    LEFT JOIN universities u ON s.university_id = u.university_id
    LEFT JOIN admins a ON s.archived_by = a.admin_id
    WHERE s.is_archived = TRUE
    AND (p_municipality_id IS NULL OR s.municipality_id = p_municipality_id)
    ORDER BY s.archived_at DESC
    LIMIT p_limit OFFSET p_offset;
END;
$$;


--
-- Name: FUNCTION get_archived_students(p_municipality_id integer, p_limit integer, p_offset integer); Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON FUNCTION public.get_archived_students(p_municipality_id integer, p_limit integer, p_offset integer) IS 'Retrieves paginated list of archived students with related details';


--
-- Name: get_confidence_breakdown(character varying); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.get_confidence_breakdown(student_id_param character varying) RETURNS jsonb
    LANGUAGE plpgsql
    AS $$
DECLARE
    result JSONB;
    personal_score DECIMAL(5,2);
    doc_score DECIMAL(5,2);
    ocr_score DECIMAL(5,2);
    verif_score DECIMAL(5,2);
    email_score DECIMAL(5,2);
    total_score DECIMAL(5,2);
    doc_count INT;
    verified_count INT;
    avg_ocr DECIMAL(5,2);
    avg_verif DECIMAL(5,2);
BEGIN
    -- Calculate each component
    
    -- Personal info score
    SELECT 
        CASE WHEN first_name IS NOT NULL AND first_name != '' 
             AND last_name IS NOT NULL AND last_name != ''
             AND email IS NOT NULL AND email != ''
             AND mobile IS NOT NULL AND mobile != ''
             AND bdate IS NOT NULL
             AND sex IS NOT NULL
             AND barangay_id IS NOT NULL
             AND university_id IS NOT NULL
             AND year_level_id IS NOT NULL
        THEN 25.00 
        ELSE (CASE WHEN first_name IS NOT NULL AND first_name != '' THEN 3.00 ELSE 0.00 END +
              CASE WHEN last_name IS NOT NULL AND last_name != '' THEN 3.00 ELSE 0.00 END +
              CASE WHEN email IS NOT NULL AND email != '' THEN 3.00 ELSE 0.00 END +
              CASE WHEN mobile IS NOT NULL AND mobile != '' THEN 3.00 ELSE 0.00 END +
              CASE WHEN bdate IS NOT NULL THEN 3.00 ELSE 0.00 END +
              CASE WHEN sex IS NOT NULL THEN 2.00 ELSE 0.00 END +
              CASE WHEN barangay_id IS NOT NULL THEN 2.00 ELSE 0.00 END +
              CASE WHEN university_id IS NOT NULL THEN 3.00 ELSE 0.00 END +
              CASE WHEN year_level_id IS NOT NULL THEN 3.00 ELSE 0.00 END)
        END
    INTO personal_score
    FROM students WHERE student_id = student_id_param;
    
    -- Document upload score
    SELECT COUNT(DISTINCT document_type_code) INTO doc_count
    FROM documents WHERE student_id = student_id_param AND status != 'rejected';
    doc_score := doc_count * 7.00;
    
    -- OCR quality score
    SELECT COALESCE(AVG(ocr_confidence), 0.00) INTO avg_ocr
    FROM documents WHERE student_id = student_id_param AND status != 'rejected';
    ocr_score := avg_ocr * 0.20;
    
    -- Verification score
    SELECT 
        COALESCE(AVG(verification_score), 0.00),
        COUNT(CASE WHEN verification_status = 'passed' THEN 1 END)
    INTO avg_verif, verified_count
    FROM documents WHERE student_id = student_id_param AND status != 'rejected';
    verif_score := (avg_verif * 0.10) + LEAST(verified_count * 1.00, 5.00);
    
    -- Email verification score
    SELECT 
        CASE WHEN status IN ('applicant', 'active', 'on_hold') 
        THEN 5.00 ELSE 0.00 END
    INTO email_score
    FROM students WHERE student_id = student_id_param;
    
    total_score := personal_score + doc_score + ocr_score + verif_score + email_score;
    
    -- Build JSON result
    result := jsonb_build_object(
        'total_score', total_score,
        'personal_info', jsonb_build_object(
            'score', personal_score,
            'max_score', 25,
            'percentage', ROUND((personal_score / 25.00) * 100, 2)
        ),
        'documents', jsonb_build_object(
            'score', doc_score,
            'max_score', 35,
            'count', doc_count,
            'required', 5,
            'percentage', ROUND((doc_score / 35.00) * 100, 2)
        ),
        'ocr_quality', jsonb_build_object(
            'score', ocr_score,
            'max_score', 20,
            'average_confidence', avg_ocr,
            'percentage', ROUND((ocr_score / 20.00) * 100, 2)
        ),
        'verification', jsonb_build_object(
            'score', verif_score,
            'max_score', 15,
            'average_score', avg_verif,
            'verified_count', verified_count,
            'percentage', ROUND((verif_score / 15.00) * 100, 2)
        ),
        'email_verification', jsonb_build_object(
            'score', email_score,
            'max_score', 5
        ),
        'level', get_confidence_level(total_score)
    );
    
    RETURN result;
END;
$$;


--
-- Name: FUNCTION get_confidence_breakdown(student_id_param character varying); Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON FUNCTION public.get_confidence_breakdown(student_id_param character varying) IS 'Returns detailed JSON breakdown of confidence score components for transparency and debugging';


--
-- Name: get_confidence_level(numeric); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.get_confidence_level(score numeric) RETURNS text
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF score >= 85.00 THEN
        RETURN 'Very High';
    ELSIF score >= 70.00 THEN
        RETURN 'High';
    ELSIF score >= 50.00 THEN
        RETURN 'Medium';
    ELSIF score >= 30.00 THEN
        RETURN 'Low';
    ELSE
        RETURN 'Very Low';
    END IF;
END;
$$;


--
-- Name: get_school_student_ids(integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.get_school_student_ids(p_university_id integer) RETURNS TABLE(school_student_id character varying, system_student_id character varying, student_name text, first_name character varying, last_name character varying, university_name character varying, status text, registered_at timestamp without time zone)
    LANGUAGE plpgsql
    AS $$
BEGIN
    RETURN QUERY
    SELECT 
        ssi.school_student_id,
        s.student_id as system_student_id,
        s.first_name || ' ' || COALESCE(s.middle_name || ' ', '') || s.last_name as student_name,
        ssi.first_name,
        ssi.last_name,
        ssi.university_name,
        ssi.status,
        ssi.registered_at
    FROM school_student_ids ssi
    JOIN students s ON ssi.student_id = s.student_id
    WHERE ssi.university_id = p_university_id
    ORDER BY ssi.registered_at DESC;
END;
$$;


--
-- Name: grading_is_passing(text, text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.grading_is_passing(p_university_key text, p_raw_grade text) RETURNS boolean
    LANGUAGE plpgsql STABLE
    AS $$
DECLARE
    policy_record RECORD;
    grade_numeric NUMERIC;
    passing_numeric NUMERIC;
    letter_index INT;
    passing_index INT;
BEGIN
    -- Get the active grading policy for the university
    SELECT scale_type, higher_is_better, highest_value, passing_value, letter_order
    INTO policy_record
    FROM university_passing_policy
    WHERE university_key = p_university_key AND is_active = TRUE;
    
    -- If no policy found, return false (strict default)
    IF NOT FOUND THEN
        RETURN FALSE;
    END IF;
    
    -- Handle different scale types
    CASE policy_record.scale_type
        WHEN 'NUMERIC_1_TO_5', 'NUMERIC_0_TO_4' THEN
            -- Try to convert grades to numeric
            BEGIN
                grade_numeric := p_raw_grade::NUMERIC;
                passing_numeric := policy_record.passing_value::NUMERIC;
                
                -- Apply direction logic
                IF policy_record.higher_is_better THEN
                    -- For 0-4 scale, grade must be >= passing value
                    RETURN grade_numeric >= passing_numeric;
                ELSE
                    -- For 1-5 scale, grade must be <= passing value
                    RETURN grade_numeric <= passing_numeric;
                END IF;
            EXCEPTION WHEN OTHERS THEN
                -- Non-numeric grade fails
                RETURN FALSE;
            END;
            
        WHEN 'PERCENT' THEN
            -- Handle percentage grades
            BEGIN
                grade_numeric := p_raw_grade::NUMERIC;
                passing_numeric := policy_record.passing_value::NUMERIC;
                
                -- For percentages, higher is always better
                RETURN grade_numeric >= passing_numeric;
            EXCEPTION WHEN OTHERS THEN
                RETURN FALSE;
            END;
            
        WHEN 'LETTER' THEN
            -- Handle letter grades using letter_order array
            IF policy_record.letter_order IS NULL THEN
                RETURN FALSE;
            END IF;
            
            -- Find index of grade and passing grade in letter_order array
            SELECT idx INTO letter_index FROM unnest(policy_record.letter_order) WITH ORDINALITY AS t(letter, idx) WHERE letter = p_raw_grade;
            SELECT idx INTO passing_index FROM unnest(policy_record.letter_order) WITH ORDINALITY AS t(letter, idx) WHERE letter = policy_record.passing_value;
            
            -- If either grade not found in order, fail
            IF letter_index IS NULL OR passing_index IS NULL THEN
                RETURN FALSE;
            END IF;
            
            -- Pass if grade index <= passing index (earlier in array means better)
            RETURN letter_index <= passing_index;
            
        ELSE
            -- Unknown scale type
            RETURN FALSE;
    END CASE;
END;
$$;


--
-- Name: has_archived_files(text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.has_archived_files(p_student_id text) RETURNS boolean
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_zip_exists BOOLEAN;
BEGIN
    -- This would need to be implemented with a custom function that checks the filesystem
    -- For now, we'll return TRUE if the student is archived
    SELECT is_archived INTO v_zip_exists
    FROM students
    WHERE student_id = p_student_id;
    
    RETURN COALESCE(v_zip_exists, FALSE);
END;
$$;


--
-- Name: log_document_audit(integer, character varying, character varying, character varying, character varying, text, character varying, integer, jsonb, character varying); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.log_document_audit(p_user_id integer, p_user_type character varying, p_username character varying, p_event_type character varying, p_event_category character varying, p_action_description text, p_affected_table character varying DEFAULT NULL::character varying, p_affected_record_id integer DEFAULT NULL::integer, p_metadata jsonb DEFAULT NULL::jsonb, p_status character varying DEFAULT 'success'::character varying) RETURNS integer
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_audit_id INTEGER;
BEGIN
    -- Insert into existing audit_logs table
    INSERT INTO audit_logs (
        user_id,
        user_type,
        username,
        event_type,
        event_category,
        action_description,
        status,
        ip_address,
        user_agent,
        affected_table,
        affected_record_id,
        metadata
    ) VALUES (
        p_user_id,
        p_user_type,
        p_username,
        p_event_type,
        p_event_category,
        p_action_description,
        p_status,
        inet_client_addr()::TEXT,  -- Get client IP if available
        current_setting('application.user_agent', true),  -- Get user agent if set
        p_affected_table,
        p_affected_record_id,
        p_metadata
    ) RETURNING audit_id INTO v_audit_id;
    
    RETURN v_audit_id;
END;
$$;


--
-- Name: FUNCTION log_document_audit(p_user_id integer, p_user_type character varying, p_username character varying, p_event_type character varying, p_event_category character varying, p_action_description text, p_affected_table character varying, p_affected_record_id integer, p_metadata jsonb, p_status character varying); Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON FUNCTION public.log_document_audit(p_user_id integer, p_user_type character varying, p_username character varying, p_event_type character varying, p_event_category character varying, p_action_description text, p_affected_table character varying, p_affected_record_id integer, p_metadata jsonb, p_status character varying) IS 'Logs document-related actions to existing audit_logs table';


--
-- Name: set_document_upload_needs(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.set_document_upload_needs() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    -- New registrations after the last distribution don't need upload tab
    -- (they upload during registration)
    IF NEW.status = 'under_registration' OR NEW.application_date > (
        SELECT COALESCE(MAX(finalized_at), '1970-01-01'::timestamp) 
        FROM distribution_snapshots
    ) THEN
        NEW.needs_document_upload = FALSE;
    ELSE
        NEW.needs_document_upload = TRUE;
    END IF;
    
    RETURN NEW;
END;
$$;


--
-- Name: track_school_student_id(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.track_school_student_id() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_university_name VARCHAR(255);
BEGIN
    -- Only track if school_student_id is provided
    IF NEW.school_student_id IS NOT NULL AND NEW.school_student_id != '' THEN
        -- Get university name
        SELECT name INTO v_university_name 
        FROM universities 
        WHERE university_id = NEW.university_id;
        
        -- Insert into tracking table with student information
        INSERT INTO school_student_ids (
            university_id,
            student_id,
            school_student_id,
            university_name,
            first_name,
            last_name,
            status
        ) VALUES (
            NEW.university_id,
            NEW.student_id,
            NEW.school_student_id,
            v_university_name,
            NEW.first_name,
            NEW.last_name,
            'active'
        )
        ON CONFLICT (university_id, school_student_id) DO NOTHING;
        
        -- Log the registration
        INSERT INTO school_student_id_audit (
            university_id,
            student_id,
            school_student_id,
            action,
            new_value,
            ip_address
        ) VALUES (
            NEW.university_id,
            NEW.student_id,
            NEW.school_student_id,
            'register',
            NEW.first_name || ' ' || NEW.last_name || ' (' || COALESCE(v_university_name, 'Unknown') || ')',
            inet_client_addr()::TEXT
        );
    END IF;
    
    RETURN NEW;
END;
$$;


--
-- Name: unarchive_student(text, integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.unarchive_student(p_student_id text, p_admin_id integer) RETURNS boolean
    LANGUAGE plpgsql
    AS $$
BEGIN
    -- Update student record
    UPDATE students
    SET 
        is_archived = FALSE,
        archived_at = NULL,
        archived_by = NULL,
        archive_reason = NULL,
        status = 'applicant' -- Set to applicant status (requires re-verification)
    WHERE student_id = p_student_id
    AND is_archived = TRUE; -- Only unarchive if currently archived
    
    -- Return true if a row was updated
    RETURN FOUND;
END;
$$;


--
-- Name: FUNCTION unarchive_student(p_student_id text, p_admin_id integer); Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON FUNCTION public.unarchive_student(p_student_id text, p_admin_id integer) IS 'Unarchives a student and restores active status';


--
-- Name: update_updated_at_column(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.update_updated_at_column() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$;


--
-- Name: validate_distribution_deadline(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.validate_distribution_deadline() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    last_dist_date DATE;
    last_dist_info TEXT;
BEGIN
    -- Only validate when finalized_at is being set (distribution is being finalized)
    IF NEW.finalized_at IS NOT NULL AND (OLD.finalized_at IS NULL OR OLD IS NULL) THEN
        -- Get the most recent finalized distribution date
        SELECT distribution_date, 
               semester || ' ' || academic_year 
        INTO last_dist_date, last_dist_info
        FROM distribution_snapshots
        WHERE finalized_at IS NOT NULL 
          AND snapshot_id != NEW.snapshot_id
        ORDER BY finalized_at DESC
        LIMIT 1;
        
        IF FOUND AND NEW.distribution_date <= last_dist_date THEN
            RAISE EXCEPTION 'Distribution date (%) cannot be on or before the last finalized distribution date (% for %). Please choose a later date.',
                NEW.distribution_date, last_dist_date, last_dist_info;
        END IF;
    END IF;
    
    RETURN NEW;
END;
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: university_passing_policy; Type: TABLE; Schema: grading; Owner: -
--

CREATE TABLE grading.university_passing_policy (
    policy_id integer NOT NULL,
    university_key text NOT NULL,
    scale_type text NOT NULL,
    higher_is_better boolean NOT NULL,
    highest_value text NOT NULL,
    passing_value text NOT NULL,
    letter_order text[],
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    CONSTRAINT university_passing_policy_scale_type_check CHECK ((scale_type = ANY (ARRAY['NUMERIC_1_TO_5'::text, 'NUMERIC_0_TO_4'::text, 'PERCENT'::text, 'LETTER'::text])))
);


--
-- Name: university_passing_policy_policy_id_seq; Type: SEQUENCE; Schema: grading; Owner: -
--

CREATE SEQUENCE grading.university_passing_policy_policy_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: university_passing_policy_policy_id_seq; Type: SEQUENCE OWNED BY; Schema: grading; Owner: -
--

ALTER SEQUENCE grading.university_passing_policy_policy_id_seq OWNED BY grading.university_passing_policy.policy_id;


--
-- Name: about_content_audit; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.about_content_audit (
    audit_id bigint NOT NULL,
    municipality_id integer DEFAULT 1 NOT NULL,
    block_key text NOT NULL,
    admin_id integer NOT NULL,
    admin_username text,
    action_type character varying(20) NOT NULL,
    old_html text,
    new_html text,
    old_text_color character varying(20),
    new_text_color character varying(20),
    old_bg_color character varying(20),
    new_bg_color character varying(20),
    created_at timestamp with time zone DEFAULT now()
);


--
-- Name: about_content_audit_audit_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.about_content_audit_audit_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: about_content_audit_audit_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.about_content_audit_audit_id_seq OWNED BY public.about_content_audit.audit_id;


--
-- Name: about_content_blocks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.about_content_blocks (
    id integer NOT NULL,
    municipality_id integer DEFAULT 1 NOT NULL,
    block_key text NOT NULL,
    html text NOT NULL,
    text_color character varying(20) DEFAULT NULL::character varying,
    bg_color character varying(20) DEFAULT NULL::character varying,
    updated_at timestamp with time zone DEFAULT now()
);


--
-- Name: about_content_blocks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.about_content_blocks_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: about_content_blocks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.about_content_blocks_id_seq OWNED BY public.about_content_blocks.id;


--
-- Name: admin_blacklist_verifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admin_blacklist_verifications (
    id integer NOT NULL,
    admin_id integer,
    otp character varying(6) NOT NULL,
    email character varying(255) NOT NULL,
    expires_at timestamp without time zone NOT NULL,
    used boolean DEFAULT false,
    created_at timestamp without time zone DEFAULT now(),
    session_data jsonb,
    student_id text
);


--
-- Name: admin_blacklist_verifications_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.admin_blacklist_verifications_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: admin_blacklist_verifications_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.admin_blacklist_verifications_id_seq OWNED BY public.admin_blacklist_verifications.id;


--
-- Name: admin_notifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admin_notifications (
    admin_notification_id integer NOT NULL,
    message text NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    is_read boolean DEFAULT false
);


--
-- Name: admin_notifications_admin_notification_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.admin_notifications_admin_notification_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: admin_notifications_admin_notification_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.admin_notifications_admin_notification_id_seq OWNED BY public.admin_notifications.admin_notification_id;


--
-- Name: admin_otp_verifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admin_otp_verifications (
    id integer NOT NULL,
    admin_id integer,
    otp character varying(6) NOT NULL,
    email character varying(255) NOT NULL,
    purpose character varying(50) NOT NULL,
    expires_at timestamp without time zone NOT NULL,
    used boolean DEFAULT false,
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: admin_otp_verifications_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.admin_otp_verifications_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: admin_otp_verifications_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.admin_otp_verifications_id_seq OWNED BY public.admin_otp_verifications.id;


--
-- Name: admins; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admins (
    admin_id integer NOT NULL,
    municipality_id integer,
    first_name text NOT NULL,
    middle_name text,
    last_name text NOT NULL,
    email text NOT NULL,
    username text NOT NULL,
    password text NOT NULL,
    role text DEFAULT 'super_admin'::text,
    is_active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT now(),
    last_login timestamp without time zone,
    CONSTRAINT admins_role_check CHECK ((role = ANY (ARRAY['super_admin'::text, 'sub_admin'::text])))
);


--
-- Name: admins_admin_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.admins_admin_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: admins_admin_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.admins_admin_id_seq OWNED BY public.admins.admin_id;


--
-- Name: announcements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.announcements (
    announcement_id integer NOT NULL,
    title text NOT NULL,
    remarks text,
    posted_at timestamp without time zone DEFAULT now() NOT NULL,
    is_active boolean DEFAULT false NOT NULL,
    event_date date,
    event_time time without time zone,
    location text,
    image_path text,
    updated_at timestamp with time zone DEFAULT now()
);


--
-- Name: announcements_announcement_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.announcements_announcement_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: announcements_announcement_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.announcements_announcement_id_seq OWNED BY public.announcements.announcement_id;


--
-- Name: announcements_content_audit; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.announcements_content_audit (
    audit_id bigint NOT NULL,
    municipality_id integer DEFAULT 1 NOT NULL,
    block_key text NOT NULL,
    admin_id integer NOT NULL,
    admin_username text,
    action_type character varying(20) NOT NULL,
    old_html text,
    new_html text,
    old_text_color character varying(20),
    new_text_color character varying(20),
    old_bg_color character varying(20),
    new_bg_color character varying(20),
    created_at timestamp with time zone DEFAULT now()
);


--
-- Name: announcements_content_audit_audit_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.announcements_content_audit_audit_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: announcements_content_audit_audit_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.announcements_content_audit_audit_id_seq OWNED BY public.announcements_content_audit.audit_id;


--
-- Name: announcements_content_blocks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.announcements_content_blocks (
    id integer NOT NULL,
    municipality_id integer DEFAULT 1 NOT NULL,
    block_key text NOT NULL,
    html text NOT NULL,
    text_color character varying(20) DEFAULT NULL::character varying,
    bg_color character varying(20) DEFAULT NULL::character varying,
    updated_at timestamp with time zone DEFAULT now()
);


--
-- Name: announcements_content_blocks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.announcements_content_blocks_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: announcements_content_blocks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.announcements_content_blocks_id_seq OWNED BY public.announcements_content_blocks.id;


--
-- Name: applications_backup_20251023; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.applications_backup_20251023 (
    application_id integer,
    semester text,
    academic_year text,
    is_valid boolean,
    remarks text,
    student_id text
);


--
-- Name: barangays; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.barangays (
    barangay_id integer NOT NULL,
    municipality_id integer,
    name text NOT NULL
);


--
-- Name: municipalities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.municipalities (
    municipality_id integer NOT NULL,
    name text NOT NULL,
    banner_image text,
    logo_image text,
    max_capacity integer,
    slug text,
    psgc_code text,
    district_no smallint,
    lgu_type text,
    preset_logo_image text,
    custom_logo_image text,
    use_custom_logo boolean DEFAULT false NOT NULL,
    primary_color character varying(7),
    secondary_color character varying(7),
    updated_at timestamp without time zone DEFAULT now(),
    CONSTRAINT municipalities_lgu_type_check CHECK ((lgu_type = ANY (ARRAY['city'::text, 'municipality'::text])))
);


--
-- Name: students; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.students (
    municipality_id integer NOT NULL,
    first_name text NOT NULL,
    middle_name text,
    last_name text,
    email text NOT NULL,
    mobile text NOT NULL,
    password text NOT NULL,
    sex text NOT NULL,
    status text DEFAULT 'applicant'::text NOT NULL,
    payroll_no integer,
    application_date timestamp without time zone DEFAULT now() NOT NULL,
    bdate date NOT NULL,
    barangay_id integer NOT NULL,
    university_id integer,
    year_level_id integer,
    student_id text NOT NULL,
    last_login timestamp without time zone,
    slot_id integer,
    status_blacklisted boolean DEFAULT false,
    documents_submitted boolean DEFAULT false,
    documents_validated boolean DEFAULT false,
    documents_submission_date timestamp without time zone,
    extension_name text,
    confidence_score numeric(5,2) DEFAULT 0.00,
    confidence_notes text,
    student_picture text,
    last_distribution_snapshot_id integer,
    needs_document_upload boolean DEFAULT false,
    is_archived boolean DEFAULT false,
    archived_at timestamp without time zone,
    archived_by integer,
    archive_reason text,
    expected_graduation_year integer,
    academic_year_registered text,
    school_student_id character varying(50),
    documents_to_reupload text,
    CONSTRAINT students_sex_check CHECK ((sex = ANY (ARRAY['Male'::text, 'Female'::text]))),
    CONSTRAINT students_status_check CHECK ((status = ANY (ARRAY['under_registration'::text, 'applicant'::text, 'active'::text, 'disabled'::text, 'given'::text, 'blacklisted'::text, 'archived'::text])))
);


--
-- Name: COLUMN students.slot_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.students.slot_id IS 'Tracks which signup slot the student originally registered under for audit trail and data integrity';


--
-- Name: COLUMN students.confidence_score; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.students.confidence_score IS 'Confidence score (0-100) based on data completeness, document quality, and validation results';


--
-- Name: COLUMN students.confidence_notes; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.students.confidence_notes IS 'Notes about confidence score calculation';


--
-- Name: COLUMN students.student_picture; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.students.student_picture IS 'File path to the student profile picture (relative path from web root)';


--
-- Name: COLUMN students.last_distribution_snapshot_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.students.last_distribution_snapshot_id IS 'References the last distribution this student participated in';


--
-- Name: COLUMN students.needs_document_upload; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.students.needs_document_upload IS 'TRUE if student needs to use Upload Documents tab (existing students), FALSE if documents come from registration (new students)';


--
-- Name: COLUMN students.is_archived; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.students.is_archived IS 'Flag indicating if student account is archived (graduated/inactive)';


--
-- Name: COLUMN students.archived_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.students.archived_at IS 'Timestamp when student was archived';


--
-- Name: COLUMN students.archived_by; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.students.archived_by IS 'Admin ID who archived the student (NULL for automatic archiving)';


--
-- Name: COLUMN students.archive_reason; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.students.archive_reason IS 'Reason for archiving: graduated, inactive, manual, no_attendance, etc.';


--
-- Name: COLUMN students.expected_graduation_year; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.students.expected_graduation_year IS 'Calculated graduation year based on year level at registration';


--
-- Name: COLUMN students.academic_year_registered; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.students.academic_year_registered IS 'Academic year when student first registered (e.g., 2024-2025)';


--
-- Name: COLUMN students.school_student_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.students.school_student_id IS 'Official student ID number from the school/university (e.g., 2024-12345). Different from system student_id.';


--
-- Name: COLUMN students.documents_to_reupload; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.students.documents_to_reupload IS 'JSON array of document type codes that need to be re-uploaded after rejection';


--
-- Name: year_levels; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.year_levels (
    year_level_id integer NOT NULL,
    name text NOT NULL,
    code text NOT NULL,
    sort_order integer NOT NULL,
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: archived_students_view; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.archived_students_view AS
 SELECT s.student_id,
    s.first_name,
    s.middle_name,
    s.last_name,
    s.extension_name,
    s.email,
    s.mobile,
    s.sex,
    s.bdate,
    s.status,
    s.application_date AS registration_date,
    s.is_archived,
    s.archived_at,
    s.archived_by,
    s.archive_reason,
    s.expected_graduation_year,
    m.name AS municipality_name,
    b.name AS barangay_name,
    yl.name AS year_level_name,
    a.username AS archived_by_username,
    concat(a.first_name, ' ', a.last_name) AS archived_by_name
   FROM ((((public.students s
     LEFT JOIN public.municipalities m ON ((s.municipality_id = m.municipality_id)))
     LEFT JOIN public.barangays b ON ((s.barangay_id = b.barangay_id)))
     LEFT JOIN public.year_levels yl ON ((s.year_level_id = yl.year_level_id)))
     LEFT JOIN public.admins a ON ((s.archived_by = a.admin_id)))
  WHERE (s.is_archived = true);


--
-- Name: audit_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.audit_logs (
    audit_id integer NOT NULL,
    user_id integer,
    user_type character varying(20) NOT NULL,
    username character varying(255),
    event_type character varying(50) NOT NULL,
    event_category character varying(30) NOT NULL,
    action_description text NOT NULL,
    status character varying(20) DEFAULT 'success'::character varying,
    ip_address character varying(45),
    user_agent text,
    request_method character varying(10),
    request_uri text,
    affected_table character varying(100),
    affected_record_id integer,
    old_values jsonb,
    new_values jsonb,
    metadata jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    session_id character varying(255)
);


--
-- Name: TABLE audit_logs; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.audit_logs IS 'Comprehensive audit trail for all system events';


--
-- Name: COLUMN audit_logs.event_type; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.audit_logs.event_type IS 'Specific event: login, logout, slot_opened, applicant_approved, etc.';


--
-- Name: COLUMN audit_logs.event_category; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.audit_logs.event_category IS 'Grouping: authentication, slot_management, applicant_management, payroll, schedule, profile, distribution, system';


--
-- Name: COLUMN audit_logs.old_values; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.audit_logs.old_values IS 'JSON snapshot of data before change';


--
-- Name: COLUMN audit_logs.new_values; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.audit_logs.new_values IS 'JSON snapshot of data after change';


--
-- Name: COLUMN audit_logs.metadata; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.audit_logs.metadata IS 'Additional context like reason, notes, batch info, etc.';


--
-- Name: audit_logs_audit_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.audit_logs_audit_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: audit_logs_audit_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.audit_logs_audit_id_seq OWNED BY public.audit_logs.audit_id;


--
-- Name: barangays_barangay_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.barangays_barangay_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: barangays_barangay_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.barangays_barangay_id_seq OWNED BY public.barangays.barangay_id;


--
-- Name: blacklisted_students; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.blacklisted_students (
    blacklist_id integer NOT NULL,
    reason_category text NOT NULL,
    detailed_reason text,
    blacklisted_by integer,
    blacklisted_at timestamp without time zone DEFAULT now(),
    admin_email text NOT NULL,
    admin_notes text,
    student_id text,
    CONSTRAINT blacklisted_students_reason_category_check CHECK ((reason_category = ANY (ARRAY['fraudulent_activity'::text, 'academic_misconduct'::text, 'system_abuse'::text, 'other'::text])))
);


--
-- Name: blacklisted_students_blacklist_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.blacklisted_students_blacklist_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: blacklisted_students_blacklist_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.blacklisted_students_blacklist_id_seq OWNED BY public.blacklisted_students.blacklist_id;


--
-- Name: config; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.config (
    key text NOT NULL,
    value text
);


--
-- Name: contact_content_audit; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.contact_content_audit (
    id integer NOT NULL,
    municipality_id integer DEFAULT 1,
    block_key character varying(100) NOT NULL,
    html_snapshot text,
    text_color character varying(20),
    bg_color character varying(20),
    changed_by character varying(255),
    changed_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: contact_content_audit_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.contact_content_audit_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: contact_content_audit_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.contact_content_audit_id_seq OWNED BY public.contact_content_audit.id;


--
-- Name: contact_content_blocks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.contact_content_blocks (
    id integer NOT NULL,
    municipality_id integer DEFAULT 1,
    block_key character varying(100) NOT NULL,
    html text,
    text_color character varying(20),
    bg_color character varying(20),
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: contact_content_blocks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.contact_content_blocks_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: contact_content_blocks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.contact_content_blocks_id_seq OWNED BY public.contact_content_blocks.id;


--
-- Name: distribution_file_manifest; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.distribution_file_manifest (
    manifest_id integer NOT NULL,
    snapshot_id integer,
    student_id text NOT NULL,
    document_type_code text,
    original_file_path text,
    file_size bigint,
    file_hash text,
    archived_path text,
    created_at timestamp without time zone DEFAULT now(),
    deleted_at timestamp without time zone
);


--
-- Name: TABLE distribution_file_manifest; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.distribution_file_manifest IS 'Detailed manifest of every file archived in distribution ZIPs - for integrity verification and file recovery';


--
-- Name: COLUMN distribution_file_manifest.deleted_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.distribution_file_manifest.deleted_at IS 'Timestamp when the original file was deleted from uploads after compression';


--
-- Name: distribution_file_manifest_manifest_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.distribution_file_manifest_manifest_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: distribution_file_manifest_manifest_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.distribution_file_manifest_manifest_id_seq OWNED BY public.distribution_file_manifest.manifest_id;


--
-- Name: distribution_snapshots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.distribution_snapshots (
    snapshot_id integer NOT NULL,
    distribution_date date NOT NULL,
    location text NOT NULL,
    total_students_count integer NOT NULL,
    active_slot_id integer,
    academic_year text,
    semester text,
    finalized_by integer,
    finalized_at timestamp without time zone DEFAULT now(),
    notes text,
    schedules_data jsonb,
    students_data jsonb,
    distribution_id text,
    archive_filename text,
    files_compressed boolean DEFAULT false,
    compression_date timestamp without time zone,
    original_total_size bigint DEFAULT 0,
    compressed_size bigint DEFAULT 0,
    compression_ratio numeric(5,2),
    space_saved bigint DEFAULT 0,
    total_files_count integer DEFAULT 0,
    archive_path text,
    municipality_id integer,
    metadata jsonb
);


--
-- Name: TABLE distribution_snapshots; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.distribution_snapshots IS 'Master record of each distribution cycle with metadata and compression statistics';


--
-- Name: distribution_snapshots_snapshot_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.distribution_snapshots_snapshot_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: distribution_snapshots_snapshot_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.distribution_snapshots_snapshot_id_seq OWNED BY public.distribution_snapshots.snapshot_id;


--
-- Name: distribution_student_records; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.distribution_student_records (
    record_id integer NOT NULL,
    snapshot_id integer,
    student_id text,
    qr_code_used text,
    scanned_at timestamp without time zone DEFAULT now(),
    scanned_by integer,
    verification_method text DEFAULT 'qr_scan'::text,
    notes text,
    distribution_id text
);


--
-- Name: TABLE distribution_student_records; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.distribution_student_records IS 'Tracks individual students who received aid in each distribution cycle - the link between students and snapshots';


--
-- Name: COLUMN distribution_student_records.verification_method; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.distribution_student_records.verification_method IS 'How distribution was verified: qr_scan, manual, etc.';


--
-- Name: distribution_student_records_record_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.distribution_student_records_record_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: distribution_student_records_record_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.distribution_student_records_record_id_seq OWNED BY public.distribution_student_records.record_id;


--
-- Name: distribution_student_snapshot; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.distribution_student_snapshot (
    student_snapshot_id integer NOT NULL,
    distribution_id text NOT NULL,
    student_id text NOT NULL,
    first_name text,
    last_name text,
    middle_name text,
    email text,
    mobile text,
    year_level_name text,
    university_name text,
    barangay_name text,
    payroll_number text,
    amount_received numeric(10,2),
    distribution_date date,
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: distribution_student_snapshot_v2_student_snapshot_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.distribution_student_snapshot_v2_student_snapshot_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: distribution_student_snapshot_v2_student_snapshot_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.distribution_student_snapshot_v2_student_snapshot_id_seq OWNED BY public.distribution_student_snapshot.student_snapshot_id;


--
-- Name: document_archives; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.document_archives (
    archive_id integer NOT NULL,
    student_id character varying(50) NOT NULL,
    original_document_id integer,
    document_type character varying(50) NOT NULL,
    file_path text NOT NULL,
    original_upload_date timestamp without time zone,
    archived_date timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    distribution_snapshot_id integer,
    academic_year character varying(20),
    semester character varying(20)
);


--
-- Name: TABLE document_archives; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.document_archives IS 'Stores archived documents from previous distribution cycles';


--
-- Name: document_archives_archive_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.document_archives_archive_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: document_archives_archive_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.document_archives_archive_id_seq OWNED BY public.document_archives.archive_id;


--
-- Name: documents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.documents (
    document_id character varying(100) NOT NULL,
    student_id character varying(100) NOT NULL,
    document_type_code character varying(2) NOT NULL,
    document_type_name character varying(50) NOT NULL,
    file_path text NOT NULL,
    file_name character varying(255) NOT NULL,
    file_extension character varying(10) NOT NULL,
    file_size_bytes bigint,
    verification_status character varying(20) DEFAULT 'pending'::character varying,
    verification_details jsonb,
    status character varying(20) DEFAULT 'temp'::character varying,
    upload_date timestamp without time zone DEFAULT now(),
    upload_year integer DEFAULT EXTRACT(year FROM now()),
    last_modified timestamp without time zone DEFAULT now(),
    approved_date timestamp without time zone,
    approved_by integer,
    ocr_confidence numeric(5,2) DEFAULT 0,
    verification_score numeric(5,2) DEFAULT 0,
    CONSTRAINT valid_document_type CHECK (((document_type_code)::text = ANY ((ARRAY['00'::character varying, '01'::character varying, '02'::character varying, '03'::character varying, '04'::character varying])::text[]))),
    CONSTRAINT valid_status CHECK (((status)::text = ANY ((ARRAY['temp'::character varying, 'approved'::character varying, 'rejected'::character varying])::text[]))),
    CONSTRAINT valid_verification_status CHECK (((verification_status)::text = ANY ((ARRAY['pending'::character varying, 'passed'::character varying, 'failed'::character varying, 'manual_review'::character varying])::text[])))
);


--
-- Name: TABLE documents; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.documents IS 'Unified document management with verification data in JSONB (cleaned schema as of 2025-10-30)';


--
-- Name: COLUMN documents.document_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.documents.document_id IS 'Format: STUDENTID-DOCU-YEAR-TYPE (e.g., GENERALTRIAS-2025-3-DWXA3N-DOCU-2025-01)';


--
-- Name: COLUMN documents.document_type_code; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.documents.document_type_code IS '00=EAF, 01=Grades, 02=Letter to Mayor, 03=Certificate of Indigency, 04=ID Picture';


--
-- Name: COLUMN documents.verification_details; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.documents.verification_details IS 'JSONB storing full verification results including individual checks, confidence scores, recommendations';


--
-- Name: COLUMN documents.ocr_confidence; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.documents.ocr_confidence IS 'OCR confidence score 0-100 (used in UI)';


--
-- Name: COLUMN documents.verification_score; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.documents.verification_score IS 'Verification score 0-100 (used in UI)';


--
-- Name: file_archive_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.file_archive_log (
    log_id integer NOT NULL,
    student_id text,
    operation text NOT NULL,
    file_count integer DEFAULT 0,
    total_size_before bigint,
    total_size_after bigint,
    space_saved bigint,
    operation_status text,
    error_message text,
    performed_by integer,
    performed_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: file_archive_log_log_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.file_archive_log_log_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: file_archive_log_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.file_archive_log_log_id_seq OWNED BY public.file_archive_log.log_id;


--
-- Name: grading_systems; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.grading_systems (
    id integer NOT NULL,
    system_name character varying(50) NOT NULL,
    display_name character varying(100) NOT NULL,
    scale_type character varying(20) NOT NULL,
    min_value numeric(5,2),
    max_value numeric(5,2),
    passing_grade numeric(5,2),
    grade_mappings jsonb,
    is_active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: TABLE grading_systems; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.grading_systems IS 'Configuration for different grading systems';


--
-- Name: grading_systems_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.grading_systems_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: grading_systems_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.grading_systems_id_seq OWNED BY public.grading_systems.id;


--
-- Name: header_theme_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.header_theme_settings (
    header_theme_id integer NOT NULL,
    municipality_id integer NOT NULL,
    header_bg_color character varying(7) DEFAULT '#ffffff'::character varying NOT NULL,
    header_border_color character varying(7) DEFAULT '#e1e7e3'::character varying NOT NULL,
    header_text_color character varying(7) DEFAULT '#2e7d32'::character varying NOT NULL,
    header_icon_color character varying(7) DEFAULT '#2e7d32'::character varying NOT NULL,
    header_hover_bg character varying(7) DEFAULT '#e9f5e9'::character varying NOT NULL,
    header_hover_icon_color character varying(7) DEFAULT '#1b5e20'::character varying NOT NULL,
    updated_by integer,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: header_theme_settings_header_theme_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.header_theme_settings_header_theme_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: header_theme_settings_header_theme_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.header_theme_settings_header_theme_id_seq OWNED BY public.header_theme_settings.header_theme_id;


--
-- Name: how_it_works_content_audit; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.how_it_works_content_audit (
    audit_id bigint NOT NULL,
    municipality_id integer DEFAULT 1 NOT NULL,
    block_key text NOT NULL,
    admin_id integer NOT NULL,
    admin_username text,
    action_type character varying(20) NOT NULL,
    old_html text,
    new_html text,
    old_text_color character varying(20),
    new_text_color character varying(20),
    old_bg_color character varying(20),
    new_bg_color character varying(20),
    created_at timestamp with time zone DEFAULT now()
);


--
-- Name: how_it_works_content_audit_audit_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.how_it_works_content_audit_audit_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: how_it_works_content_audit_audit_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.how_it_works_content_audit_audit_id_seq OWNED BY public.how_it_works_content_audit.audit_id;


--
-- Name: how_it_works_content_blocks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.how_it_works_content_blocks (
    id integer NOT NULL,
    municipality_id integer DEFAULT 1 NOT NULL,
    block_key text NOT NULL,
    html text NOT NULL,
    text_color character varying(20) DEFAULT NULL::character varying,
    bg_color character varying(20) DEFAULT NULL::character varying,
    updated_at timestamp with time zone DEFAULT now()
);


--
-- Name: how_it_works_content_blocks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.how_it_works_content_blocks_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: how_it_works_content_blocks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.how_it_works_content_blocks_id_seq OWNED BY public.how_it_works_content_blocks.id;


--
-- Name: landing_content_audit; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.landing_content_audit (
    audit_id bigint NOT NULL,
    municipality_id integer DEFAULT 1 NOT NULL,
    block_key text NOT NULL,
    admin_id integer NOT NULL,
    admin_username text,
    action_type character varying(20) NOT NULL,
    old_html text,
    new_html text,
    old_text_color character varying(20),
    new_text_color character varying(20),
    old_bg_color character varying(20),
    new_bg_color character varying(20),
    created_at timestamp with time zone DEFAULT now()
);


--
-- Name: landing_content_audit_audit_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.landing_content_audit_audit_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: landing_content_audit_audit_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.landing_content_audit_audit_id_seq OWNED BY public.landing_content_audit.audit_id;


--
-- Name: landing_content_blocks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.landing_content_blocks (
    id integer NOT NULL,
    municipality_id integer DEFAULT 1 NOT NULL,
    block_key text NOT NULL,
    html text NOT NULL,
    text_color character varying(20) DEFAULT NULL::character varying,
    bg_color character varying(20) DEFAULT NULL::character varying,
    updated_at timestamp with time zone DEFAULT now(),
    is_visible boolean DEFAULT true
);


--
-- Name: COLUMN landing_content_blocks.is_visible; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.landing_content_blocks.is_visible IS 'Controls whether this content block is displayed (true) or archived (false)';


--
-- Name: landing_content_blocks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.landing_content_blocks_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: landing_content_blocks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.landing_content_blocks_id_seq OWNED BY public.landing_content_blocks.id;


--
-- Name: login_content_blocks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.login_content_blocks (
    id integer NOT NULL,
    municipality_id integer DEFAULT 1 NOT NULL,
    block_key text NOT NULL,
    html text NOT NULL,
    text_color character varying(20) DEFAULT NULL::character varying,
    bg_color character varying(20) DEFAULT NULL::character varying,
    is_visible boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: login_content_blocks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.login_content_blocks_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: login_content_blocks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.login_content_blocks_id_seq OWNED BY public.login_content_blocks.id;


--
-- Name: municipalities_municipality_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.municipalities_municipality_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: municipalities_municipality_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.municipalities_municipality_id_seq OWNED BY public.municipalities.municipality_id;


--
-- Name: notifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notifications (
    notification_id integer NOT NULL,
    message text NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    student_id text,
    is_priority boolean DEFAULT false,
    viewed_at timestamp without time zone,
    title character varying(255),
    type character varying(50) DEFAULT 'info'::character varying,
    priority character varying(20) DEFAULT 'low'::character varying,
    is_read boolean DEFAULT false,
    action_url text,
    expires_at timestamp without time zone
);


--
-- Name: COLUMN notifications.is_priority; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.notifications.is_priority IS 'TRUE for urgent notifications that need immediate attention (e.g., document rejections)';


--
-- Name: COLUMN notifications.viewed_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.notifications.viewed_at IS 'Timestamp when priority notification was first viewed (for one-time display)';


--
-- Name: COLUMN notifications.title; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.notifications.title IS 'Short title/subject for the notification';


--
-- Name: COLUMN notifications.type; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.notifications.type IS 'Type of notification: announcement, document, schedule, system, warning, error, success';


--
-- Name: COLUMN notifications.priority; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.notifications.priority IS 'Priority level: low, medium, high';


--
-- Name: COLUMN notifications.is_read; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.notifications.is_read IS 'Track whether the notification has been read';


--
-- Name: COLUMN notifications.action_url; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.notifications.action_url IS 'Optional URL to navigate to when notification is clicked';


--
-- Name: COLUMN notifications.expires_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.notifications.expires_at IS 'Optional expiration timestamp - notifications expire automatically after this time';


--
-- Name: notifications_notification_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.notifications_notification_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: notifications_notification_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.notifications_notification_id_seq OWNED BY public.notifications.notification_id;


--
-- Name: qr_codes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.qr_codes (
    qr_id integer NOT NULL,
    payroll_number integer NOT NULL,
    student_id text,
    status text DEFAULT 'Pending'::text,
    created_at timestamp without time zone DEFAULT now(),
    unique_id text,
    CONSTRAINT qr_codes_status_check CHECK ((status = ANY (ARRAY['Pending'::text, 'Done'::text])))
);


--
-- Name: qr_codes_qr_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.qr_codes_qr_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: qr_codes_qr_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.qr_codes_qr_id_seq OWNED BY public.qr_codes.qr_id;


--
-- Name: qr_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.qr_logs (
    log_id integer NOT NULL,
    scanned_at timestamp without time zone DEFAULT now(),
    scanned_by integer,
    student_id text
);


--
-- Name: qr_logs_log_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.qr_logs_log_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: qr_logs_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.qr_logs_log_id_seq OWNED BY public.qr_logs.log_id;


--
-- Name: requirements_content_audit; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.requirements_content_audit (
    audit_id bigint NOT NULL,
    municipality_id integer DEFAULT 1 NOT NULL,
    block_key text NOT NULL,
    admin_id integer NOT NULL,
    admin_username text,
    action_type character varying(20) NOT NULL,
    old_html text,
    new_html text,
    old_text_color character varying(20),
    new_text_color character varying(20),
    old_bg_color character varying(20),
    new_bg_color character varying(20),
    created_at timestamp with time zone DEFAULT now()
);


--
-- Name: requirements_content_audit_audit_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.requirements_content_audit_audit_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: requirements_content_audit_audit_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.requirements_content_audit_audit_id_seq OWNED BY public.requirements_content_audit.audit_id;


--
-- Name: requirements_content_blocks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.requirements_content_blocks (
    id integer NOT NULL,
    municipality_id integer DEFAULT 1 NOT NULL,
    block_key text NOT NULL,
    html text NOT NULL,
    text_color character varying(20) DEFAULT NULL::character varying,
    bg_color character varying(20) DEFAULT NULL::character varying,
    updated_at timestamp with time zone DEFAULT now()
);


--
-- Name: requirements_content_blocks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.requirements_content_blocks_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: requirements_content_blocks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.requirements_content_blocks_id_seq OWNED BY public.requirements_content_blocks.id;


--
-- Name: schedule_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.schedule_batches (
    batch_config_id integer NOT NULL,
    schedule_date date NOT NULL,
    batch_number integer NOT NULL,
    batch_name text NOT NULL,
    start_time time without time zone NOT NULL,
    end_time time without time zone NOT NULL,
    max_students integer DEFAULT 50 NOT NULL,
    location text NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    created_by integer
);


--
-- Name: schedule_batches_batch_config_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.schedule_batches_batch_config_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: schedule_batches_batch_config_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.schedule_batches_batch_config_id_seq OWNED BY public.schedule_batches.batch_config_id;


--
-- Name: schedules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.schedules (
    schedule_id integer NOT NULL,
    payroll_no integer NOT NULL,
    batch_no integer NOT NULL,
    distribution_date date NOT NULL,
    time_slot text NOT NULL,
    location text DEFAULT ''::text NOT NULL,
    status text DEFAULT 'scheduled'::text,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    max_students_per_batch integer DEFAULT 50,
    batch_name text,
    student_id text,
    CONSTRAINT schedules_status_check CHECK ((status = ANY (ARRAY['scheduled'::text, 'completed'::text, 'missed'::text])))
);


--
-- Name: schedules_schedule_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.schedules_schedule_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: schedules_schedule_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.schedules_schedule_id_seq OWNED BY public.schedules.schedule_id;


--
-- Name: school_student_id_audit; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.school_student_id_audit (
    audit_id integer NOT NULL,
    university_id integer NOT NULL,
    student_id character varying(50) NOT NULL,
    school_student_id character varying(50) NOT NULL,
    action character varying(50) NOT NULL,
    old_value text,
    new_value text,
    performed_by character varying(100),
    performed_at timestamp without time zone DEFAULT now(),
    ip_address character varying(50),
    notes text
);


--
-- Name: TABLE school_student_id_audit; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.school_student_id_audit IS 'Audit log for all changes to school student ID records';


--
-- Name: school_student_id_audit_audit_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.school_student_id_audit_audit_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: school_student_id_audit_audit_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.school_student_id_audit_audit_id_seq OWNED BY public.school_student_id_audit.audit_id;


--
-- Name: school_student_ids; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.school_student_ids (
    id integer NOT NULL,
    university_id integer NOT NULL,
    student_id character varying(50) NOT NULL,
    school_student_id character varying(50) NOT NULL,
    university_name character varying(255),
    first_name character varying(100),
    last_name character varying(100),
    registered_at timestamp without time zone DEFAULT now(),
    status character varying(50) DEFAULT 'active'::character varying,
    notes text
);


--
-- Name: TABLE school_student_ids; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.school_student_ids IS 'Tracks all school-issued student IDs to prevent duplicate registrations';


--
-- Name: school_student_ids_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.school_student_ids_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: school_student_ids_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.school_student_ids_id_seq OWNED BY public.school_student_ids.id;


--
-- Name: sidebar_theme_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sidebar_theme_settings (
    id integer NOT NULL,
    municipality_id integer DEFAULT 1 NOT NULL,
    sidebar_bg_start character varying(7) DEFAULT '#f8f9fa'::character varying,
    sidebar_bg_end character varying(7) DEFAULT '#ffffff'::character varying,
    sidebar_border_color character varying(7) DEFAULT '#dee2e6'::character varying,
    nav_text_color character varying(7) DEFAULT '#212529'::character varying,
    nav_icon_color character varying(7) DEFAULT '#6c757d'::character varying,
    nav_hover_bg character varying(7) DEFAULT '#e9ecef'::character varying,
    nav_hover_text character varying(7) DEFAULT '#212529'::character varying,
    nav_active_bg character varying(7) DEFAULT '#0d6efd'::character varying,
    nav_active_text character varying(7) DEFAULT '#ffffff'::character varying,
    profile_avatar_bg_start character varying(7) DEFAULT '#0d6efd'::character varying,
    profile_avatar_bg_end character varying(7) DEFAULT '#0b5ed7'::character varying,
    profile_name_color character varying(7) DEFAULT '#212529'::character varying,
    profile_role_color character varying(7) DEFAULT '#6c757d'::character varying,
    profile_border_color character varying(7) DEFAULT '#dee2e6'::character varying,
    submenu_bg character varying(7) DEFAULT '#f8f9fa'::character varying,
    submenu_text_color character varying(7) DEFAULT '#495057'::character varying,
    submenu_hover_bg character varying(7) DEFAULT '#e9ecef'::character varying,
    submenu_active_bg character varying(7) DEFAULT '#e7f3ff'::character varying,
    submenu_active_text character varying(7) DEFAULT '#0d6efd'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: sidebar_theme_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sidebar_theme_settings_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sidebar_theme_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sidebar_theme_settings_id_seq OWNED BY public.sidebar_theme_settings.id;


--
-- Name: signup_slots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.signup_slots (
    slot_id integer NOT NULL,
    municipality_id integer,
    slot_count integer NOT NULL,
    is_active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT now(),
    semester text,
    academic_year text,
    manually_finished boolean DEFAULT false,
    finished_at timestamp without time zone
);


--
-- Name: signup_slots_slot_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.signup_slots_slot_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: signup_slots_slot_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.signup_slots_slot_id_seq OWNED BY public.signup_slots.slot_id;


--
-- Name: student_active_sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.student_active_sessions (
    session_id character varying(255) NOT NULL,
    student_id character varying(50) NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    last_activity timestamp without time zone DEFAULT now(),
    ip_address character varying(45),
    user_agent text,
    device_type character varying(50),
    browser character varying(100),
    os character varying(100),
    location character varying(255),
    expires_at timestamp without time zone,
    is_current boolean DEFAULT false
);


--
-- Name: TABLE student_active_sessions; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.student_active_sessions IS 'Tracks currently active student sessions for security management';


--
-- Name: student_login_history; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.student_login_history (
    history_id integer NOT NULL,
    student_id character varying(50) NOT NULL,
    login_time timestamp without time zone DEFAULT now(),
    logout_time timestamp without time zone,
    ip_address character varying(45),
    user_agent text,
    device_type character varying(50),
    browser character varying(100),
    os character varying(100),
    location character varying(255),
    login_method character varying(50) DEFAULT 'password'::character varying,
    status character varying(20) DEFAULT 'success'::character varying,
    session_id character varying(255),
    failure_reason text
);


--
-- Name: TABLE student_login_history; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.student_login_history IS 'Records all student login attempts and activity';


--
-- Name: student_login_history_history_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.student_login_history_history_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: student_login_history_history_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.student_login_history_history_id_seq OWNED BY public.student_login_history.history_id;


--
-- Name: student_notifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.student_notifications (
    notification_id integer NOT NULL,
    student_id text NOT NULL,
    title text NOT NULL,
    message text NOT NULL,
    type character varying(50) DEFAULT 'info'::character varying,
    priority character varying(20) DEFAULT 'low'::character varying,
    action_url text,
    is_read boolean DEFAULT false,
    is_priority boolean DEFAULT false,
    viewed_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    expires_at timestamp without time zone
);


--
-- Name: TABLE student_notifications; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.student_notifications IS 'Dedicated notification system for students';


--
-- Name: COLUMN student_notifications.notification_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.student_notifications.notification_id IS 'Unique notification identifier';


--
-- Name: COLUMN student_notifications.student_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.student_notifications.student_id IS 'Foreign key reference to students table';


--
-- Name: COLUMN student_notifications.title; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.student_notifications.title IS 'Brief notification title';


--
-- Name: COLUMN student_notifications.message; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.student_notifications.message IS 'Full notification message/description';


--
-- Name: COLUMN student_notifications.type; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.student_notifications.type IS 'Notification type: info, warning, error, success, document, application, etc.';


--
-- Name: COLUMN student_notifications.priority; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.student_notifications.priority IS 'Priority level: high, medium, low';


--
-- Name: COLUMN student_notifications.action_url; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.student_notifications.action_url IS 'Optional URL to navigate when notification is clicked';


--
-- Name: COLUMN student_notifications.is_read; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.student_notifications.is_read IS 'Whether the notification has been read';


--
-- Name: COLUMN student_notifications.is_priority; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.student_notifications.is_priority IS 'TRUE for urgent notifications that need immediate attention';


--
-- Name: COLUMN student_notifications.viewed_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.student_notifications.viewed_at IS 'Timestamp when priority notification was first viewed';


--
-- Name: COLUMN student_notifications.created_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.student_notifications.created_at IS 'When the notification was created';


--
-- Name: COLUMN student_notifications.expires_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.student_notifications.expires_at IS 'Optional expiration date for time-sensitive notifications';


--
-- Name: student_notifications_notification_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.student_notifications_notification_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: student_notifications_notification_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.student_notifications_notification_id_seq OWNED BY public.student_notifications.notification_id;


--
-- Name: students_backup_redundant_fields_20251024; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.students_backup_redundant_fields_20251024 (
    student_id text,
    qr_code text,
    has_received boolean
);


--
-- Name: theme_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.theme_settings (
    theme_id integer NOT NULL,
    municipality_id integer DEFAULT 1,
    topbar_email character varying(100) DEFAULT 'educaid@generaltrias.gov.ph'::character varying,
    topbar_phone character varying(50) DEFAULT '(046) 886-4454'::character varying,
    topbar_office_hours character varying(100) DEFAULT 'Mon–Fri 8:00AM - 5:00PM'::character varying,
    system_name character varying(100) DEFAULT 'EducAid'::character varying,
    municipality_name character varying(100) DEFAULT 'City of General Trias'::character varying,
    is_active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_by integer,
    topbar_bg_color character varying(7) DEFAULT '#2e7d32'::character varying,
    topbar_bg_gradient character varying(7) DEFAULT '#1b5e20'::character varying,
    topbar_text_color character varying(7) DEFAULT '#ffffff'::character varying,
    topbar_link_color character varying(7) DEFAULT '#e8f5e9'::character varying
);


--
-- Name: theme_settings_theme_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.theme_settings_theme_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: theme_settings_theme_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.theme_settings_theme_id_seq OWNED BY public.theme_settings.theme_id;


--
-- Name: universities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.universities (
    university_id integer NOT NULL,
    name text NOT NULL,
    code text NOT NULL,
    created_at timestamp without time zone DEFAULT now()
);


--
-- Name: universities_university_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.universities_university_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: universities_university_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.universities_university_id_seq OWNED BY public.universities.university_id;


--
-- Name: university_passing_policy; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.university_passing_policy (
    policy_id integer NOT NULL,
    university_key text NOT NULL,
    scale_type text NOT NULL,
    higher_is_better boolean NOT NULL,
    highest_value text NOT NULL,
    passing_value text NOT NULL,
    letter_order text[],
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now(),
    CONSTRAINT university_passing_policy_scale_type_check CHECK ((scale_type = ANY (ARRAY['NUMERIC_1_TO_5'::text, 'NUMERIC_0_TO_4'::text, 'PERCENT'::text, 'LETTER'::text])))
);


--
-- Name: university_passing_policy_policy_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.university_passing_policy_policy_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: university_passing_policy_policy_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.university_passing_policy_policy_id_seq OWNED BY public.university_passing_policy.policy_id;


--
-- Name: used_schedule_dates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.used_schedule_dates (
    date_id integer NOT NULL,
    schedule_date date NOT NULL,
    location text NOT NULL,
    total_students integer NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    created_by integer
);


--
-- Name: used_schedule_dates_date_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.used_schedule_dates_date_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: used_schedule_dates_date_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.used_schedule_dates_date_id_seq OWNED BY public.used_schedule_dates.date_id;


--
-- Name: v_archived_students_summary; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.v_archived_students_summary AS
 SELECT s.student_id,
    s.first_name,
    s.middle_name,
    s.last_name,
    s.extension_name,
    s.email,
    s.mobile,
    s.status,
    yl.name AS year_level_name,
    u.name AS university_name,
    s.academic_year_registered,
    s.expected_graduation_year,
    s.is_archived,
    s.archived_at,
    s.archived_by,
    s.archive_reason,
    concat(a.first_name, ' ', a.last_name) AS archived_by_name,
    s.application_date,
    s.last_login,
        CASE
            WHEN (s.archived_by IS NULL) THEN 'Automatic'::text
            ELSE 'Manual'::text
        END AS archive_type
   FROM (((public.students s
     LEFT JOIN public.year_levels yl ON ((s.year_level_id = yl.year_level_id)))
     LEFT JOIN public.universities u ON ((s.university_id = u.university_id)))
     LEFT JOIN public.admins a ON ((s.archived_by = a.admin_id)))
  WHERE (s.is_archived = true)
  ORDER BY s.archived_at DESC;


--
-- Name: v_distribution_history; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.v_distribution_history AS
SELECT
    NULL::integer AS snapshot_id,
    NULL::text AS distribution_id,
    NULL::text AS academic_year,
    NULL::text AS semester,
    NULL::date AS distribution_date,
    NULL::text AS location,
    NULL::timestamp without time zone AS finalized_at,
    NULL::integer AS finalized_by,
    NULL::text AS finalized_by_username,
    NULL::integer AS total_students_count,
    NULL::bigint AS actual_students_distributed,
    NULL::boolean AS files_compressed,
    NULL::timestamp without time zone AS compression_date,
    NULL::integer AS total_files_count,
    NULL::bigint AS compressed_size,
    NULL::numeric(5,2) AS compression_ratio,
    NULL::text AS archive_filename,
    NULL::text AS notes;


--
-- Name: VIEW v_distribution_history; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON VIEW public.v_distribution_history IS 'Convenient view showing distribution history with student counts and compression stats';


--
-- Name: v_failed_logins; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.v_failed_logins AS
 SELECT audit_id,
    user_type,
    username,
    ip_address,
    user_agent,
    (metadata ->> 'reason'::text) AS failure_reason,
    created_at
   FROM public.audit_logs
  WHERE ((event_type)::text = 'login_failed'::text)
  ORDER BY created_at DESC;


--
-- Name: v_recent_admin_activity; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.v_recent_admin_activity AS
 SELECT audit_id,
    username,
    event_type,
    event_category,
    action_description,
    status,
    ip_address,
    created_at
   FROM public.audit_logs
  WHERE ((user_type)::text = 'admin'::text)
  ORDER BY created_at DESC
 LIMIT 100;


--
-- Name: v_recent_student_activity; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.v_recent_student_activity AS
 SELECT audit_id,
    username,
    event_type,
    event_category,
    action_description,
    status,
    ip_address,
    created_at
   FROM public.audit_logs
  WHERE ((user_type)::text = 'student'::text)
  ORDER BY created_at DESC
 LIMIT 100;


--
-- Name: v_school_student_id_duplicates; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.v_school_student_id_duplicates AS
 SELECT ssi.university_name,
    ssi.school_student_id,
    count(*) AS registration_count,
    array_agg(s.student_id) AS system_student_ids,
    array_agg((((ssi.first_name)::text || ' '::text) || (ssi.last_name)::text)) AS student_names,
    array_agg(s.status) AS statuses,
    min(ssi.registered_at) AS first_registered,
    max(ssi.registered_at) AS last_registered
   FROM (public.school_student_ids ssi
     JOIN public.students s ON (((ssi.student_id)::text = s.student_id)))
  WHERE ((ssi.status)::text = 'active'::text)
  GROUP BY ssi.university_name, ssi.school_student_id
 HAVING (count(*) > 1)
  ORDER BY (count(*)) DESC, (max(ssi.registered_at)) DESC;


--
-- Name: v_students_eligible_for_archiving; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.v_students_eligible_for_archiving AS
 SELECT s.student_id,
    s.first_name,
    s.last_name,
    s.email,
    s.status,
    s.year_level_id,
    yl.name AS year_level_name,
    s.academic_year_registered,
    s.expected_graduation_year,
    (EXTRACT(year FROM CURRENT_DATE))::integer AS current_year,
    ((EXTRACT(year FROM CURRENT_DATE))::integer - s.expected_graduation_year) AS years_past_graduation,
    s.last_login,
    s.application_date,
        CASE
            WHEN ((EXTRACT(year FROM CURRENT_DATE))::integer > s.expected_graduation_year) THEN 'Graduated (past expected graduation year)'::text
            WHEN (((EXTRACT(year FROM CURRENT_DATE))::integer = s.expected_graduation_year) AND (EXTRACT(month FROM CURRENT_DATE) >= (6)::numeric)) THEN 'Graduated (current graduation year, past June)'::text
            WHEN ((s.last_login IS NOT NULL) AND (s.last_login < (CURRENT_DATE - '2 years'::interval))) THEN 'Inactive (no login for 2+ years)'::text
            WHEN ((s.last_login IS NULL) AND (s.application_date < (CURRENT_DATE - '2 years'::interval))) THEN 'Inactive (never logged in, registered 2+ years ago)'::text
            ELSE 'Other'::text
        END AS eligibility_reason
   FROM (public.students s
     LEFT JOIN public.year_levels yl ON ((s.year_level_id = yl.year_level_id)))
  WHERE ((s.is_archived = false) AND (s.status <> 'blacklisted'::text) AND (((EXTRACT(year FROM CURRENT_DATE))::integer > s.expected_graduation_year) OR (((EXTRACT(year FROM CURRENT_DATE))::integer = s.expected_graduation_year) AND (EXTRACT(month FROM CURRENT_DATE) >= (6)::numeric)) OR ((s.last_login IS NOT NULL) AND (s.last_login < (CURRENT_DATE - '2 years'::interval))) OR ((s.last_login IS NULL) AND (s.application_date < (CURRENT_DATE - '2 years'::interval)))));


--
-- Name: year_levels_year_level_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.year_levels_year_level_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: year_levels_year_level_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.year_levels_year_level_id_seq OWNED BY public.year_levels.year_level_id;


--
-- Name: university_passing_policy policy_id; Type: DEFAULT; Schema: grading; Owner: -
--

ALTER TABLE ONLY grading.university_passing_policy ALTER COLUMN policy_id SET DEFAULT nextval('grading.university_passing_policy_policy_id_seq'::regclass);


--
-- Name: about_content_audit audit_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.about_content_audit ALTER COLUMN audit_id SET DEFAULT nextval('public.about_content_audit_audit_id_seq'::regclass);


--
-- Name: about_content_blocks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.about_content_blocks ALTER COLUMN id SET DEFAULT nextval('public.about_content_blocks_id_seq'::regclass);


--
-- Name: admin_blacklist_verifications id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_blacklist_verifications ALTER COLUMN id SET DEFAULT nextval('public.admin_blacklist_verifications_id_seq'::regclass);


--
-- Name: admin_notifications admin_notification_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_notifications ALTER COLUMN admin_notification_id SET DEFAULT nextval('public.admin_notifications_admin_notification_id_seq'::regclass);


--
-- Name: admin_otp_verifications id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_otp_verifications ALTER COLUMN id SET DEFAULT nextval('public.admin_otp_verifications_id_seq'::regclass);


--
-- Name: admins admin_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admins ALTER COLUMN admin_id SET DEFAULT nextval('public.admins_admin_id_seq'::regclass);


--
-- Name: announcements announcement_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.announcements ALTER COLUMN announcement_id SET DEFAULT nextval('public.announcements_announcement_id_seq'::regclass);


--
-- Name: announcements_content_audit audit_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.announcements_content_audit ALTER COLUMN audit_id SET DEFAULT nextval('public.announcements_content_audit_audit_id_seq'::regclass);


--
-- Name: announcements_content_blocks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.announcements_content_blocks ALTER COLUMN id SET DEFAULT nextval('public.announcements_content_blocks_id_seq'::regclass);


--
-- Name: audit_logs audit_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_logs ALTER COLUMN audit_id SET DEFAULT nextval('public.audit_logs_audit_id_seq'::regclass);


--
-- Name: barangays barangay_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.barangays ALTER COLUMN barangay_id SET DEFAULT nextval('public.barangays_barangay_id_seq'::regclass);


--
-- Name: blacklisted_students blacklist_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blacklisted_students ALTER COLUMN blacklist_id SET DEFAULT nextval('public.blacklisted_students_blacklist_id_seq'::regclass);


--
-- Name: contact_content_audit id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contact_content_audit ALTER COLUMN id SET DEFAULT nextval('public.contact_content_audit_id_seq'::regclass);


--
-- Name: contact_content_blocks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contact_content_blocks ALTER COLUMN id SET DEFAULT nextval('public.contact_content_blocks_id_seq'::regclass);


--
-- Name: distribution_file_manifest manifest_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.distribution_file_manifest ALTER COLUMN manifest_id SET DEFAULT nextval('public.distribution_file_manifest_manifest_id_seq'::regclass);


--
-- Name: distribution_snapshots snapshot_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.distribution_snapshots ALTER COLUMN snapshot_id SET DEFAULT nextval('public.distribution_snapshots_snapshot_id_seq'::regclass);


--
-- Name: distribution_student_records record_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.distribution_student_records ALTER COLUMN record_id SET DEFAULT nextval('public.distribution_student_records_record_id_seq'::regclass);


--
-- Name: distribution_student_snapshot student_snapshot_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.distribution_student_snapshot ALTER COLUMN student_snapshot_id SET DEFAULT nextval('public.distribution_student_snapshot_v2_student_snapshot_id_seq'::regclass);


--
-- Name: document_archives archive_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_archives ALTER COLUMN archive_id SET DEFAULT nextval('public.document_archives_archive_id_seq'::regclass);


--
-- Name: file_archive_log log_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.file_archive_log ALTER COLUMN log_id SET DEFAULT nextval('public.file_archive_log_log_id_seq'::regclass);


--
-- Name: grading_systems id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grading_systems ALTER COLUMN id SET DEFAULT nextval('public.grading_systems_id_seq'::regclass);


--
-- Name: header_theme_settings header_theme_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.header_theme_settings ALTER COLUMN header_theme_id SET DEFAULT nextval('public.header_theme_settings_header_theme_id_seq'::regclass);


--
-- Name: how_it_works_content_audit audit_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.how_it_works_content_audit ALTER COLUMN audit_id SET DEFAULT nextval('public.how_it_works_content_audit_audit_id_seq'::regclass);


--
-- Name: how_it_works_content_blocks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.how_it_works_content_blocks ALTER COLUMN id SET DEFAULT nextval('public.how_it_works_content_blocks_id_seq'::regclass);


--
-- Name: landing_content_audit audit_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.landing_content_audit ALTER COLUMN audit_id SET DEFAULT nextval('public.landing_content_audit_audit_id_seq'::regclass);


--
-- Name: landing_content_blocks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.landing_content_blocks ALTER COLUMN id SET DEFAULT nextval('public.landing_content_blocks_id_seq'::regclass);


--
-- Name: login_content_blocks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.login_content_blocks ALTER COLUMN id SET DEFAULT nextval('public.login_content_blocks_id_seq'::regclass);


--
-- Name: municipalities municipality_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.municipalities ALTER COLUMN municipality_id SET DEFAULT nextval('public.municipalities_municipality_id_seq'::regclass);


--
-- Name: notifications notification_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications ALTER COLUMN notification_id SET DEFAULT nextval('public.notifications_notification_id_seq'::regclass);


--
-- Name: qr_codes qr_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.qr_codes ALTER COLUMN qr_id SET DEFAULT nextval('public.qr_codes_qr_id_seq'::regclass);


--
-- Name: qr_logs log_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.qr_logs ALTER COLUMN log_id SET DEFAULT nextval('public.qr_logs_log_id_seq'::regclass);


--
-- Name: requirements_content_audit audit_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.requirements_content_audit ALTER COLUMN audit_id SET DEFAULT nextval('public.requirements_content_audit_audit_id_seq'::regclass);


--
-- Name: requirements_content_blocks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.requirements_content_blocks ALTER COLUMN id SET DEFAULT nextval('public.requirements_content_blocks_id_seq'::regclass);


--
-- Name: schedule_batches batch_config_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schedule_batches ALTER COLUMN batch_config_id SET DEFAULT nextval('public.schedule_batches_batch_config_id_seq'::regclass);


--
-- Name: schedules schedule_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schedules ALTER COLUMN schedule_id SET DEFAULT nextval('public.schedules_schedule_id_seq'::regclass);


--
-- Name: school_student_id_audit audit_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_student_id_audit ALTER COLUMN audit_id SET DEFAULT nextval('public.school_student_id_audit_audit_id_seq'::regclass);


--
-- Name: school_student_ids id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_student_ids ALTER COLUMN id SET DEFAULT nextval('public.school_student_ids_id_seq'::regclass);


--
-- Name: sidebar_theme_settings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sidebar_theme_settings ALTER COLUMN id SET DEFAULT nextval('public.sidebar_theme_settings_id_seq'::regclass);


--
-- Name: signup_slots slot_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.signup_slots ALTER COLUMN slot_id SET DEFAULT nextval('public.signup_slots_slot_id_seq'::regclass);


--
-- Name: student_login_history history_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_login_history ALTER COLUMN history_id SET DEFAULT nextval('public.student_login_history_history_id_seq'::regclass);


--
-- Name: student_notifications notification_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_notifications ALTER COLUMN notification_id SET DEFAULT nextval('public.student_notifications_notification_id_seq'::regclass);


--
-- Name: theme_settings theme_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.theme_settings ALTER COLUMN theme_id SET DEFAULT nextval('public.theme_settings_theme_id_seq'::regclass);


--
-- Name: universities university_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.universities ALTER COLUMN university_id SET DEFAULT nextval('public.universities_university_id_seq'::regclass);


--
-- Name: university_passing_policy policy_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.university_passing_policy ALTER COLUMN policy_id SET DEFAULT nextval('public.university_passing_policy_policy_id_seq'::regclass);


--
-- Name: used_schedule_dates date_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.used_schedule_dates ALTER COLUMN date_id SET DEFAULT nextval('public.used_schedule_dates_date_id_seq'::regclass);


--
-- Name: year_levels year_level_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.year_levels ALTER COLUMN year_level_id SET DEFAULT nextval('public.year_levels_year_level_id_seq'::regclass);


--
-- Name: university_passing_policy university_passing_policy_pkey; Type: CONSTRAINT; Schema: grading; Owner: -
--

ALTER TABLE ONLY grading.university_passing_policy
    ADD CONSTRAINT university_passing_policy_pkey PRIMARY KEY (policy_id);


--
-- Name: university_passing_policy university_passing_policy_university_key_is_active_key; Type: CONSTRAINT; Schema: grading; Owner: -
--

ALTER TABLE ONLY grading.university_passing_policy
    ADD CONSTRAINT university_passing_policy_university_key_is_active_key UNIQUE (university_key, is_active);


--
-- Name: about_content_audit about_content_audit_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.about_content_audit
    ADD CONSTRAINT about_content_audit_pkey PRIMARY KEY (audit_id);


--
-- Name: about_content_blocks about_content_blocks_municipality_id_block_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.about_content_blocks
    ADD CONSTRAINT about_content_blocks_municipality_id_block_key_key UNIQUE (municipality_id, block_key);


--
-- Name: about_content_blocks about_content_blocks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.about_content_blocks
    ADD CONSTRAINT about_content_blocks_pkey PRIMARY KEY (id);


--
-- Name: admin_blacklist_verifications admin_blacklist_verifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_blacklist_verifications
    ADD CONSTRAINT admin_blacklist_verifications_pkey PRIMARY KEY (id);


--
-- Name: admin_notifications admin_notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_notifications
    ADD CONSTRAINT admin_notifications_pkey PRIMARY KEY (admin_notification_id);


--
-- Name: admin_otp_verifications admin_otp_verifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_otp_verifications
    ADD CONSTRAINT admin_otp_verifications_pkey PRIMARY KEY (id);


--
-- Name: admins admins_email_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admins
    ADD CONSTRAINT admins_email_key UNIQUE (email);


--
-- Name: admins admins_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admins
    ADD CONSTRAINT admins_pkey PRIMARY KEY (admin_id);


--
-- Name: admins admins_username_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admins
    ADD CONSTRAINT admins_username_key UNIQUE (username);


--
-- Name: announcements_content_audit announcements_content_audit_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.announcements_content_audit
    ADD CONSTRAINT announcements_content_audit_pkey PRIMARY KEY (audit_id);


--
-- Name: announcements_content_blocks announcements_content_blocks_municipality_id_block_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.announcements_content_blocks
    ADD CONSTRAINT announcements_content_blocks_municipality_id_block_key_key UNIQUE (municipality_id, block_key);


--
-- Name: announcements_content_blocks announcements_content_blocks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.announcements_content_blocks
    ADD CONSTRAINT announcements_content_blocks_pkey PRIMARY KEY (id);


--
-- Name: announcements announcements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.announcements
    ADD CONSTRAINT announcements_pkey PRIMARY KEY (announcement_id);


--
-- Name: audit_logs audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_pkey PRIMARY KEY (audit_id);


--
-- Name: barangays barangays_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.barangays
    ADD CONSTRAINT barangays_pkey PRIMARY KEY (barangay_id);


--
-- Name: blacklisted_students blacklisted_students_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blacklisted_students
    ADD CONSTRAINT blacklisted_students_pkey PRIMARY KEY (blacklist_id);


--
-- Name: config config_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.config
    ADD CONSTRAINT config_pkey PRIMARY KEY (key);


--
-- Name: contact_content_audit contact_content_audit_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contact_content_audit
    ADD CONSTRAINT contact_content_audit_pkey PRIMARY KEY (id);


--
-- Name: contact_content_blocks contact_content_blocks_block_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contact_content_blocks
    ADD CONSTRAINT contact_content_blocks_block_key_key UNIQUE (block_key);


--
-- Name: contact_content_blocks contact_content_blocks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.contact_content_blocks
    ADD CONSTRAINT contact_content_blocks_pkey PRIMARY KEY (id);


--
-- Name: distribution_file_manifest distribution_file_manifest_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.distribution_file_manifest
    ADD CONSTRAINT distribution_file_manifest_pkey PRIMARY KEY (manifest_id);


--
-- Name: distribution_snapshots distribution_snapshots_distribution_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.distribution_snapshots
    ADD CONSTRAINT distribution_snapshots_distribution_id_key UNIQUE (distribution_id);


--
-- Name: distribution_snapshots distribution_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.distribution_snapshots
    ADD CONSTRAINT distribution_snapshots_pkey PRIMARY KEY (snapshot_id);


--
-- Name: distribution_student_records distribution_student_records_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.distribution_student_records
    ADD CONSTRAINT distribution_student_records_pkey PRIMARY KEY (record_id);


--
-- Name: distribution_student_records distribution_student_records_snapshot_id_student_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.distribution_student_records
    ADD CONSTRAINT distribution_student_records_snapshot_id_student_id_key UNIQUE (snapshot_id, student_id);


--
-- Name: distribution_student_snapshot distribution_student_snapshot_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.distribution_student_snapshot
    ADD CONSTRAINT distribution_student_snapshot_unique UNIQUE (distribution_id, student_id);


--
-- Name: distribution_student_snapshot distribution_student_snapshot_v2_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.distribution_student_snapshot
    ADD CONSTRAINT distribution_student_snapshot_v2_pkey PRIMARY KEY (student_snapshot_id);


--
-- Name: document_archives document_archives_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_archives
    ADD CONSTRAINT document_archives_pkey PRIMARY KEY (archive_id);


--
-- Name: documents documents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_pkey PRIMARY KEY (document_id);


--
-- Name: file_archive_log file_archive_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.file_archive_log
    ADD CONSTRAINT file_archive_log_pkey PRIMARY KEY (log_id);


--
-- Name: grading_systems grading_systems_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grading_systems
    ADD CONSTRAINT grading_systems_pkey PRIMARY KEY (id);


--
-- Name: grading_systems grading_systems_system_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grading_systems
    ADD CONSTRAINT grading_systems_system_name_key UNIQUE (system_name);


--
-- Name: header_theme_settings header_theme_settings_municipality_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.header_theme_settings
    ADD CONSTRAINT header_theme_settings_municipality_id_key UNIQUE (municipality_id);


--
-- Name: header_theme_settings header_theme_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.header_theme_settings
    ADD CONSTRAINT header_theme_settings_pkey PRIMARY KEY (header_theme_id);


--
-- Name: how_it_works_content_audit how_it_works_content_audit_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.how_it_works_content_audit
    ADD CONSTRAINT how_it_works_content_audit_pkey PRIMARY KEY (audit_id);


--
-- Name: how_it_works_content_blocks how_it_works_content_blocks_municipality_id_block_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.how_it_works_content_blocks
    ADD CONSTRAINT how_it_works_content_blocks_municipality_id_block_key_key UNIQUE (municipality_id, block_key);


--
-- Name: how_it_works_content_blocks how_it_works_content_blocks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.how_it_works_content_blocks
    ADD CONSTRAINT how_it_works_content_blocks_pkey PRIMARY KEY (id);


--
-- Name: landing_content_audit landing_content_audit_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.landing_content_audit
    ADD CONSTRAINT landing_content_audit_pkey PRIMARY KEY (audit_id);


--
-- Name: landing_content_blocks landing_content_blocks_municipality_id_block_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.landing_content_blocks
    ADD CONSTRAINT landing_content_blocks_municipality_id_block_key_key UNIQUE (municipality_id, block_key);


--
-- Name: landing_content_blocks landing_content_blocks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.landing_content_blocks
    ADD CONSTRAINT landing_content_blocks_pkey PRIMARY KEY (id);


--
-- Name: login_content_blocks login_content_blocks_municipality_id_block_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.login_content_blocks
    ADD CONSTRAINT login_content_blocks_municipality_id_block_key_key UNIQUE (municipality_id, block_key);


--
-- Name: login_content_blocks login_content_blocks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.login_content_blocks
    ADD CONSTRAINT login_content_blocks_pkey PRIMARY KEY (id);


--
-- Name: municipalities municipalities_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.municipalities
    ADD CONSTRAINT municipalities_pkey PRIMARY KEY (municipality_id);


--
-- Name: notifications notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (notification_id);


--
-- Name: qr_codes qr_codes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.qr_codes
    ADD CONSTRAINT qr_codes_pkey PRIMARY KEY (qr_id);


--
-- Name: qr_logs qr_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.qr_logs
    ADD CONSTRAINT qr_logs_pkey PRIMARY KEY (log_id);


--
-- Name: requirements_content_audit requirements_content_audit_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.requirements_content_audit
    ADD CONSTRAINT requirements_content_audit_pkey PRIMARY KEY (audit_id);


--
-- Name: requirements_content_blocks requirements_content_blocks_municipality_id_block_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.requirements_content_blocks
    ADD CONSTRAINT requirements_content_blocks_municipality_id_block_key_key UNIQUE (municipality_id, block_key);


--
-- Name: requirements_content_blocks requirements_content_blocks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.requirements_content_blocks
    ADD CONSTRAINT requirements_content_blocks_pkey PRIMARY KEY (id);


--
-- Name: schedule_batches schedule_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schedule_batches
    ADD CONSTRAINT schedule_batches_pkey PRIMARY KEY (batch_config_id);


--
-- Name: schedule_batches schedule_batches_schedule_date_batch_number_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schedule_batches
    ADD CONSTRAINT schedule_batches_schedule_date_batch_number_key UNIQUE (schedule_date, batch_number);


--
-- Name: schedules schedules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schedules
    ADD CONSTRAINT schedules_pkey PRIMARY KEY (schedule_id);


--
-- Name: school_student_id_audit school_student_id_audit_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_student_id_audit
    ADD CONSTRAINT school_student_id_audit_pkey PRIMARY KEY (audit_id);


--
-- Name: school_student_ids school_student_ids_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_student_ids
    ADD CONSTRAINT school_student_ids_pkey PRIMARY KEY (id);


--
-- Name: sidebar_theme_settings sidebar_theme_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sidebar_theme_settings
    ADD CONSTRAINT sidebar_theme_settings_pkey PRIMARY KEY (id);


--
-- Name: signup_slots signup_slots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.signup_slots
    ADD CONSTRAINT signup_slots_pkey PRIMARY KEY (slot_id);


--
-- Name: student_active_sessions student_active_sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_active_sessions
    ADD CONSTRAINT student_active_sessions_pkey PRIMARY KEY (session_id);


--
-- Name: student_login_history student_login_history_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_login_history
    ADD CONSTRAINT student_login_history_pkey PRIMARY KEY (history_id);


--
-- Name: student_notifications student_notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_notifications
    ADD CONSTRAINT student_notifications_pkey PRIMARY KEY (notification_id);


--
-- Name: students students_email_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_email_key UNIQUE (email);


--
-- Name: students students_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_pkey PRIMARY KEY (student_id);


--
-- Name: students students_unique_student_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_unique_student_id_key UNIQUE (student_id);


--
-- Name: theme_settings theme_settings_municipality_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.theme_settings
    ADD CONSTRAINT theme_settings_municipality_id_key UNIQUE (municipality_id);


--
-- Name: theme_settings theme_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.theme_settings
    ADD CONSTRAINT theme_settings_pkey PRIMARY KEY (theme_id);


--
-- Name: sidebar_theme_settings uniq_sidebar_muni; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sidebar_theme_settings
    ADD CONSTRAINT uniq_sidebar_muni UNIQUE (municipality_id);


--
-- Name: school_student_ids unique_school_student_per_university; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_student_ids
    ADD CONSTRAINT unique_school_student_per_university UNIQUE (university_id, school_student_id);


--
-- Name: universities universities_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.universities
    ADD CONSTRAINT universities_code_key UNIQUE (code);


--
-- Name: universities universities_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.universities
    ADD CONSTRAINT universities_pkey PRIMARY KEY (university_id);


--
-- Name: university_passing_policy university_passing_policy_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.university_passing_policy
    ADD CONSTRAINT university_passing_policy_pkey PRIMARY KEY (policy_id);


--
-- Name: university_passing_policy university_passing_policy_university_key_is_active_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.university_passing_policy
    ADD CONSTRAINT university_passing_policy_university_key_is_active_key UNIQUE (university_key, is_active);


--
-- Name: used_schedule_dates used_schedule_dates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.used_schedule_dates
    ADD CONSTRAINT used_schedule_dates_pkey PRIMARY KEY (date_id);


--
-- Name: used_schedule_dates used_schedule_dates_schedule_date_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.used_schedule_dates
    ADD CONSTRAINT used_schedule_dates_schedule_date_key UNIQUE (schedule_date);


--
-- Name: year_levels year_levels_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.year_levels
    ADD CONSTRAINT year_levels_code_key UNIQUE (code);


--
-- Name: year_levels year_levels_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.year_levels
    ADD CONSTRAINT year_levels_pkey PRIMARY KEY (year_level_id);


--
-- Name: idx_university_policy_active; Type: INDEX; Schema: grading; Owner: -
--

CREATE INDEX idx_university_policy_active ON grading.university_passing_policy USING btree (university_key, is_active);


--
-- Name: announcements_content_blocks_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX announcements_content_blocks_unique ON public.announcements_content_blocks USING btree (municipality_id, block_key);


--
-- Name: contact_content_blocks_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX contact_content_blocks_unique ON public.contact_content_blocks USING btree (municipality_id, block_key);


--
-- Name: idx_active_sessions_activity; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_active_sessions_activity ON public.student_active_sessions USING btree (last_activity);


--
-- Name: idx_active_sessions_student; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_active_sessions_student ON public.student_active_sessions USING btree (student_id);


--
-- Name: idx_admin_blacklist_verifications_admin_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_admin_blacklist_verifications_admin_id ON public.admin_blacklist_verifications USING btree (admin_id);


--
-- Name: idx_admin_blacklist_verifications_expires; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_admin_blacklist_verifications_expires ON public.admin_blacklist_verifications USING btree (expires_at);


--
-- Name: idx_admin_notifications_is_read; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_admin_notifications_is_read ON public.admin_notifications USING btree (is_read);


--
-- Name: idx_admin_otp_admin_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_admin_otp_admin_id ON public.admin_otp_verifications USING btree (admin_id);


--
-- Name: idx_admin_otp_admin_purpose; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_admin_otp_admin_purpose ON public.admin_otp_verifications USING btree (admin_id, purpose);


--
-- Name: idx_admin_otp_expires; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_admin_otp_expires ON public.admin_otp_verifications USING btree (expires_at);


--
-- Name: idx_announcements_content_audit_muni_key; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_announcements_content_audit_muni_key ON public.announcements_content_audit USING btree (municipality_id, block_key);


--
-- Name: idx_announcements_content_blocks_muni_key; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_announcements_content_blocks_muni_key ON public.announcements_content_blocks USING btree (municipality_id, block_key);


--
-- Name: idx_audit_affected; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_affected ON public.audit_logs USING btree (affected_table, affected_record_id);


--
-- Name: idx_audit_category; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_category ON public.audit_logs USING btree (event_category);


--
-- Name: idx_audit_category_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_category_date ON public.audit_logs USING btree (event_category, created_at DESC);


--
-- Name: idx_audit_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_created_at ON public.audit_logs USING btree (created_at DESC);


--
-- Name: idx_audit_event_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_event_type ON public.audit_logs USING btree (event_type);


--
-- Name: idx_audit_ip; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_ip ON public.audit_logs USING btree (ip_address);


--
-- Name: idx_audit_performed_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_performed_at ON public.school_student_id_audit USING btree (performed_at DESC);


--
-- Name: idx_audit_school_student_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_school_student_id ON public.school_student_id_audit USING btree (school_student_id);


--
-- Name: idx_audit_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_status ON public.audit_logs USING btree (status);


--
-- Name: idx_audit_user; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_user ON public.audit_logs USING btree (user_id, user_type);


--
-- Name: idx_audit_user_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_user_date ON public.audit_logs USING btree (user_type, created_at DESC);


--
-- Name: idx_blacklisted_students_blacklisted_by; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_blacklisted_students_blacklisted_by ON public.blacklisted_students USING btree (blacklisted_by);


--
-- Name: idx_dist_student_records_scanned_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_dist_student_records_scanned_at ON public.distribution_student_records USING btree (scanned_at);


--
-- Name: idx_dist_student_records_snapshot; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_dist_student_records_snapshot ON public.distribution_student_records USING btree (snapshot_id);


--
-- Name: idx_dist_student_records_student; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_dist_student_records_student ON public.distribution_student_records USING btree (student_id);


--
-- Name: idx_distribution_snapshots_academic; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_distribution_snapshots_academic ON public.distribution_snapshots USING btree (academic_year, semester);


--
-- Name: idx_distribution_snapshots_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_distribution_snapshots_date ON public.distribution_snapshots USING btree (distribution_date);


--
-- Name: idx_distribution_snapshots_dist_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_distribution_snapshots_dist_id ON public.distribution_snapshots USING btree (distribution_id);


--
-- Name: idx_distribution_snapshots_finalized_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_distribution_snapshots_finalized_at ON public.distribution_snapshots USING btree (finalized_at DESC);


--
-- Name: idx_distribution_snapshots_finalized_by; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_distribution_snapshots_finalized_by ON public.distribution_snapshots USING btree (finalized_by);


--
-- Name: idx_distribution_snapshots_municipality; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_distribution_snapshots_municipality ON public.distribution_snapshots USING btree (municipality_id);


--
-- Name: idx_document_archives_distribution; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_document_archives_distribution ON public.document_archives USING btree (distribution_snapshot_id);


--
-- Name: idx_document_archives_student_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_document_archives_student_id ON public.document_archives USING btree (student_id);


--
-- Name: idx_documents_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_documents_status ON public.documents USING btree (status);


--
-- Name: idx_documents_student_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_documents_student_id ON public.documents USING btree (student_id);


--
-- Name: idx_documents_student_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_documents_student_type ON public.documents USING btree (student_id, document_type_code);


--
-- Name: idx_documents_type_code; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_documents_type_code ON public.documents USING btree (document_type_code);


--
-- Name: idx_documents_type_name; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_documents_type_name ON public.documents USING btree (document_type_name);


--
-- Name: idx_documents_upload_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_documents_upload_date ON public.documents USING btree (upload_date);


--
-- Name: idx_documents_verification_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_documents_verification_status ON public.documents USING btree (verification_status);


--
-- Name: idx_dsr_distribution_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_dsr_distribution_id ON public.distribution_student_records USING btree (distribution_id);


--
-- Name: idx_dsr_scanned_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_dsr_scanned_at ON public.distribution_student_records USING btree (scanned_at DESC);


--
-- Name: idx_dsr_scanned_by; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_dsr_scanned_by ON public.distribution_student_records USING btree (scanned_by);


--
-- Name: idx_dsr_snapshot; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_dsr_snapshot ON public.distribution_student_records USING btree (snapshot_id);


--
-- Name: idx_dsr_student; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_dsr_student ON public.distribution_student_records USING btree (student_id);


--
-- Name: idx_dss_dist_student; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_dss_dist_student ON public.distribution_student_snapshot USING btree (distribution_id, student_id);


--
-- Name: idx_dss_distribution; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_dss_distribution ON public.distribution_student_snapshot USING btree (distribution_id);


--
-- Name: idx_dss_student; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_dss_student ON public.distribution_student_snapshot USING btree (student_id);


--
-- Name: idx_file_archive_log_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_file_archive_log_date ON public.file_archive_log USING btree (performed_at);


--
-- Name: idx_file_archive_log_operation; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_file_archive_log_operation ON public.file_archive_log USING btree (operation);


--
-- Name: idx_file_archive_log_student; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_file_archive_log_student ON public.file_archive_log USING btree (student_id);


--
-- Name: idx_how_it_works_content_blocks_muni_key; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_how_it_works_content_blocks_muni_key ON public.how_it_works_content_blocks USING btree (municipality_id, block_key);


--
-- Name: idx_landing_content_audit_muni_key; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_landing_content_audit_muni_key ON public.landing_content_audit USING btree (municipality_id, block_key);


--
-- Name: idx_landing_content_blocks_muni_key; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_landing_content_blocks_muni_key ON public.landing_content_blocks USING btree (municipality_id, block_key);


--
-- Name: idx_login_content_blocks_muni_key; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_login_content_blocks_muni_key ON public.login_content_blocks USING btree (municipality_id, block_key);


--
-- Name: idx_login_history_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_login_history_status ON public.student_login_history USING btree (status);


--
-- Name: idx_login_history_student; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_login_history_student ON public.student_login_history USING btree (student_id);


--
-- Name: idx_login_history_time; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_login_history_time ON public.student_login_history USING btree (login_time DESC);


--
-- Name: idx_manifest_doc_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_manifest_doc_type ON public.distribution_file_manifest USING btree (document_type_code);


--
-- Name: idx_manifest_hash; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_manifest_hash ON public.distribution_file_manifest USING btree (file_hash);


--
-- Name: idx_manifest_snapshot; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_manifest_snapshot ON public.distribution_file_manifest USING btree (snapshot_id);


--
-- Name: idx_manifest_student; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_manifest_student ON public.distribution_file_manifest USING btree (student_id);


--
-- Name: idx_notifications_is_read; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_notifications_is_read ON public.notifications USING btree (is_read);


--
-- Name: idx_notifications_student_priority; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_notifications_student_priority ON public.notifications USING btree (student_id, is_priority, viewed_at);


--
-- Name: idx_notifications_student_unread; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_notifications_student_unread ON public.notifications USING btree (student_id, is_read, created_at DESC);


--
-- Name: idx_requirements_content_audit_muni_key; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_requirements_content_audit_muni_key ON public.requirements_content_audit USING btree (municipality_id, block_key);


--
-- Name: idx_requirements_content_blocks_muni_key; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_requirements_content_blocks_muni_key ON public.requirements_content_blocks USING btree (municipality_id, block_key);


--
-- Name: idx_schedule_batches_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_schedule_batches_date ON public.schedule_batches USING btree (schedule_date);


--
-- Name: idx_schedule_batches_date_batch; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_schedule_batches_date_batch ON public.schedule_batches USING btree (schedule_date, batch_number);


--
-- Name: idx_school_student_ids_lookup; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_school_student_ids_lookup ON public.school_student_ids USING btree (university_id, school_student_id);


--
-- Name: idx_school_student_ids_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_school_student_ids_status ON public.school_student_ids USING btree (status);


--
-- Name: idx_school_student_ids_university; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_school_student_ids_university ON public.school_student_ids USING btree (university_id);


--
-- Name: idx_student_notifications_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_student_notifications_created_at ON public.student_notifications USING btree (created_at DESC);


--
-- Name: idx_student_notifications_is_read; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_student_notifications_is_read ON public.student_notifications USING btree (is_read);


--
-- Name: idx_student_notifications_priority; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_student_notifications_priority ON public.student_notifications USING btree (is_priority, student_id) WHERE (is_priority = true);


--
-- Name: idx_student_notifications_student_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_student_notifications_student_id ON public.student_notifications USING btree (student_id);


--
-- Name: idx_students_active_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_students_active_status ON public.students USING btree (is_archived, status) WHERE (is_archived = false);


--
-- Name: idx_students_archived_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_students_archived_at ON public.students USING btree (archived_at);


--
-- Name: idx_students_archived_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_students_archived_date ON public.students USING btree (is_archived, archived_at) WHERE (is_archived = true);


--
-- Name: idx_students_confidence_score; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_students_confidence_score ON public.students USING btree (confidence_score DESC);


--
-- Name: idx_students_extension_name; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_students_extension_name ON public.students USING btree (extension_name);


--
-- Name: idx_students_graduation_year; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_students_graduation_year ON public.students USING btree (expected_graduation_year);


--
-- Name: idx_students_is_archived; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_students_is_archived ON public.students USING btree (is_archived);


--
-- Name: idx_students_last_login; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_students_last_login ON public.students USING btree (last_login);


--
-- Name: idx_students_school_student_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_students_school_student_id ON public.students USING btree (school_student_id) WHERE ((school_student_id IS NOT NULL) AND ((school_student_id)::text <> ''::text));


--
-- Name: idx_students_slot_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_students_slot_id ON public.students USING btree (slot_id);


--
-- Name: idx_students_year_level; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_students_year_level ON public.students USING btree (year_level_id);


--
-- Name: idx_unique_finalized_distribution; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_unique_finalized_distribution ON public.distribution_snapshots USING btree (academic_year, semester) WHERE (finalized_at IS NOT NULL);


--
-- Name: INDEX idx_unique_finalized_distribution; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON INDEX public.idx_unique_finalized_distribution IS 'Ensures only one finalized distribution can exist per academic year/semester combination. Allows multiple unfinalized drafts.';


--
-- Name: idx_unique_school_student_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_unique_school_student_id ON public.students USING btree (university_id, school_student_id) WHERE ((school_student_id IS NOT NULL) AND ((school_student_id)::text <> ''::text));


--
-- Name: idx_university_policy_active; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_university_policy_active ON public.university_passing_policy USING btree (university_key, is_active);


--
-- Name: idx_used_schedule_dates_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_used_schedule_dates_date ON public.used_schedule_dates USING btree (schedule_date);


--
-- Name: ux_municipalities_name; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_municipalities_name ON public.municipalities USING btree (name);


--
-- Name: ux_municipalities_slug; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ux_municipalities_slug ON public.municipalities USING btree (slug);


--
-- Name: v_distribution_history _RETURN; Type: RULE; Schema: public; Owner: -
--

CREATE OR REPLACE VIEW public.v_distribution_history AS
 SELECT ds.snapshot_id,
    ds.distribution_id,
    ds.academic_year,
    ds.semester,
    ds.distribution_date,
    ds.location,
    ds.finalized_at,
    ds.finalized_by,
    a.username AS finalized_by_username,
    ds.total_students_count,
    count(DISTINCT dsr.student_id) AS actual_students_distributed,
    ds.files_compressed,
    ds.compression_date,
    ds.total_files_count,
    ds.compressed_size,
    ds.compression_ratio,
    ds.archive_filename,
    ds.notes
   FROM ((public.distribution_snapshots ds
     LEFT JOIN public.distribution_student_records dsr ON ((ds.snapshot_id = dsr.snapshot_id)))
     LEFT JOIN public.admins a ON ((ds.finalized_by = a.admin_id)))
  GROUP BY ds.snapshot_id, a.username
  ORDER BY ds.finalized_at DESC NULLS LAST;


--
-- Name: university_passing_policy update_grading_policy_updated_at; Type: TRIGGER; Schema: grading; Owner: -
--

CREATE TRIGGER update_grading_policy_updated_at BEFORE UPDATE ON grading.university_passing_policy FOR EACH ROW EXECUTE FUNCTION grading.update_updated_at_column();


--
-- Name: students trigger_set_document_upload_needs; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trigger_set_document_upload_needs BEFORE INSERT OR UPDATE ON public.students FOR EACH ROW EXECUTE FUNCTION public.set_document_upload_needs();


--
-- Name: students trigger_track_school_student_id; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trigger_track_school_student_id AFTER INSERT ON public.students FOR EACH ROW EXECUTE FUNCTION public.track_school_student_id();


--
-- Name: municipalities update_municipalities_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER update_municipalities_updated_at BEFORE UPDATE ON public.municipalities FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: university_passing_policy university_passing_policy_university_key_fkey; Type: FK CONSTRAINT; Schema: grading; Owner: -
--

ALTER TABLE ONLY grading.university_passing_policy
    ADD CONSTRAINT university_passing_policy_university_key_fkey FOREIGN KEY (university_key) REFERENCES public.universities(code);


--
-- Name: admin_blacklist_verifications admin_blacklist_verifications_admin_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_blacklist_verifications
    ADD CONSTRAINT admin_blacklist_verifications_admin_id_fkey FOREIGN KEY (admin_id) REFERENCES public.admins(admin_id) ON DELETE CASCADE;


--
-- Name: admin_blacklist_verifications admin_blacklist_verifications_student_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_blacklist_verifications
    ADD CONSTRAINT admin_blacklist_verifications_student_id_fkey FOREIGN KEY (student_id) REFERENCES public.students(student_id);


--
-- Name: admin_otp_verifications admin_otp_verifications_admin_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_otp_verifications
    ADD CONSTRAINT admin_otp_verifications_admin_id_fkey FOREIGN KEY (admin_id) REFERENCES public.admins(admin_id) ON DELETE CASCADE;


--
-- Name: admins admins_municipality_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admins
    ADD CONSTRAINT admins_municipality_id_fkey FOREIGN KEY (municipality_id) REFERENCES public.municipalities(municipality_id);


--
-- Name: barangays barangays_municipality_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.barangays
    ADD CONSTRAINT barangays_municipality_id_fkey FOREIGN KEY (municipality_id) REFERENCES public.municipalities(municipality_id);


--
-- Name: blacklisted_students blacklisted_students_blacklisted_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blacklisted_students
    ADD CONSTRAINT blacklisted_students_blacklisted_by_fkey FOREIGN KEY (blacklisted_by) REFERENCES public.admins(admin_id);


--
-- Name: blacklisted_students blacklisted_students_student_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blacklisted_students
    ADD CONSTRAINT blacklisted_students_student_id_fkey FOREIGN KEY (student_id) REFERENCES public.students(student_id);


--
-- Name: distribution_file_manifest distribution_file_manifest_snapshot_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.distribution_file_manifest
    ADD CONSTRAINT distribution_file_manifest_snapshot_id_fkey FOREIGN KEY (snapshot_id) REFERENCES public.distribution_snapshots(snapshot_id) ON DELETE CASCADE;


--
-- Name: distribution_snapshots distribution_snapshots_finalized_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.distribution_snapshots
    ADD CONSTRAINT distribution_snapshots_finalized_by_fkey FOREIGN KEY (finalized_by) REFERENCES public.admins(admin_id);


--
-- Name: distribution_snapshots distribution_snapshots_municipality_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.distribution_snapshots
    ADD CONSTRAINT distribution_snapshots_municipality_id_fkey FOREIGN KEY (municipality_id) REFERENCES public.municipalities(municipality_id);


--
-- Name: distribution_student_records distribution_student_records_scanned_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.distribution_student_records
    ADD CONSTRAINT distribution_student_records_scanned_by_fkey FOREIGN KEY (scanned_by) REFERENCES public.admins(admin_id);


--
-- Name: distribution_student_records distribution_student_records_snapshot_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.distribution_student_records
    ADD CONSTRAINT distribution_student_records_snapshot_id_fkey FOREIGN KEY (snapshot_id) REFERENCES public.distribution_snapshots(snapshot_id) ON DELETE CASCADE;


--
-- Name: distribution_student_records distribution_student_records_student_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.distribution_student_records
    ADD CONSTRAINT distribution_student_records_student_id_fkey FOREIGN KEY (student_id) REFERENCES public.students(student_id) ON DELETE CASCADE;


--
-- Name: distribution_student_snapshot distribution_student_snapshot_v2_student_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.distribution_student_snapshot
    ADD CONSTRAINT distribution_student_snapshot_v2_student_id_fkey FOREIGN KEY (student_id) REFERENCES public.students(student_id) ON DELETE CASCADE;


--
-- Name: document_archives document_archives_distribution_snapshot_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.document_archives
    ADD CONSTRAINT document_archives_distribution_snapshot_id_fkey FOREIGN KEY (distribution_snapshot_id) REFERENCES public.distribution_snapshots(snapshot_id);


--
-- Name: documents documents_approved_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_approved_by_fkey FOREIGN KEY (approved_by) REFERENCES public.admins(admin_id);


--
-- Name: documents documents_student_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_student_id_fkey FOREIGN KEY (student_id) REFERENCES public.students(student_id) ON DELETE CASCADE;


--
-- Name: file_archive_log fk_admin_archive_log; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.file_archive_log
    ADD CONSTRAINT fk_admin_archive_log FOREIGN KEY (performed_by) REFERENCES public.admins(admin_id) ON DELETE SET NULL;


--
-- Name: distribution_student_snapshot fk_distribution_id; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.distribution_student_snapshot
    ADD CONSTRAINT fk_distribution_id FOREIGN KEY (distribution_id) REFERENCES public.distribution_snapshots(distribution_id) ON DELETE CASCADE;


--
-- Name: distribution_student_records fk_dsr_distribution_id; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.distribution_student_records
    ADD CONSTRAINT fk_dsr_distribution_id FOREIGN KEY (distribution_id) REFERENCES public.distribution_snapshots(distribution_id) ON DELETE CASCADE;


--
-- Name: student_notifications fk_student; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_notifications
    ADD CONSTRAINT fk_student FOREIGN KEY (student_id) REFERENCES public.students(student_id) ON DELETE CASCADE;


--
-- Name: file_archive_log fk_student_archive_log; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.file_archive_log
    ADD CONSTRAINT fk_student_archive_log FOREIGN KEY (student_id) REFERENCES public.students(student_id) ON DELETE SET NULL;


--
-- Name: students fk_students_last_distribution; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT fk_students_last_distribution FOREIGN KEY (last_distribution_snapshot_id) REFERENCES public.distribution_snapshots(snapshot_id);


--
-- Name: qr_codes qr_codes_student_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.qr_codes
    ADD CONSTRAINT qr_codes_student_id_fkey FOREIGN KEY (student_id) REFERENCES public.students(student_id);


--
-- Name: schedule_batches schedule_batches_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schedule_batches
    ADD CONSTRAINT schedule_batches_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.admins(admin_id);


--
-- Name: school_student_ids school_student_ids_student_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_student_ids
    ADD CONSTRAINT school_student_ids_student_id_fkey FOREIGN KEY (student_id) REFERENCES public.students(student_id) ON DELETE CASCADE;


--
-- Name: school_student_ids school_student_ids_university_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.school_student_ids
    ADD CONSTRAINT school_student_ids_university_id_fkey FOREIGN KEY (university_id) REFERENCES public.universities(university_id) ON DELETE CASCADE;


--
-- Name: signup_slots signup_slots_municipality_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.signup_slots
    ADD CONSTRAINT signup_slots_municipality_id_fkey FOREIGN KEY (municipality_id) REFERENCES public.municipalities(municipality_id);


--
-- Name: student_active_sessions student_active_sessions_student_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_active_sessions
    ADD CONSTRAINT student_active_sessions_student_id_fkey FOREIGN KEY (student_id) REFERENCES public.students(student_id) ON DELETE CASCADE;


--
-- Name: student_login_history student_login_history_student_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_login_history
    ADD CONSTRAINT student_login_history_student_id_fkey FOREIGN KEY (student_id) REFERENCES public.students(student_id) ON DELETE CASCADE;


--
-- Name: students students_archived_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_archived_by_fkey FOREIGN KEY (archived_by) REFERENCES public.admins(admin_id);


--
-- Name: students students_barangay_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_barangay_id_fkey FOREIGN KEY (barangay_id) REFERENCES public.barangays(barangay_id);


--
-- Name: students students_municipality_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_municipality_id_fkey FOREIGN KEY (municipality_id) REFERENCES public.municipalities(municipality_id);


--
-- Name: students students_slot_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_slot_id_fkey FOREIGN KEY (slot_id) REFERENCES public.signup_slots(slot_id);


--
-- Name: students students_university_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_university_id_fkey FOREIGN KEY (university_id) REFERENCES public.universities(university_id);


--
-- Name: students students_year_level_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_year_level_id_fkey FOREIGN KEY (year_level_id) REFERENCES public.year_levels(year_level_id);


--
-- Name: university_passing_policy university_passing_policy_university_key_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.university_passing_policy
    ADD CONSTRAINT university_passing_policy_university_key_fkey FOREIGN KEY (university_key) REFERENCES public.universities(code);


--
-- Name: used_schedule_dates used_schedule_dates_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.used_schedule_dates
    ADD CONSTRAINT used_schedule_dates_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.admins(admin_id);


--
-- PostgreSQL database dump complete
--

