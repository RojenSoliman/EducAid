--
-- PostgreSQL database dump
--

-- Dumped from database version 17.5
-- Dumped by pg_dump version 17.5

-- Started on 2025-09-29 10:33:59

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'SQL_ASCII';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- TOC entry 289 (class 1255 OID 33645)
-- Name: calculate_confidence_score(integer); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.calculate_confidence_score(student_id_param integer) RETURNS numeric
    LANGUAGE plpgsql
    AS $$
DECLARE
    score DECIMAL(5,2) := 0.00;
    doc_count INT := 0;
    temp_score DECIMAL(5,2);
BEGIN
    -- Personal information completeness (30 points)
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
        THEN 30.00 ELSE 0.00 END
    INTO temp_score
    FROM students 
    WHERE student_id = student_id_param;
    
    score := score + temp_score;
    
    -- Document uploads (40 points total - 10 points each for 3 documents + 10 points from enrollment_forms table)
    -- Check enrollment assessment form from enrollment_forms table (10 points)
    SELECT COUNT(*) INTO doc_count
    FROM enrollment_forms
    WHERE student_id = student_id_param;
    
    score := score + (doc_count * 10);
    
    -- Check other documents (30 points - 15 each for letter_to_mayor and certificate_of_indigency)
    SELECT COUNT(*) INTO doc_count
    FROM documents d
    WHERE d.student_id = student_id_param 
    AND d.type IN ('certificate_of_indigency', 'letter_to_mayor');
    
    score := score + (doc_count * 15);
    
    -- OCR confidence (20 points) - average of all document OCR confidences
    -- Include EAF documents from documents table if they exist
    SELECT COALESCE(AVG(ocr_confidence), 75.00) INTO temp_score
    FROM documents d
    WHERE d.student_id = student_id_param 
    AND d.ocr_confidence > 0
    AND d.type IN ('eaf', 'certificate_of_indigency', 'letter_to_mayor');
    
    score := score + (temp_score * 0.20);
    
    -- Email verification (10 points)
    SELECT 
        CASE WHEN status != 'under_registration' THEN 10.00 ELSE 0.00 END
    INTO temp_score
    FROM students 
    WHERE student_id = student_id_param;
    
    score := score + temp_score;
    
    -- Ensure score is between 0 and 100
    score := GREATEST(0.00, LEAST(100.00, score));
    
    RETURN score;
END;
$$;


ALTER FUNCTION public.calculate_confidence_score(student_id_param integer) OWNER TO postgres;

--
-- TOC entry 276 (class 1255 OID 33676)
-- Name: calculate_confidence_score(text); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.calculate_confidence_score(student_id_param text) RETURNS numeric
    LANGUAGE plpgsql
    AS $$
    DECLARE
        score DECIMAL(5,2) := 0.00;
        doc_count INT := 0;
        total_docs INT := 0;
        avg_ocr_confidence DECIMAL(5,2) := 0.00;
        temp_score DECIMAL(5,2);
    BEGIN
        -- Base score for having all required personal information (30 points)
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
            THEN 30.00 ELSE 0.00 END
        INTO temp_score
        FROM students 
        WHERE student_id = student_id_param;
        
        score := score + temp_score;
        
        -- Document upload score (40 points)
        SELECT COUNT(*) INTO doc_count
        FROM documents d
        WHERE d.student_id = student_id_param 
        AND d.type IN ('eaf', 'certificate_of_indigency', 'letter_to_mayor', 'id_picture');
        
        -- Also check enrollment_forms table
        SELECT COUNT(*) INTO total_docs
        FROM enrollment_forms ef
        WHERE ef.student_id = student_id_param;
        
        doc_count := doc_count + total_docs;
        score := score + LEAST(doc_count * 10.00, 40.00);
        
        -- OCR confidence score (20 points)
        SELECT COALESCE(AVG(ocr_confidence), 0.00) INTO avg_ocr_confidence
        FROM documents d
        WHERE d.student_id = student_id_param 
        AND d.ocr_confidence > 0;
        
        score := score + (avg_ocr_confidence * 0.20);
        
        -- Email verification bonus (10 points)
        SELECT 
            CASE WHEN status != 'under_registration' THEN 10.00 ELSE 0.00 END
        INTO temp_score
        FROM students 
        WHERE student_id = student_id_param;
        
        score := score + temp_score;
        
        -- Ensure score is between 0 and 100
        score := GREATEST(0.00, LEAST(100.00, score));
        
        RETURN score;
    END;
    $$;


ALTER FUNCTION public.calculate_confidence_score(student_id_param text) OWNER TO postgres;

--
-- TOC entry 288 (class 1255 OID 33646)
-- Name: get_confidence_level(numeric); Type: FUNCTION; Schema: public; Owner: postgres
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


ALTER FUNCTION public.get_confidence_level(score numeric) OWNER TO postgres;

--
-- TOC entry 275 (class 1255 OID 33601)
-- Name: update_updated_at_column(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.update_updated_at_column() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$;


ALTER FUNCTION public.update_updated_at_column() OWNER TO postgres;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- TOC entry 266 (class 1259 OID 33420)
-- Name: admin_blacklist_verifications; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.admin_blacklist_verifications OWNER TO postgres;

--
-- TOC entry 265 (class 1259 OID 33419)
-- Name: admin_blacklist_verifications_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.admin_blacklist_verifications_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.admin_blacklist_verifications_id_seq OWNER TO postgres;

--
-- TOC entry 5265 (class 0 OID 0)
-- Dependencies: 265
-- Name: admin_blacklist_verifications_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.admin_blacklist_verifications_id_seq OWNED BY public.admin_blacklist_verifications.id;


--
-- TOC entry 240 (class 1259 OID 25022)
-- Name: admin_notifications; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.admin_notifications (
    admin_notification_id integer NOT NULL,
    message text NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    is_read boolean DEFAULT false
);


ALTER TABLE public.admin_notifications OWNER TO postgres;

--
-- TOC entry 239 (class 1259 OID 25021)
-- Name: admin_notifications_admin_notification_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.admin_notifications_admin_notification_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.admin_notifications_admin_notification_id_seq OWNER TO postgres;

--
-- TOC entry 5267 (class 0 OID 0)
-- Dependencies: 239
-- Name: admin_notifications_admin_notification_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.admin_notifications_admin_notification_id_seq OWNED BY public.admin_notifications.admin_notification_id;


--
-- TOC entry 256 (class 1259 OID 33317)
-- Name: admin_otp_verifications; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.admin_otp_verifications OWNER TO postgres;

--
-- TOC entry 255 (class 1259 OID 33316)
-- Name: admin_otp_verifications_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.admin_otp_verifications_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.admin_otp_verifications_id_seq OWNER TO postgres;

--
-- TOC entry 5268 (class 0 OID 0)
-- Dependencies: 255
-- Name: admin_otp_verifications_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.admin_otp_verifications_id_seq OWNED BY public.admin_otp_verifications.id;


--
-- TOC entry 242 (class 1259 OID 25032)
-- Name: admins; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.admins OWNER TO postgres;

--
-- TOC entry 241 (class 1259 OID 25031)
-- Name: admins_admin_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.admins_admin_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.admins_admin_id_seq OWNER TO postgres;

--
-- TOC entry 5269 (class 0 OID 0)
-- Dependencies: 241
-- Name: admins_admin_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.admins_admin_id_seq OWNED BY public.admins.admin_id;


--
-- TOC entry 234 (class 1259 OID 24976)
-- Name: announcements; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.announcements (
    announcement_id integer NOT NULL,
    title text NOT NULL,
    remarks text,
    posted_at timestamp without time zone DEFAULT now() NOT NULL,
    is_active boolean DEFAULT false NOT NULL
);


ALTER TABLE public.announcements OWNER TO postgres;

--
-- TOC entry 233 (class 1259 OID 24975)
-- Name: announcements_announcement_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.announcements_announcement_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.announcements_announcement_id_seq OWNER TO postgres;

--
-- TOC entry 5271 (class 0 OID 0)
-- Dependencies: 233
-- Name: announcements_announcement_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.announcements_announcement_id_seq OWNED BY public.announcements.announcement_id;


--
-- TOC entry 221 (class 1259 OID 24629)
-- Name: applications; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.applications (
    application_id integer NOT NULL,
    semester text,
    academic_year text,
    is_valid boolean DEFAULT true,
    remarks text,
    student_id text
);


ALTER TABLE public.applications OWNER TO postgres;

--
-- TOC entry 220 (class 1259 OID 24628)
-- Name: applications_application_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.applications_application_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.applications_application_id_seq OWNER TO postgres;

--
-- TOC entry 5273 (class 0 OID 0)
-- Dependencies: 220
-- Name: applications_application_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.applications_application_id_seq OWNED BY public.applications.application_id;


--
-- TOC entry 229 (class 1259 OID 24714)
-- Name: barangays; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.barangays (
    barangay_id integer NOT NULL,
    municipality_id integer,
    name text NOT NULL
);


ALTER TABLE public.barangays OWNER TO postgres;

--
-- TOC entry 228 (class 1259 OID 24713)
-- Name: barangays_barangay_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.barangays_barangay_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.barangays_barangay_id_seq OWNER TO postgres;

--
-- TOC entry 5275 (class 0 OID 0)
-- Dependencies: 228
-- Name: barangays_barangay_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.barangays_barangay_id_seq OWNED BY public.barangays.barangay_id;


--
-- TOC entry 264 (class 1259 OID 33397)
-- Name: blacklisted_students; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.blacklisted_students OWNER TO postgres;

--
-- TOC entry 263 (class 1259 OID 33396)
-- Name: blacklisted_students_blacklist_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.blacklisted_students_blacklist_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.blacklisted_students_blacklist_id_seq OWNER TO postgres;

--
-- TOC entry 5276 (class 0 OID 0)
-- Dependencies: 263
-- Name: blacklisted_students_blacklist_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.blacklisted_students_blacklist_id_seq OWNED BY public.blacklisted_students.blacklist_id;


--
-- TOC entry 232 (class 1259 OID 24906)
-- Name: config; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.config (
    key text NOT NULL,
    value text
);


ALTER TABLE public.config OWNER TO postgres;

--
-- TOC entry 258 (class 1259 OID 33334)
-- Name: distribution_snapshots; Type: TABLE; Schema: public; Owner: postgres
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
    students_data jsonb
);


ALTER TABLE public.distribution_snapshots OWNER TO postgres;

--
-- TOC entry 257 (class 1259 OID 33333)
-- Name: distribution_snapshots_snapshot_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.distribution_snapshots_snapshot_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.distribution_snapshots_snapshot_id_seq OWNER TO postgres;

--
-- TOC entry 5278 (class 0 OID 0)
-- Dependencies: 257
-- Name: distribution_snapshots_snapshot_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.distribution_snapshots_snapshot_id_seq OWNED BY public.distribution_snapshots.snapshot_id;


--
-- TOC entry 225 (class 1259 OID 24677)
-- Name: distributions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.distributions (
    distribution_id integer NOT NULL,
    date_given date,
    verified_by integer,
    remarks text,
    student_id text
);


ALTER TABLE public.distributions OWNER TO postgres;

--
-- TOC entry 224 (class 1259 OID 24676)
-- Name: distributions_distribution_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.distributions_distribution_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.distributions_distribution_id_seq OWNER TO postgres;

--
-- TOC entry 5280 (class 0 OID 0)
-- Dependencies: 224
-- Name: distributions_distribution_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.distributions_distribution_id_seq OWNED BY public.distributions.distribution_id;


--
-- TOC entry 223 (class 1259 OID 24644)
-- Name: documents; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.documents (
    document_id integer NOT NULL,
    type text,
    file_path text,
    upload_date timestamp without time zone DEFAULT now(),
    is_valid boolean DEFAULT true,
    validation_notes text,
    ocr_confidence numeric(5,2) DEFAULT 0.00,
    validation_confidence numeric(5,2) DEFAULT 0.00,
    student_id text,
    CONSTRAINT documents_type_check CHECK ((type = ANY (ARRAY['school_id'::text, 'eaf'::text, 'certificate_of_indigency'::text, 'letter_to_mayor'::text, 'id_picture'::text])))
);


ALTER TABLE public.documents OWNER TO postgres;

--
-- TOC entry 5281 (class 0 OID 0)
-- Dependencies: 223
-- Name: COLUMN documents.ocr_confidence; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.documents.ocr_confidence IS 'OCR processing confidence score for document readability';


--
-- TOC entry 5282 (class 0 OID 0)
-- Dependencies: 223
-- Name: COLUMN documents.validation_confidence; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.documents.validation_confidence IS 'Manual validation confidence score';


--
-- TOC entry 222 (class 1259 OID 24643)
-- Name: documents_document_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.documents_document_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.documents_document_id_seq OWNER TO postgres;

--
-- TOC entry 5284 (class 0 OID 0)
-- Dependencies: 222
-- Name: documents_document_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.documents_document_id_seq OWNED BY public.documents.document_id;


--
-- TOC entry 248 (class 1259 OID 25088)
-- Name: enrollment_forms; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.enrollment_forms (
    form_id integer NOT NULL,
    file_path text NOT NULL,
    original_filename text NOT NULL,
    upload_date timestamp without time zone DEFAULT now(),
    student_id text
);


ALTER TABLE public.enrollment_forms OWNER TO postgres;

--
-- TOC entry 247 (class 1259 OID 25087)
-- Name: enrollment_forms_form_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.enrollment_forms_form_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.enrollment_forms_form_id_seq OWNER TO postgres;

--
-- TOC entry 5285 (class 0 OID 0)
-- Dependencies: 247
-- Name: enrollment_forms_form_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.enrollment_forms_form_id_seq OWNED BY public.enrollment_forms.form_id;


--
-- TOC entry 254 (class 1259 OID 25147)
-- Name: extracted_grades; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.extracted_grades (
    grade_id integer NOT NULL,
    upload_id integer,
    subject_name character varying(100),
    grade_value character varying(10),
    grade_numeric numeric(4,2),
    grade_percentage numeric(5,2),
    semester character varying(20),
    school_year character varying(10),
    extraction_confidence numeric(5,2),
    is_passing boolean DEFAULT false,
    manual_entry boolean DEFAULT false
);


ALTER TABLE public.extracted_grades OWNER TO postgres;

--
-- TOC entry 5286 (class 0 OID 0)
-- Dependencies: 254
-- Name: COLUMN extracted_grades.manual_entry; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.extracted_grades.manual_entry IS 'TRUE if this grade was manually entered by an admin, FALSE if extracted via OCR';


--
-- TOC entry 253 (class 1259 OID 25146)
-- Name: extracted_grades_grade_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.extracted_grades_grade_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.extracted_grades_grade_id_seq OWNER TO postgres;

--
-- TOC entry 5287 (class 0 OID 0)
-- Dependencies: 253
-- Name: extracted_grades_grade_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.extracted_grades_grade_id_seq OWNED BY public.extracted_grades.grade_id;


--
-- TOC entry 270 (class 1259 OID 33582)
-- Name: grade_documents; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.grade_documents (
    id integer NOT NULL,
    student_id character varying(255) NOT NULL,
    file_name character varying(255) NOT NULL,
    file_path character varying(500) NOT NULL,
    file_type character varying(50) NOT NULL,
    file_size integer,
    upload_source character varying(50) DEFAULT 'registration'::character varying,
    ocr_text text,
    ocr_confidence numeric(5,2),
    processing_status character varying(20) DEFAULT 'pending'::character varying,
    verification_status character varying(20) DEFAULT 'pending'::character varying,
    admin_notes text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.grade_documents OWNER TO postgres;

--
-- TOC entry 5288 (class 0 OID 0)
-- Dependencies: 270
-- Name: TABLE grade_documents; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON TABLE public.grade_documents IS 'Stores uploaded grade documents and OCR results';


--
-- TOC entry 269 (class 1259 OID 33581)
-- Name: grade_documents_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.grade_documents_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.grade_documents_id_seq OWNER TO postgres;

--
-- TOC entry 5289 (class 0 OID 0)
-- Dependencies: 269
-- Name: grade_documents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.grade_documents_id_seq OWNED BY public.grade_documents.id;


--
-- TOC entry 252 (class 1259 OID 25124)
-- Name: grade_uploads; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.grade_uploads (
    upload_id integer NOT NULL,
    file_path character varying(255) NOT NULL,
    file_type character varying(10) NOT NULL,
    upload_date timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    ocr_processed boolean DEFAULT false,
    ocr_confidence numeric(5,2),
    extracted_text text,
    validation_status character varying(20) DEFAULT 'pending'::character varying,
    admin_reviewed boolean DEFAULT false,
    admin_notes text,
    reviewed_by integer,
    reviewed_at timestamp without time zone,
    grading_system_used character varying(20) DEFAULT 'unknown'::character varying,
    student_id text
);


ALTER TABLE public.grade_uploads OWNER TO postgres;

--
-- TOC entry 5290 (class 0 OID 0)
-- Dependencies: 252
-- Name: COLUMN grade_uploads.grading_system_used; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.grade_uploads.grading_system_used IS 'The grading system used: gpa, percentage, letter, auto_detected, or unknown';


--
-- TOC entry 251 (class 1259 OID 25123)
-- Name: grade_uploads_upload_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.grade_uploads_upload_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.grade_uploads_upload_id_seq OWNER TO postgres;

--
-- TOC entry 5291 (class 0 OID 0)
-- Dependencies: 251
-- Name: grade_uploads_upload_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.grade_uploads_upload_id_seq OWNED BY public.grade_uploads.upload_id;


--
-- TOC entry 272 (class 1259 OID 33606)
-- Name: grading_systems; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.grading_systems OWNER TO postgres;

--
-- TOC entry 5292 (class 0 OID 0)
-- Dependencies: 272
-- Name: TABLE grading_systems; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON TABLE public.grading_systems IS 'Configuration for different grading systems';


--
-- TOC entry 271 (class 1259 OID 33605)
-- Name: grading_systems_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.grading_systems_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.grading_systems_id_seq OWNER TO postgres;

--
-- TOC entry 5293 (class 0 OID 0)
-- Dependencies: 271
-- Name: grading_systems_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.grading_systems_id_seq OWNED BY public.grading_systems.id;


--
-- TOC entry 218 (class 1259 OID 24582)
-- Name: municipalities; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.municipalities (
    municipality_id integer NOT NULL,
    name text NOT NULL,
    color_theme text,
    banner_image text,
    logo_image text,
    max_capacity integer
);


ALTER TABLE public.municipalities OWNER TO postgres;

--
-- TOC entry 217 (class 1259 OID 24581)
-- Name: municipalities_municipality_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.municipalities_municipality_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.municipalities_municipality_id_seq OWNER TO postgres;

--
-- TOC entry 5295 (class 0 OID 0)
-- Dependencies: 217
-- Name: municipalities_municipality_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.municipalities_municipality_id_seq OWNED BY public.municipalities.municipality_id;


--
-- TOC entry 238 (class 1259 OID 25007)
-- Name: notifications; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.notifications (
    notification_id integer NOT NULL,
    message text NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    student_id text
);


ALTER TABLE public.notifications OWNER TO postgres;

--
-- TOC entry 237 (class 1259 OID 25006)
-- Name: notifications_notification_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.notifications_notification_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.notifications_notification_id_seq OWNER TO postgres;

--
-- TOC entry 5297 (class 0 OID 0)
-- Dependencies: 237
-- Name: notifications_notification_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.notifications_notification_id_seq OWNED BY public.notifications.notification_id;


--
-- TOC entry 250 (class 1259 OID 25107)
-- Name: qr_codes; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.qr_codes OWNER TO postgres;

--
-- TOC entry 249 (class 1259 OID 25106)
-- Name: qr_codes_qr_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.qr_codes_qr_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.qr_codes_qr_id_seq OWNER TO postgres;

--
-- TOC entry 5298 (class 0 OID 0)
-- Dependencies: 249
-- Name: qr_codes_qr_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.qr_codes_qr_id_seq OWNED BY public.qr_codes.qr_id;


--
-- TOC entry 227 (class 1259 OID 24696)
-- Name: qr_logs; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.qr_logs (
    log_id integer NOT NULL,
    scanned_at timestamp without time zone DEFAULT now(),
    scanned_by integer,
    student_id text
);


ALTER TABLE public.qr_logs OWNER TO postgres;

--
-- TOC entry 226 (class 1259 OID 24695)
-- Name: qr_logs_log_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.qr_logs_log_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.qr_logs_log_id_seq OWNER TO postgres;

--
-- TOC entry 5300 (class 0 OID 0)
-- Dependencies: 226
-- Name: qr_logs_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.qr_logs_log_id_seq OWNED BY public.qr_logs.log_id;


--
-- TOC entry 262 (class 1259 OID 33376)
-- Name: schedule_batches; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.schedule_batches OWNER TO postgres;

--
-- TOC entry 261 (class 1259 OID 33375)
-- Name: schedule_batches_batch_config_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.schedule_batches_batch_config_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.schedule_batches_batch_config_id_seq OWNER TO postgres;

--
-- TOC entry 5301 (class 0 OID 0)
-- Dependencies: 261
-- Name: schedule_batches_batch_config_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.schedule_batches_batch_config_id_seq OWNED BY public.schedule_batches.batch_config_id;


--
-- TOC entry 236 (class 1259 OID 24987)
-- Name: schedules; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.schedules OWNER TO postgres;

--
-- TOC entry 235 (class 1259 OID 24986)
-- Name: schedules_schedule_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.schedules_schedule_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.schedules_schedule_id_seq OWNER TO postgres;

--
-- TOC entry 5303 (class 0 OID 0)
-- Dependencies: 235
-- Name: schedules_schedule_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.schedules_schedule_id_seq OWNED BY public.schedules.schedule_id;


--
-- TOC entry 231 (class 1259 OID 24733)
-- Name: signup_slots; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.signup_slots OWNER TO postgres;

--
-- TOC entry 230 (class 1259 OID 24732)
-- Name: signup_slots_slot_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.signup_slots_slot_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.signup_slots_slot_id_seq OWNER TO postgres;

--
-- TOC entry 5305 (class 0 OID 0)
-- Dependencies: 230
-- Name: signup_slots_slot_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.signup_slots_slot_id_seq OWNED BY public.signup_slots.slot_id;


--
-- TOC entry 268 (class 1259 OID 33570)
-- Name: student_gpa_summary; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.student_gpa_summary (
    id integer NOT NULL,
    student_id character varying(255) NOT NULL,
    semester character varying(50) NOT NULL,
    academic_year character varying(20) NOT NULL,
    total_units integer NOT NULL,
    gpa numeric(4,2) NOT NULL,
    grading_system character varying(20) NOT NULL,
    source character varying(50) DEFAULT 'calculated'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.student_gpa_summary OWNER TO postgres;

--
-- TOC entry 5306 (class 0 OID 0)
-- Dependencies: 268
-- Name: TABLE student_gpa_summary; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON TABLE public.student_gpa_summary IS 'Stores calculated GPA summaries by semester/year';


--
-- TOC entry 267 (class 1259 OID 33569)
-- Name: student_gpa_summary_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.student_gpa_summary_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.student_gpa_summary_id_seq OWNER TO postgres;

--
-- TOC entry 5307 (class 0 OID 0)
-- Dependencies: 267
-- Name: student_gpa_summary_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.student_gpa_summary_id_seq OWNED BY public.student_gpa_summary.id;


--
-- TOC entry 274 (class 1259 OID 33624)
-- Name: student_grades; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.student_grades (
    id integer NOT NULL,
    student_id character varying(255) NOT NULL,
    subject_name character varying(255) NOT NULL,
    grade_value character varying(10) NOT NULL,
    grade_system character varying(20) NOT NULL,
    units integer NOT NULL,
    semester character varying(50),
    academic_year character varying(20),
    source character varying(50) DEFAULT 'manual'::character varying,
    verification_status character varying(20) DEFAULT 'pending'::character varying,
    ocr_confidence numeric(5,2),
    admin_notes text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    created_by character varying(255),
    updated_by character varying(255),
    CONSTRAINT student_grades_grade_system_check CHECK (((grade_system)::text = ANY ((ARRAY['percentage'::character varying, 'gpa'::character varying, 'dlsu_gpa'::character varying, 'letter'::character varying])::text[]))),
    CONSTRAINT student_grades_source_check CHECK (((source)::text = ANY ((ARRAY['manual'::character varying, 'registration'::character varying, 'upload'::character varying, 'admin_entry'::character varying])::text[]))),
    CONSTRAINT student_grades_units_check CHECK (((units > 0) AND (units <= 6))),
    CONSTRAINT student_grades_verification_status_check CHECK (((verification_status)::text = ANY ((ARRAY['pending'::character varying, 'verified'::character varying, 'rejected'::character varying, 'needs_review'::character varying])::text[])))
);


ALTER TABLE public.student_grades OWNER TO postgres;

--
-- TOC entry 273 (class 1259 OID 33623)
-- Name: student_grades_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.student_grades_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.student_grades_id_seq OWNER TO postgres;

--
-- TOC entry 5308 (class 0 OID 0)
-- Dependencies: 273
-- Name: student_grades_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.student_grades_id_seq OWNED BY public.student_grades.id;


--
-- TOC entry 219 (class 1259 OID 24608)
-- Name: students; Type: TABLE; Schema: public; Owner: postgres
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
    qr_code text,
    has_received boolean DEFAULT false,
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
    CONSTRAINT students_sex_check CHECK ((sex = ANY (ARRAY['Male'::text, 'Female'::text]))),
    CONSTRAINT students_status_check CHECK ((status = ANY (ARRAY['under_registration'::text, 'applicant'::text, 'active'::text, 'disabled'::text, 'given'::text, 'blacklisted'::text])))
);


ALTER TABLE public.students OWNER TO postgres;

--
-- TOC entry 5309 (class 0 OID 0)
-- Dependencies: 219
-- Name: COLUMN students.slot_id; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.students.slot_id IS 'Tracks which signup slot the student originally registered under for audit trail and data integrity';


--
-- TOC entry 5310 (class 0 OID 0)
-- Dependencies: 219
-- Name: COLUMN students.confidence_score; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.students.confidence_score IS 'Confidence score (0-100) based on data completeness, document quality, and validation results';


--
-- TOC entry 5311 (class 0 OID 0)
-- Dependencies: 219
-- Name: COLUMN students.confidence_notes; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.students.confidence_notes IS 'Notes about confidence score calculation';


--
-- TOC entry 244 (class 1259 OID 25050)
-- Name: universities; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.universities (
    university_id integer NOT NULL,
    name text NOT NULL,
    code text NOT NULL,
    created_at timestamp without time zone DEFAULT now()
);


ALTER TABLE public.universities OWNER TO postgres;

--
-- TOC entry 243 (class 1259 OID 25049)
-- Name: universities_university_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.universities_university_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.universities_university_id_seq OWNER TO postgres;

--
-- TOC entry 5313 (class 0 OID 0)
-- Dependencies: 243
-- Name: universities_university_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.universities_university_id_seq OWNED BY public.universities.university_id;


--
-- TOC entry 260 (class 1259 OID 33357)
-- Name: used_schedule_dates; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.used_schedule_dates (
    date_id integer NOT NULL,
    schedule_date date NOT NULL,
    location text NOT NULL,
    total_students integer NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    created_by integer
);


ALTER TABLE public.used_schedule_dates OWNER TO postgres;

--
-- TOC entry 259 (class 1259 OID 33356)
-- Name: used_schedule_dates_date_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.used_schedule_dates_date_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.used_schedule_dates_date_id_seq OWNER TO postgres;

--
-- TOC entry 5314 (class 0 OID 0)
-- Dependencies: 259
-- Name: used_schedule_dates_date_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.used_schedule_dates_date_id_seq OWNED BY public.used_schedule_dates.date_id;


--
-- TOC entry 246 (class 1259 OID 25062)
-- Name: year_levels; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.year_levels (
    year_level_id integer NOT NULL,
    name text NOT NULL,
    code text NOT NULL,
    sort_order integer NOT NULL,
    created_at timestamp without time zone DEFAULT now()
);


ALTER TABLE public.year_levels OWNER TO postgres;

--
-- TOC entry 245 (class 1259 OID 25061)
-- Name: year_levels_year_level_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.year_levels_year_level_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.year_levels_year_level_id_seq OWNER TO postgres;

--
-- TOC entry 5315 (class 0 OID 0)
-- Dependencies: 245
-- Name: year_levels_year_level_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.year_levels_year_level_id_seq OWNED BY public.year_levels.year_level_id;


--
-- TOC entry 4960 (class 2604 OID 33423)
-- Name: admin_blacklist_verifications id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.admin_blacklist_verifications ALTER COLUMN id SET DEFAULT nextval('public.admin_blacklist_verifications_id_seq'::regclass);


--
-- TOC entry 4923 (class 2604 OID 25025)
-- Name: admin_notifications admin_notification_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.admin_notifications ALTER COLUMN admin_notification_id SET DEFAULT nextval('public.admin_notifications_admin_notification_id_seq'::regclass);


--
-- TOC entry 4948 (class 2604 OID 33320)
-- Name: admin_otp_verifications id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.admin_otp_verifications ALTER COLUMN id SET DEFAULT nextval('public.admin_otp_verifications_id_seq'::regclass);


--
-- TOC entry 4926 (class 2604 OID 25035)
-- Name: admins admin_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.admins ALTER COLUMN admin_id SET DEFAULT nextval('public.admins_admin_id_seq'::regclass);


--
-- TOC entry 4912 (class 2604 OID 24979)
-- Name: announcements announcement_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.announcements ALTER COLUMN announcement_id SET DEFAULT nextval('public.announcements_announcement_id_seq'::regclass);


--
-- TOC entry 4897 (class 2604 OID 24632)
-- Name: applications application_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.applications ALTER COLUMN application_id SET DEFAULT nextval('public.applications_application_id_seq'::regclass);


--
-- TOC entry 4907 (class 2604 OID 24717)
-- Name: barangays barangay_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.barangays ALTER COLUMN barangay_id SET DEFAULT nextval('public.barangays_barangay_id_seq'::regclass);


--
-- TOC entry 4958 (class 2604 OID 33400)
-- Name: blacklisted_students blacklist_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.blacklisted_students ALTER COLUMN blacklist_id SET DEFAULT nextval('public.blacklisted_students_blacklist_id_seq'::regclass);


--
-- TOC entry 4951 (class 2604 OID 33337)
-- Name: distribution_snapshots snapshot_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.distribution_snapshots ALTER COLUMN snapshot_id SET DEFAULT nextval('public.distribution_snapshots_snapshot_id_seq'::regclass);


--
-- TOC entry 4904 (class 2604 OID 24680)
-- Name: distributions distribution_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.distributions ALTER COLUMN distribution_id SET DEFAULT nextval('public.distributions_distribution_id_seq'::regclass);


--
-- TOC entry 4899 (class 2604 OID 24647)
-- Name: documents document_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.documents ALTER COLUMN document_id SET DEFAULT nextval('public.documents_document_id_seq'::regclass);


--
-- TOC entry 4934 (class 2604 OID 25091)
-- Name: enrollment_forms form_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.enrollment_forms ALTER COLUMN form_id SET DEFAULT nextval('public.enrollment_forms_form_id_seq'::regclass);


--
-- TOC entry 4945 (class 2604 OID 25150)
-- Name: extracted_grades grade_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.extracted_grades ALTER COLUMN grade_id SET DEFAULT nextval('public.extracted_grades_grade_id_seq'::regclass);


--
-- TOC entry 4967 (class 2604 OID 33585)
-- Name: grade_documents id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.grade_documents ALTER COLUMN id SET DEFAULT nextval('public.grade_documents_id_seq'::regclass);


--
-- TOC entry 4939 (class 2604 OID 25127)
-- Name: grade_uploads upload_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.grade_uploads ALTER COLUMN upload_id SET DEFAULT nextval('public.grade_uploads_upload_id_seq'::regclass);


--
-- TOC entry 4973 (class 2604 OID 33609)
-- Name: grading_systems id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.grading_systems ALTER COLUMN id SET DEFAULT nextval('public.grading_systems_id_seq'::regclass);


--
-- TOC entry 4889 (class 2604 OID 24585)
-- Name: municipalities municipality_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.municipalities ALTER COLUMN municipality_id SET DEFAULT nextval('public.municipalities_municipality_id_seq'::regclass);


--
-- TOC entry 4921 (class 2604 OID 25010)
-- Name: notifications notification_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.notifications ALTER COLUMN notification_id SET DEFAULT nextval('public.notifications_notification_id_seq'::regclass);


--
-- TOC entry 4936 (class 2604 OID 25110)
-- Name: qr_codes qr_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.qr_codes ALTER COLUMN qr_id SET DEFAULT nextval('public.qr_codes_qr_id_seq'::regclass);


--
-- TOC entry 4905 (class 2604 OID 24699)
-- Name: qr_logs log_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.qr_logs ALTER COLUMN log_id SET DEFAULT nextval('public.qr_logs_log_id_seq'::regclass);


--
-- TOC entry 4955 (class 2604 OID 33379)
-- Name: schedule_batches batch_config_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.schedule_batches ALTER COLUMN batch_config_id SET DEFAULT nextval('public.schedule_batches_batch_config_id_seq'::regclass);


--
-- TOC entry 4915 (class 2604 OID 24990)
-- Name: schedules schedule_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.schedules ALTER COLUMN schedule_id SET DEFAULT nextval('public.schedules_schedule_id_seq'::regclass);


--
-- TOC entry 4908 (class 2604 OID 24736)
-- Name: signup_slots slot_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.signup_slots ALTER COLUMN slot_id SET DEFAULT nextval('public.signup_slots_slot_id_seq'::regclass);


--
-- TOC entry 4963 (class 2604 OID 33573)
-- Name: student_gpa_summary id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.student_gpa_summary ALTER COLUMN id SET DEFAULT nextval('public.student_gpa_summary_id_seq'::regclass);


--
-- TOC entry 4976 (class 2604 OID 33627)
-- Name: student_grades id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.student_grades ALTER COLUMN id SET DEFAULT nextval('public.student_grades_id_seq'::regclass);


--
-- TOC entry 4930 (class 2604 OID 25053)
-- Name: universities university_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.universities ALTER COLUMN university_id SET DEFAULT nextval('public.universities_university_id_seq'::regclass);


--
-- TOC entry 4953 (class 2604 OID 33360)
-- Name: used_schedule_dates date_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.used_schedule_dates ALTER COLUMN date_id SET DEFAULT nextval('public.used_schedule_dates_date_id_seq'::regclass);


--
-- TOC entry 4932 (class 2604 OID 25065)
-- Name: year_levels year_level_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.year_levels ALTER COLUMN year_level_id SET DEFAULT nextval('public.year_levels_year_level_id_seq'::regclass);


--
-- TOC entry 5076 (class 2606 OID 33429)
-- Name: admin_blacklist_verifications admin_blacklist_verifications_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.admin_blacklist_verifications
    ADD CONSTRAINT admin_blacklist_verifications_pkey PRIMARY KEY (id);


--
-- TOC entry 5025 (class 2606 OID 25030)
-- Name: admin_notifications admin_notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.admin_notifications
    ADD CONSTRAINT admin_notifications_pkey PRIMARY KEY (admin_notification_id);


--
-- TOC entry 5053 (class 2606 OID 33324)
-- Name: admin_otp_verifications admin_otp_verifications_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.admin_otp_verifications
    ADD CONSTRAINT admin_otp_verifications_pkey PRIMARY KEY (id);


--
-- TOC entry 5028 (class 2606 OID 25041)
-- Name: admins admins_email_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.admins
    ADD CONSTRAINT admins_email_key UNIQUE (email);


--
-- TOC entry 5030 (class 2606 OID 25039)
-- Name: admins admins_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.admins
    ADD CONSTRAINT admins_pkey PRIMARY KEY (admin_id);


--
-- TOC entry 5032 (class 2606 OID 25043)
-- Name: admins admins_username_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.admins
    ADD CONSTRAINT admins_username_key UNIQUE (username);


--
-- TOC entry 5019 (class 2606 OID 24985)
-- Name: announcements announcements_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.announcements
    ADD CONSTRAINT announcements_pkey PRIMARY KEY (announcement_id);


--
-- TOC entry 5005 (class 2606 OID 24637)
-- Name: applications applications_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.applications
    ADD CONSTRAINT applications_pkey PRIMARY KEY (application_id);


--
-- TOC entry 5013 (class 2606 OID 24721)
-- Name: barangays barangays_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.barangays
    ADD CONSTRAINT barangays_pkey PRIMARY KEY (barangay_id);


--
-- TOC entry 5073 (class 2606 OID 33406)
-- Name: blacklisted_students blacklisted_students_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.blacklisted_students
    ADD CONSTRAINT blacklisted_students_pkey PRIMARY KEY (blacklist_id);


--
-- TOC entry 5017 (class 2606 OID 24912)
-- Name: config config_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.config
    ADD CONSTRAINT config_pkey PRIMARY KEY (key);


--
-- TOC entry 5058 (class 2606 OID 33342)
-- Name: distribution_snapshots distribution_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.distribution_snapshots
    ADD CONSTRAINT distribution_snapshots_pkey PRIMARY KEY (snapshot_id);


--
-- TOC entry 5009 (class 2606 OID 24684)
-- Name: distributions distributions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.distributions
    ADD CONSTRAINT distributions_pkey PRIMARY KEY (distribution_id);


--
-- TOC entry 5007 (class 2606 OID 24654)
-- Name: documents documents_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.documents
    ADD CONSTRAINT documents_pkey PRIMARY KEY (document_id);


--
-- TOC entry 5042 (class 2606 OID 25096)
-- Name: enrollment_forms enrollment_forms_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.enrollment_forms
    ADD CONSTRAINT enrollment_forms_pkey PRIMARY KEY (form_id);


--
-- TOC entry 5050 (class 2606 OID 25153)
-- Name: extracted_grades extracted_grades_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.extracted_grades
    ADD CONSTRAINT extracted_grades_pkey PRIMARY KEY (grade_id);


--
-- TOC entry 5085 (class 2606 OID 33594)
-- Name: grade_documents grade_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.grade_documents
    ADD CONSTRAINT grade_documents_pkey PRIMARY KEY (id);


--
-- TOC entry 5046 (class 2606 OID 25135)
-- Name: grade_uploads grade_uploads_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.grade_uploads
    ADD CONSTRAINT grade_uploads_pkey PRIMARY KEY (upload_id);


--
-- TOC entry 5089 (class 2606 OID 33615)
-- Name: grading_systems grading_systems_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.grading_systems
    ADD CONSTRAINT grading_systems_pkey PRIMARY KEY (id);


--
-- TOC entry 5091 (class 2606 OID 33617)
-- Name: grading_systems grading_systems_system_name_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.grading_systems
    ADD CONSTRAINT grading_systems_system_name_key UNIQUE (system_name);


--
-- TOC entry 4993 (class 2606 OID 24589)
-- Name: municipalities municipalities_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.municipalities
    ADD CONSTRAINT municipalities_pkey PRIMARY KEY (municipality_id);


--
-- TOC entry 5023 (class 2606 OID 25015)
-- Name: notifications notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (notification_id);


--
-- TOC entry 5044 (class 2606 OID 25117)
-- Name: qr_codes qr_codes_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.qr_codes
    ADD CONSTRAINT qr_codes_pkey PRIMARY KEY (qr_id);


--
-- TOC entry 5011 (class 2606 OID 24702)
-- Name: qr_logs qr_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.qr_logs
    ADD CONSTRAINT qr_logs_pkey PRIMARY KEY (log_id);


--
-- TOC entry 5069 (class 2606 OID 33385)
-- Name: schedule_batches schedule_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.schedule_batches
    ADD CONSTRAINT schedule_batches_pkey PRIMARY KEY (batch_config_id);


--
-- TOC entry 5071 (class 2606 OID 33387)
-- Name: schedule_batches schedule_batches_schedule_date_batch_number_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.schedule_batches
    ADD CONSTRAINT schedule_batches_schedule_date_batch_number_key UNIQUE (schedule_date, batch_number);


--
-- TOC entry 5021 (class 2606 OID 24999)
-- Name: schedules schedules_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.schedules
    ADD CONSTRAINT schedules_pkey PRIMARY KEY (schedule_id);


--
-- TOC entry 5015 (class 2606 OID 24740)
-- Name: signup_slots signup_slots_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.signup_slots
    ADD CONSTRAINT signup_slots_pkey PRIMARY KEY (slot_id);


--
-- TOC entry 5081 (class 2606 OID 33578)
-- Name: student_gpa_summary student_gpa_summary_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.student_gpa_summary
    ADD CONSTRAINT student_gpa_summary_pkey PRIMARY KEY (id);


--
-- TOC entry 5083 (class 2606 OID 33580)
-- Name: student_gpa_summary student_gpa_summary_student_id_semester_academic_year_sourc_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.student_gpa_summary
    ADD CONSTRAINT student_gpa_summary_student_id_semester_academic_year_sourc_key UNIQUE (student_id, semester, academic_year, source);


--
-- TOC entry 5093 (class 2606 OID 33639)
-- Name: student_grades student_grades_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.student_grades
    ADD CONSTRAINT student_grades_pkey PRIMARY KEY (id);


--
-- TOC entry 4999 (class 2606 OID 24622)
-- Name: students students_email_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_email_key UNIQUE (email);


--
-- TOC entry 5001 (class 2606 OID 33659)
-- Name: students students_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_pkey PRIMARY KEY (student_id);


--
-- TOC entry 5003 (class 2606 OID 25084)
-- Name: students students_unique_student_id_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_unique_student_id_key UNIQUE (student_id);


--
-- TOC entry 5034 (class 2606 OID 25060)
-- Name: universities universities_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.universities
    ADD CONSTRAINT universities_code_key UNIQUE (code);


--
-- TOC entry 5036 (class 2606 OID 25058)
-- Name: universities universities_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.universities
    ADD CONSTRAINT universities_pkey PRIMARY KEY (university_id);


--
-- TOC entry 5063 (class 2606 OID 33365)
-- Name: used_schedule_dates used_schedule_dates_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.used_schedule_dates
    ADD CONSTRAINT used_schedule_dates_pkey PRIMARY KEY (date_id);


--
-- TOC entry 5065 (class 2606 OID 33367)
-- Name: used_schedule_dates used_schedule_dates_schedule_date_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.used_schedule_dates
    ADD CONSTRAINT used_schedule_dates_schedule_date_key UNIQUE (schedule_date);


--
-- TOC entry 5038 (class 2606 OID 25072)
-- Name: year_levels year_levels_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.year_levels
    ADD CONSTRAINT year_levels_code_key UNIQUE (code);


--
-- TOC entry 5040 (class 2606 OID 25070)
-- Name: year_levels year_levels_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.year_levels
    ADD CONSTRAINT year_levels_pkey PRIMARY KEY (year_level_id);


--
-- TOC entry 5077 (class 1259 OID 33440)
-- Name: idx_admin_blacklist_verifications_admin_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_admin_blacklist_verifications_admin_id ON public.admin_blacklist_verifications USING btree (admin_id);


--
-- TOC entry 5078 (class 1259 OID 33441)
-- Name: idx_admin_blacklist_verifications_expires; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_admin_blacklist_verifications_expires ON public.admin_blacklist_verifications USING btree (expires_at);


--
-- TOC entry 5026 (class 1259 OID 33678)
-- Name: idx_admin_notifications_is_read; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_admin_notifications_is_read ON public.admin_notifications USING btree (is_read);


--
-- TOC entry 5054 (class 1259 OID 33332)
-- Name: idx_admin_otp_admin_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_admin_otp_admin_id ON public.admin_otp_verifications USING btree (admin_id);


--
-- TOC entry 5055 (class 1259 OID 33330)
-- Name: idx_admin_otp_admin_purpose; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_admin_otp_admin_purpose ON public.admin_otp_verifications USING btree (admin_id, purpose);


--
-- TOC entry 5056 (class 1259 OID 33331)
-- Name: idx_admin_otp_expires; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_admin_otp_expires ON public.admin_otp_verifications USING btree (expires_at);


--
-- TOC entry 5074 (class 1259 OID 33418)
-- Name: idx_blacklisted_students_blacklisted_by; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_blacklisted_students_blacklisted_by ON public.blacklisted_students USING btree (blacklisted_by);


--
-- TOC entry 5059 (class 1259 OID 33348)
-- Name: idx_distribution_snapshots_date; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_distribution_snapshots_date ON public.distribution_snapshots USING btree (distribution_date);


--
-- TOC entry 5060 (class 1259 OID 33349)
-- Name: idx_distribution_snapshots_finalized_by; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_distribution_snapshots_finalized_by ON public.distribution_snapshots USING btree (finalized_by);


--
-- TOC entry 5051 (class 1259 OID 25161)
-- Name: idx_extracted_grades_upload; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_extracted_grades_upload ON public.extracted_grades USING btree (upload_id);


--
-- TOC entry 5086 (class 1259 OID 33600)
-- Name: idx_grade_documents_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_grade_documents_status ON public.grade_documents USING btree (processing_status, verification_status);


--
-- TOC entry 5087 (class 1259 OID 33599)
-- Name: idx_grade_documents_student_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_grade_documents_student_id ON public.grade_documents USING btree (student_id);


--
-- TOC entry 5047 (class 1259 OID 25160)
-- Name: idx_grade_uploads_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_grade_uploads_status ON public.grade_uploads USING btree (validation_status);


--
-- TOC entry 5048 (class 1259 OID 33675)
-- Name: idx_grade_uploads_student; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_grade_uploads_student ON public.grade_uploads USING btree (student_id);


--
-- TOC entry 5066 (class 1259 OID 33393)
-- Name: idx_schedule_batches_date; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_schedule_batches_date ON public.schedule_batches USING btree (schedule_date);


--
-- TOC entry 5067 (class 1259 OID 33394)
-- Name: idx_schedule_batches_date_batch; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_schedule_batches_date_batch ON public.schedule_batches USING btree (schedule_date, batch_number);


--
-- TOC entry 5079 (class 1259 OID 33598)
-- Name: idx_student_gpa_student_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_student_gpa_student_id ON public.student_gpa_summary USING btree (student_id);


--
-- TOC entry 4994 (class 1259 OID 33644)
-- Name: idx_students_confidence_score; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_students_confidence_score ON public.students USING btree (confidence_score DESC);


--
-- TOC entry 4995 (class 1259 OID 33549)
-- Name: idx_students_extension_name; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_students_extension_name ON public.students USING btree (extension_name);


--
-- TOC entry 4996 (class 1259 OID 25162)
-- Name: idx_students_last_login; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_students_last_login ON public.students USING btree (last_login);


--
-- TOC entry 4997 (class 1259 OID 33355)
-- Name: idx_students_slot_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_students_slot_id ON public.students USING btree (slot_id);


--
-- TOC entry 5061 (class 1259 OID 33373)
-- Name: idx_used_schedule_dates_date; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_used_schedule_dates_date ON public.used_schedule_dates USING btree (schedule_date);


--
-- TOC entry 5114 (class 2620 OID 33604)
-- Name: grade_documents update_grade_documents_updated_at; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER update_grade_documents_updated_at BEFORE UPDATE ON public.grade_documents FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- TOC entry 5113 (class 2620 OID 33603)
-- Name: student_gpa_summary update_student_gpa_summary_updated_at; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER update_student_gpa_summary_updated_at BEFORE UPDATE ON public.student_gpa_summary FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- TOC entry 5111 (class 2606 OID 33430)
-- Name: admin_blacklist_verifications admin_blacklist_verifications_admin_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.admin_blacklist_verifications
    ADD CONSTRAINT admin_blacklist_verifications_admin_id_fkey FOREIGN KEY (admin_id) REFERENCES public.admins(admin_id) ON DELETE CASCADE;


--
-- TOC entry 5112 (class 2606 OID 33665)
-- Name: admin_blacklist_verifications admin_blacklist_verifications_student_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.admin_blacklist_verifications
    ADD CONSTRAINT admin_blacklist_verifications_student_id_fkey FOREIGN KEY (student_id) REFERENCES public.students(student_id);


--
-- TOC entry 5105 (class 2606 OID 33325)
-- Name: admin_otp_verifications admin_otp_verifications_admin_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.admin_otp_verifications
    ADD CONSTRAINT admin_otp_verifications_admin_id_fkey FOREIGN KEY (admin_id) REFERENCES public.admins(admin_id) ON DELETE CASCADE;


--
-- TOC entry 5101 (class 2606 OID 25044)
-- Name: admins admins_municipality_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.admins
    ADD CONSTRAINT admins_municipality_id_fkey FOREIGN KEY (municipality_id) REFERENCES public.municipalities(municipality_id);


--
-- TOC entry 5099 (class 2606 OID 24722)
-- Name: barangays barangays_municipality_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.barangays
    ADD CONSTRAINT barangays_municipality_id_fkey FOREIGN KEY (municipality_id) REFERENCES public.municipalities(municipality_id);


--
-- TOC entry 5109 (class 2606 OID 33412)
-- Name: blacklisted_students blacklisted_students_blacklisted_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.blacklisted_students
    ADD CONSTRAINT blacklisted_students_blacklisted_by_fkey FOREIGN KEY (blacklisted_by) REFERENCES public.admins(admin_id);


--
-- TOC entry 5110 (class 2606 OID 33660)
-- Name: blacklisted_students blacklisted_students_student_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.blacklisted_students
    ADD CONSTRAINT blacklisted_students_student_id_fkey FOREIGN KEY (student_id) REFERENCES public.students(student_id);


--
-- TOC entry 5106 (class 2606 OID 33343)
-- Name: distribution_snapshots distribution_snapshots_finalized_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.distribution_snapshots
    ADD CONSTRAINT distribution_snapshots_finalized_by_fkey FOREIGN KEY (finalized_by) REFERENCES public.admins(admin_id);


--
-- TOC entry 5104 (class 2606 OID 25154)
-- Name: extracted_grades extracted_grades_upload_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.extracted_grades
    ADD CONSTRAINT extracted_grades_upload_id_fkey FOREIGN KEY (upload_id) REFERENCES public.grade_uploads(upload_id);


--
-- TOC entry 5103 (class 2606 OID 25141)
-- Name: grade_uploads grade_uploads_reviewed_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.grade_uploads
    ADD CONSTRAINT grade_uploads_reviewed_by_fkey FOREIGN KEY (reviewed_by) REFERENCES public.admins(admin_id);


--
-- TOC entry 5102 (class 2606 OID 33670)
-- Name: qr_codes qr_codes_student_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.qr_codes
    ADD CONSTRAINT qr_codes_student_id_fkey FOREIGN KEY (student_id) REFERENCES public.students(student_id);


--
-- TOC entry 5108 (class 2606 OID 33388)
-- Name: schedule_batches schedule_batches_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.schedule_batches
    ADD CONSTRAINT schedule_batches_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.admins(admin_id);


--
-- TOC entry 5100 (class 2606 OID 24741)
-- Name: signup_slots signup_slots_municipality_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.signup_slots
    ADD CONSTRAINT signup_slots_municipality_id_fkey FOREIGN KEY (municipality_id) REFERENCES public.municipalities(municipality_id);


--
-- TOC entry 5094 (class 2606 OID 24727)
-- Name: students students_barangay_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_barangay_id_fkey FOREIGN KEY (barangay_id) REFERENCES public.barangays(barangay_id);


--
-- TOC entry 5095 (class 2606 OID 24623)
-- Name: students students_municipality_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_municipality_id_fkey FOREIGN KEY (municipality_id) REFERENCES public.municipalities(municipality_id);


--
-- TOC entry 5096 (class 2606 OID 33350)
-- Name: students students_slot_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_slot_id_fkey FOREIGN KEY (slot_id) REFERENCES public.signup_slots(slot_id);


--
-- TOC entry 5097 (class 2606 OID 25073)
-- Name: students students_university_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_university_id_fkey FOREIGN KEY (university_id) REFERENCES public.universities(university_id);


--
-- TOC entry 5098 (class 2606 OID 25078)
-- Name: students students_year_level_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_year_level_id_fkey FOREIGN KEY (year_level_id) REFERENCES public.year_levels(year_level_id);


--
-- TOC entry 5107 (class 2606 OID 33368)
-- Name: used_schedule_dates used_schedule_dates_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.used_schedule_dates
    ADD CONSTRAINT used_schedule_dates_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.admins(admin_id);


--
-- TOC entry 5266 (class 0 OID 0)
-- Dependencies: 240
-- Name: TABLE admin_notifications; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.admin_notifications TO PUBLIC;


--
-- TOC entry 5270 (class 0 OID 0)
-- Dependencies: 234
-- Name: TABLE announcements; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.announcements TO PUBLIC;


--
-- TOC entry 5272 (class 0 OID 0)
-- Dependencies: 221
-- Name: TABLE applications; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.applications TO PUBLIC;


--
-- TOC entry 5274 (class 0 OID 0)
-- Dependencies: 229
-- Name: TABLE barangays; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.barangays TO PUBLIC;


--
-- TOC entry 5277 (class 0 OID 0)
-- Dependencies: 232
-- Name: TABLE config; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.config TO PUBLIC;


--
-- TOC entry 5279 (class 0 OID 0)
-- Dependencies: 225
-- Name: TABLE distributions; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.distributions TO PUBLIC;


--
-- TOC entry 5283 (class 0 OID 0)
-- Dependencies: 223
-- Name: TABLE documents; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.documents TO PUBLIC;


--
-- TOC entry 5294 (class 0 OID 0)
-- Dependencies: 218
-- Name: TABLE municipalities; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.municipalities TO PUBLIC;


--
-- TOC entry 5296 (class 0 OID 0)
-- Dependencies: 238
-- Name: TABLE notifications; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.notifications TO PUBLIC;


--
-- TOC entry 5299 (class 0 OID 0)
-- Dependencies: 227
-- Name: TABLE qr_logs; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.qr_logs TO PUBLIC;


--
-- TOC entry 5302 (class 0 OID 0)
-- Dependencies: 236
-- Name: TABLE schedules; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.schedules TO PUBLIC;


--
-- TOC entry 5304 (class 0 OID 0)
-- Dependencies: 231
-- Name: TABLE signup_slots; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.signup_slots TO PUBLIC;


--
-- TOC entry 5312 (class 0 OID 0)
-- Dependencies: 219
-- Name: TABLE students; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.students TO PUBLIC;


-- Completed on 2025-09-29 10:33:59

--
-- PostgreSQL database dump complete
--

