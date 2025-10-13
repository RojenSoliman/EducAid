<?php
require_once __DIR__ . '/../../includes/CSRFProtection.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}
include '../../config/database.php';
require_once __DIR__ . '/../../phpmailer/vendor/autoload.php';

// Resolve current admin's municipality context
$adminMunicipalityId = null;
$adminMunicipalityName = '';
$adminId = $_SESSION['admin_id'] ?? null;
$adminUsername = $_SESSION['admin_username'] ?? null;
if ($adminId) {
    $admRes = pg_query_params($connection, "SELECT a.municipality_id, a.role, COALESCE(m.name,'') AS municipality_name FROM admins a LEFT JOIN municipalities m ON m.municipality_id = a.municipality_id WHERE a.admin_id = $1 LIMIT 1", [$adminId]);
} elseif ($adminUsername) {
    // Fallback to username if admin_id is not available in session
    $admRes = pg_query_params($connection, "SELECT a.municipality_id, a.role, COALESCE(m.name,'') AS municipality_name FROM admins a LEFT JOIN municipalities m ON m.municipality_id = a.municipality_id WHERE a.username = $1 LIMIT 1", [$adminUsername]);
} else {
    $admRes = false;
}
if ($admRes && pg_num_rows($admRes)) {
    $admRow = pg_fetch_assoc($admRes);
    $adminMunicipalityId = $admRow['municipality_id'] ? intval($admRow['municipality_id']) : null;
    $adminMunicipalityName = $admRow['municipality_name'] ?? '';
    if (empty($_SESSION['admin_role']) && !empty($admRow['role'])) {
        $_SESSION['admin_role'] = $admRow['role'];
    }
}

// --- Migration helpers ---
function rand_password_12() {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $pwd = '';
    for ($i=0; $i<12; $i++) $pwd .= $chars[random_int(0, strlen($chars)-1)];
    return $pwd;
}

function to_bdate_from_age($age) {
    $age = trim((string)$age);
    if ($age === '') return null;
    // If looks like a date string
    if (preg_match('/\\d{4}-\\d{2}-\\d{2}|\\d{1,2}[\\/\\-]\\d{1,2}[\\/\\-]\\d{2,4}/', $age)) {
        $ts = strtotime($age);
        return $ts ? date('Y-m-d', $ts) : null;
    }
    // If looks like Excel serial
    if (ctype_digit($age) && (int)$age > 20000 && (int)$age < 60000) {
        $base = (int)$age;
        $unix = ($base - 25569) * 86400; // Excel to Unix
        return date('Y-m-d', $unix);
    }
    // If numeric years
    if (is_numeric($age)) {
        $years = (int)$age;
        if ($years < 5 || $years > 100) return null;
        $y = (int)date('Y') - $years;
        return sprintf('%04d-06-15', $y); // mid-year default
    }
    return null;
}

function map_gender($g) {
    $g = strtolower(trim((string)$g));
    if (in_array($g, ['m','male'])) return 'Male';
    if (in_array($g, ['f','female'])) return 'Female';
    return null;
}

function normalize_str($s) { return strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/i',' ', (string)$s)))); }

function find_best_match($needle, $rows, $field) {
    $needleN = normalize_str($needle);
    $best = null; $bestScore = 0;
    foreach ($rows as $r) {
        $val = normalize_str($r[$field] ?? '');
        if ($val === $needleN) return $r; // exact
        similar_text($needleN, $val, $pct);
        if ($pct > $bestScore) { $bestScore = $pct; $best = $r; }
    }
    return $bestScore >= 70 ? $best : null;
}

// Barangay-specific matcher: strips common prefixes (brgy, barangay) and uses a slightly lower threshold
function find_best_barangay($needle, $rows) {
    $needle = preg_replace('/\b(brgy|barangay|bgry|bgy)\b\.?/i', ' ', (string)$needle);
    $needle = normalize_str($needle);
    $best = null; $bestScore = 0;
    foreach ($rows as $r) {
        $val = normalize_str($r['name'] ?? '');
        if ($val === $needle) return $r; // exact after cleanup
        // containments
        if ($val && $needle && (str_contains($val, $needle) || str_contains($needle, $val))) {
            // prefer longer match
            $score = 95 - abs(strlen($val) - strlen($needle));
            if ($score > $bestScore) { $bestScore = $score; $best = $r; }
            continue;
        }
        similar_text($needle, $val, $pct);
        if ($pct > $bestScore) { $bestScore = $pct; $best = $r; }
    }
    return $bestScore >= 60 ? $best : null;
}

function generateUniqueStudentId_admin($connection, $year_level_id) {
    // Map year_level_id to code number (1..4...), fallback 0
    $code = '0';
    $res = pg_query_params($connection, "SELECT code FROM year_levels WHERE year_level_id = $1", [$year_level_id]);
    if ($res && pg_num_rows($res)) { $row = pg_fetch_assoc($res); $code = preg_replace('/[^0-9]/','',$row['code'] ?? '0'); if ($code==='') $code='0'; }
    $current_year = date('Y');
    $max_attempts = 100; $attempts = 0; $exists = true; $unique_id = '';
    while ($exists && $attempts < $max_attempts) {
        $random_digits = str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $unique_id = $current_year . '-' . $code . '-' . $random_digits;
        $check = pg_query_params($connection, "SELECT 1 FROM students WHERE student_id = $1", [$unique_id]);
        $exists = $check && pg_num_rows($check) > 0; $attempts++;
    }
    return $exists ? null : $unique_id;
}

function send_migration_email($toEmail, $toName, $passwordPlain) {
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Server settings - using same configuration as OTPService
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'dilucayaka02@gmail.com';
        $mail->Password   = 'jlld eygl hksj flvg';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        // Recipients
        $mail->setFrom('dilucayaka02@gmail.com', 'EducAid System');
        $mail->addAddress($toEmail, $toName ?: $toEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Welcome to EducAid - Your Account Has Been Created';
        
        $loginUrl = (isset($_SERVER['HTTPS'])?'https':'http') . '://' . $_SERVER['HTTP_HOST'] . '/EducAid/unified_login.php';
        
        $mail->Body = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f8f9fa;">
            <div style="background-color: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <div style="text-align: center; margin-bottom: 30px;">
                    <h1 style="color: #1182FF; margin: 0; font-size: 28px;">Welcome to EducAid!</h1>
                    <p style="color: #6c757d; margin: 10px 0 0 0;">Your educational assistance account is ready</p>
                </div>
                
                <div style="background-color: #e3f2fd; padding: 20px; border-radius: 6px; margin-bottom: 25px;">
                    <h3 style="color: #1976d2; margin: 0 0 15px 0;">📧 Your Login Credentials</h3>
                    <p style="margin: 8px 0;"><strong>Email:</strong> ' . htmlspecialchars($toEmail) . '</p>
                    <p style="margin: 8px 0;"><strong>Temporary Password:</strong> <code style="background: #fff; padding: 4px 8px; border-radius: 4px; color: #d32f2f; font-weight: bold;">' . htmlspecialchars($passwordPlain) . '</code></p>
                </div>
                
                <div style="background-color: #fff3cd; padding: 20px; border-radius: 6px; margin-bottom: 25px; border-left: 4px solid #ffc107;">
                    <h3 style="color: #856404; margin: 0 0 15px 0;">⚠️ Important Security Notice</h3>
                    <ul style="margin: 0; padding-left: 20px; color: #856404;">
                        <li>Keep your password confidential - never share it with anyone</li>
                        <li>You will need to verify with a One-Time Password (OTP) during your first login</li>
                        <li>Please change your password after your first successful login</li>
                    </ul>
                </div>
                
                <div style="background-color: #d4edda; padding: 20px; border-radius: 6px; margin-bottom: 25px; border-left: 4px solid #28a745;">
                    <h3 style="color: #155724; margin: 0 0 15px 0;">📋 Next Steps - Required Documents</h3>
                    <p style="color: #155724; margin: 0 0 10px 0;">After logging in, please upload these required documents:</p>
                    <ul style="margin: 0; padding-left: 20px; color: #155724;">
                        <li><strong>Educational Assistance Form (EAF)</strong> - Completed and signed</li>
                        <li><strong>Letter to Mayor</strong> - Formal request letter</li>
                        <li><strong>Certificate of Indigency</strong> - From your barangay</li>
                        <li><strong>Academic Grades</strong> - Recent transcript or report card</li>
                    </ul>
                    <p style="color: #155724; margin: 15px 0 0 0; font-style: italic;">Your application will be reviewed once all documents are uploaded.</p>
                </div>
                
                <div style="text-align: center; margin: 30px 0;">
                    <a href="' . htmlspecialchars($loginUrl) . '" style="background-color: #1182FF; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;">🔐 Login to EducAid</a>
                </div>
                
                <div style="border-top: 1px solid #dee2e6; padding-top: 20px; margin-top: 30px; text-align: center; color: #6c757d; font-size: 14px;">
                    <p>If you have any questions, please contact your local EducAid administrator.</p>
                    <p style="margin: 5px 0 0 0;">This is an automated message. Please do not reply to this email.</p>
                </div>
            </div>
        </div>';
        
        $mail->AltBody = "Welcome to EducAid!\n\n" .
            "Your account has been created successfully.\n\n" .
            "Login Details:\n" .
            "Email: " . $toEmail . "\n" .
            "Temporary Password: " . $passwordPlain . "\n\n" .
            "IMPORTANT SECURITY NOTICE:\n" .
            "- Keep your password confidential\n" .
            "- You will need OTP verification on first login\n" .
            "- Change your password after first login\n\n" .
            "REQUIRED DOCUMENTS TO UPLOAD:\n" .
            "1. Educational Assistance Form (EAF)\n" .
            "2. Letter to Mayor\n" .
            "3. Certificate of Indigency\n" .
            "4. Academic Grades\n\n" .
            "Login here: " . $loginUrl . "\n\n" .
            "Contact your local EducAid administrator for assistance.";
            
        $mail->send();
        return true;
    } catch (Exception $e) { return false; }
}

// Handle Migration POST actions
$migration_preview = $_SESSION['migration_preview'] ?? null;
$migration_result = $_SESSION['migration_result'] ?? null;

// Generate CSRF token for CSV migration
$csrfMigrationToken = CSRFProtection::generateToken('csv_migration');

// Generate CSRF tokens for applicant approval flows
$csrfApproveApplicantToken = CSRFProtection::generateToken('approve_applicant');
$csrfOverrideApplicantToken = CSRFProtection::generateToken('override_applicant');
$csrfRejectApplicantToken = CSRFProtection::generateToken('reject_applicant');

// Clear migration sessions on GET request to prevent resubmission warnings
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['clear_migration'])) {
    // If a preview still exists, only clear the result so remaining rows persist
    if (!empty($_SESSION['migration_preview'])) {
        unset($_SESSION['migration_result']);
    } else {
        unset($_SESSION['migration_preview']);
        unset($_SESSION['migration_result']);
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['migration_action'])) {
    // CSRF Protection - validate token first
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRFProtection::validateToken('csv_migration', $token)) {
        $_SESSION['error'] = 'Security validation failed. Please refresh the page.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    if ($_POST['migration_action'] === 'preview' && isset($_FILES['csv_file'])) {
        $municipality_id = intval($adminMunicipalityId ?? 0);
        if (!$municipality_id) { $municipality_id = intval($_POST['municipality_id'] ?? 0); }
        $csv = $_FILES['csv_file'];
        if ($csv['error'] === UPLOAD_ERR_OK) {
            $rows = [];
            $fh = fopen($csv['tmp_name'], 'r');
            if ($fh) {
                $header = null; $map = [];
                // Header synonyms mapping to internal keys
                $syn = [
                    'last_name' => ['lastname','last name','surname','family name','last'],
                    'first_name'=> ['firstname','first name','given name','first','given'],
                    'middle_name'=>['middlename','middle name','mi','m.i.','middle','mname','m name'],
                    'extension_name'=>['extension','suffix','name extension','ext'],
                    'age' => ['age','years','yrs','yr'],
                    'bdate' => ['birthdate','birth date','bday','date of birth','dob'],
                    'sex' => ['sex','gender'],
                    'barangay_name'=>['barangay','brgy','bgry','bgy','village','barangay name'],
                    'university_name'=>['university','school','college','univ','institution','campus'],
                    'year_level_name'=>['year level','year','level','yr level','grade'],
                    'email'=>['email','e-mail','email address'],
                    'mobile'=>['mobile','contact number','phone','number','contact','cellphone','cp number','mobile number','phone number','contact no','contact #']
                ];
                $normalizeHeader = function($s){ return strtolower(trim(preg_replace('/\s+|\_|\-/',' ', (string)$s))); };
                $recognize = function($label) use ($syn,$normalizeHeader){
                    $l = $normalizeHeader($label);
                    foreach ($syn as $key => $arr) {
                        foreach ($arr as $cand) { if ($l === $normalizeHeader($cand)) return $key; }
                    }
                    return null;
                };

                $rowIndex = 0;
                while (($data = fgetcsv($fh)) !== false) {
                    $rowIndex++;
                    // Attempt to detect header in first row
                    if ($rowIndex === 1) {
                        $isHeader = false; $hdr = [];
                        foreach ($data as $i => $col) {
                            $key = $recognize($col);
                            if ($key) { $hdr[$key] = $i; $isHeader = true; }
                        }
                        if ($isHeader) { $header = $data; $map = $hdr; continue; }
                        // else no header, fall through to positional mapping
                    }

                    // Build row using header map if present, else positional fallback
                    $get = function($key) use ($map,$data){ return isset($map[$key]) ? ($data[$map[$key]] ?? '') : null; };
                    if ($map) {
                        $last = $get('last_name');
                        $first = $get('first_name');
                        $mid = $get('middle_name');
                        $ext = $get('extension_name');
                        $ageVal = $get('age');
                        $bdateVal = $get('bdate');
                        $gender = $get('sex');
                        $barangayName = $get('barangay_name');
                        $universityName = $get('university_name');
                        $yearLevelName = $get('year_level_name');
                        $email = $get('email');
                        $mobile = $get('mobile');
                    } else {
                        if (count($data) < 11) continue; // insufficient columns in positional mode
                        list($last,$first,$ext,$mid,$ageVal,$gender,$barangayName,$universityName,$yearLevelName,$email,$mobile) = $data;
                        $bdateVal = null;
                    }

                    // Normalize values
                    $email = trim((string)$email);
                    $mobile = preg_replace('/[^0-9]/','', (string)$mobile);
                    if (strlen($mobile) === 10) $mobile = '0' . $mobile;
                    $bdate = $bdateVal ? to_bdate_from_age($bdateVal) : to_bdate_from_age($ageVal);
                    $sex = map_gender($gender);

                    $rows[] = [
                        'first_name'=>trim((string)$first), 'middle_name'=>trim((string)$mid), 'last_name'=>trim((string)$last), 'extension_name'=>trim((string)$ext),
                        'bdate'=>$bdate, 'sex'=>$sex, 'barangay_name'=>trim((string)$barangayName), 'university_name'=>trim((string)$universityName),
                        'year_level_name'=>trim((string)$yearLevelName), 'email'=>$email, 'mobile'=>$mobile, 'municipality_id'=>$municipality_id,
                        'include'=>true,
                    ];
                }
                fclose($fh);
            }

            // Prefetch mapping tables
            $universities = pg_fetch_all(pg_query($connection, "SELECT university_id, name, COALESCE(code,'') code FROM universities")) ?: [];
            $yearLevels = pg_fetch_all(pg_query($connection, "SELECT year_level_id, name, COALESCE(code,'') code FROM year_levels")) ?: [];
            $barangays = $municipality_id ? (pg_fetch_all(pg_query_params($connection, "SELECT barangay_id, name FROM barangays WHERE municipality_id = $1", [$municipality_id])) ?: []) : [];

            // Attempt mappings and generate preview
            $preview = [];
            foreach ($rows as $r) {
                $uni = find_best_match($r['university_name'], $universities, 'name');
                if (!$uni) $uni = find_best_match($r['university_name'], $universities, 'code');
                $yl = find_best_match($r['year_level_name'], $yearLevels, 'name');
                if (!$yl) $yl = find_best_match($r['year_level_name'], $yearLevels, 'code');
                $brgy = $barangays ? find_best_barangay($r['barangay_name'], $barangays) : null;

                $conflicts = [];
                if (!$r['bdate']) $conflicts[] = 'Birthdate missing/invalid (age column)';
                if (!$r['sex']) $conflicts[] = 'Gender unknown';
                if (!$uni) $conflicts[] = 'University not recognized';
                if (!$yl) $conflicts[] = 'Year level unknown';
                if (!$brgy) $conflicts[] = 'Barangay not found';
                if (!filter_var($r['email'], FILTER_VALIDATE_EMAIL)) $conflicts[] = 'Invalid email';
                // Duplicate email/mobile check
                $dupEmail = pg_fetch_assoc(pg_query_params($connection, "SELECT 1 FROM students WHERE email = $1", [$r['email']])) ? true : false;
                $dupMobile = $r['mobile'] ? (pg_fetch_assoc(pg_query_params($connection, "SELECT 1 FROM students WHERE mobile = $1", [$r['mobile']])) ? true : false) : false;
                if ($dupEmail) $conflicts[] = 'Email already exists';
                if ($dupMobile) $conflicts[] = 'Mobile already exists';

                $preview[] = [
                    'row'=>$r,
                    'university'=>$uni, 'year_level'=>$yl, 'barangay'=>$brgy,
                    'conflicts'=>$conflicts,
                ];
            }
            $_SESSION['migration_preview'] = ['municipality_id'=>$municipality_id, 'rows'=>$preview];
            // Redirect to avoid form re-submission warnings (PRG pattern)
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    } // end preview action
    if ($_POST['migration_action'] === 'confirm') {
        if (!isset($_SESSION['migration_preview'])) {
            // Preview missing – likely session loss; set a clear result so UI shows error
            $_SESSION['migration_result'] = ['inserted'=>0, 'errors'=>['Migration preview expired or session lost. Please re-upload the CSV and try again.'], 'status'=>'error'];
        } else {
        // Verify admin password before migration
        $adminPassword = trim($_POST['admin_password'] ?? '');
        if (empty($adminPassword)) {
            $_SESSION['migration_result'] = ['inserted'=>0, 'errors'=>['Admin password verification required.'], 'status'=>'error'];
        } else {
            // Verify admin password
            $adminId = $_SESSION['admin_id'] ?? null;
            $adminUsername = $_SESSION['admin_username'] ?? null;
            $passwordValid = false;
            if ($adminId) {
                $adminQuery = pg_query_params($connection, "SELECT password FROM admins WHERE admin_id = $1", [$adminId]);
            } elseif ($adminUsername) {
                $adminQuery = pg_query_params($connection, "SELECT password FROM admins WHERE username = $1", [$adminUsername]);
            } else { $adminQuery = false; }
            if ($adminQuery && pg_num_rows($adminQuery)) {
                $adminData = pg_fetch_assoc($adminQuery);
                $passwordValid = password_verify($adminPassword, $adminData['password']);
            } else {
                if (function_exists('error_log')) { error_log('[MIGRATION] Admin lookup failed. Session admin_id=' . ($_SESSION['admin_id'] ?? 'NULL') . ', admin_username=' . ($_SESSION['admin_username'] ?? 'NULL')); }
            }
            
            if (!$passwordValid) {
                $_SESSION['migration_result'] = ['inserted'=>0, 'errors'=>['Invalid admin password.'], 'status'=>'error'];
            } else {
                $selected = $_POST['select'] ?? [];
                if (empty($selected)) {
                    $_SESSION['migration_result'] = ['inserted'=>0, 'errors'=>['No rows selected for migration.'], 'status'=>'warning'];
                } else {
            $preview = $_SESSION['migration_preview'];
            $municipality_id = intval($preview['municipality_id']);
            $inserted = 0; $errors = [];
            // Debug: log session and selection size
            if (function_exists('error_log')) {
                error_log('[MIGRATION] Confirm started: session ok, selected=' . count($selected) . ', muni=' . $municipality_id);
            }
            foreach ($preview['rows'] as $idx => $row) {
                if (!isset($selected[(string)$idx])) continue; // not selected
                $r = $row['row']; $uni = $row['university']; $yl = $row['year_level']; $brgy = $row['barangay'];
                if (!$r['bdate'] || !$r['sex'] || !$uni || !$yl || !$brgy || !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) { $errors[] = "Row #$idx has unresolved fields"; continue; }
                // generate password
                $plain = rand_password_12(); $hashed = password_hash($plain, PASSWORD_DEFAULT);
                // student id
                $stud_id = generateUniqueStudentId_admin($connection, $yl['year_level_id']);
                if (!$stud_id) { $errors[] = "Row #$idx could not generate student id"; continue; }
                // insert
                $insert = pg_query_params($connection, "INSERT INTO students (student_id, municipality_id, first_name, middle_name, last_name, extension_name, email, mobile, password, sex, status, payroll_no, qr_code, has_received, application_date, bdate, barangay_id, university_id, year_level_id, slot_id) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,'applicant',0,0,FALSE,NOW(),$11,$12,$13,$14,$15)", [
                    $stud_id, $municipality_id, $r['first_name'], $r['middle_name'], $r['last_name'], $r['extension_name'], $r['email'], $r['mobile'], $hashed, $r['sex'], $r['bdate'], $brgy['barangay_id'], $uni['university_id'], $yl['year_level_id'], null
                ]);
                if ($insert) {
                    $inserted++;
                    send_migration_email($r['email'], $r['first_name'] . ' ' . $r['last_name'], $plain);
                } else {
                    $dbErr = pg_last_error($connection);
                    $errors[] = "Row #$idx DB error: " . $dbErr;
                    if (function_exists('error_log')) { error_log('[MIGRATION] Insert failed for row #' . $idx . ': ' . $dbErr); }
                }
            }
            // Rebuild preview with rows that were NOT selected or that failed
            $remaining = [];
            // Build a set of row indices that failed during insert (from error messages)
            $failedIndices = [];
            foreach ($errors as $er) {
                if (preg_match('/Row\s+#(\d+)/', $er, $m)) {
                    $failedIndices[(string)$m[1]] = true;
                }
            }

            foreach ($preview['rows'] as $idx => $row) {
                $wasSelected = isset($selected[(string)$idx]);
                // Consider a row as failed if it was selected but appears in the failedIndices
                $hadError = $wasSelected && isset($failedIndices[(string)$idx]);
                if (!$wasSelected || $hadError) { $remaining[] = $row; }
            }

            if (!empty($remaining)) {
                $_SESSION['migration_preview'] = ['municipality_id'=>$municipality_id, 'rows'=>$remaining];
            } else {
                unset($_SESSION['migration_preview']);
            }

            $_SESSION['migration_result'] = [
                'inserted'=>$inserted, 
                'errors'=>$errors,
                'status' => $inserted > 0 ? 'success' : 'error'
            ];
            // If client expects JSON (AJAX), respond with the result now and exit
            if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
                header('Content-Type: application/json');
                echo json_encode($_SESSION['migration_result']);
                // Do not unset here; GET will display and then unset
                exit;
            }
            // Don't redirect after confirm - let the modal show results
            // header('Location: ' . $_SERVER['PHP_SELF']);
            // exit;
                }
            }
        }
        } // close else (preview exists)
        // end else preview exists
    }
    // end confirm action
    if ($_POST['migration_action'] === 'cancel') {
        unset($_SESSION['migration_preview']);
        unset($_SESSION['migration_result']);
        // For XHR cancel, return quickly without rendering
        http_response_code(204);
        exit;
    }
} // end POST migration_action handler
// End migration_action POST handler

// Normalize a string for comparison (letters only, lowercase)
function _normalize_token($s) {
    return preg_replace('/[^a-z]/', '', strtolower($s ?? ''));
}

// Find newest file in a folder that matches both first and last name (case-insensitive)
function find_student_documents($first_name, $last_name) {
    $server_base = dirname(__DIR__, 2) . '/assets/uploads/student/'; // absolute server path
    $web_base    = '../../assets/uploads/student/';                   // web path from this PHP file

    $first = _normalize_token($first_name);
    $last  = _normalize_token($last_name);

    $document_types = [
        'eaf' => 'enrollment_forms',
        'letter_to_mayor' => 'letter_to_mayor',
        'certificate_of_indigency' => 'indigency'
    ];

    $found = [];
    foreach ($document_types as $type => $folder) {
        $dir = $server_base . $folder . '/';
        if (!is_dir($dir)) continue;

        // Scan all files and pick the newest that contains both name tokens
        $matches = [];
        foreach (glob($dir . '*.*') as $file) {
            $base = pathinfo($file, PATHINFO_FILENAME);
            $baseNorm = _normalize_token($base);
            if ($first && $last && strpos($baseNorm, $first) !== false && strpos($baseNorm, $last) !== false) {
                $matches[filemtime($file)] = $file;
            }
        }

        if (!empty($matches)) {
            krsort($matches); // newest first
            $picked = reset($matches);
            $found[$type] = $web_base . $folder . '/' . basename($picked);
        }
    }

    return $found;
}

// Helper to find documents by student_id by first fetching the name
function find_student_documents_by_id($connection, $student_id) {
    $res = pg_query_params($connection, "SELECT first_name, last_name FROM students WHERE student_id = $1", [$student_id]);
    if ($res && pg_num_rows($res)) {
        $row = pg_fetch_assoc($res);
        return find_student_documents($row['first_name'] ?? '', $row['last_name'] ?? '');
    }
    return [];
}

// Function to check if all required documents are uploaded
function check_documents($connection, $student_id) {
    $required = ['eaf', 'letter_to_mayor', 'certificate_of_indigency'];
    
    // Check if student needs upload tab (existing student) or uses registration docs (new student)
    $student_info_query = pg_query_params($connection, 
        "SELECT needs_document_upload, application_date FROM students WHERE student_id = $1", 
        [$student_id]
    );
    $student_info = pg_fetch_assoc($student_info_query);
    $needs_upload_tab = $student_info ? (bool)$student_info['needs_document_upload'] : true;
    
    $uploaded = [];
    
    if ($needs_upload_tab) {
        // Existing student: check upload_documents table/system
        $query = pg_query_params($connection, "SELECT type FROM documents WHERE student_id = $1", [$student_id]);
        while ($row = pg_fetch_assoc($query)) $uploaded[] = $row['type'];
        
        // Also check file system for new structure by student name
        $found_documents = find_student_documents_by_id($connection, $student_id);
        $uploaded = array_unique(array_merge($uploaded, array_keys($found_documents)));
        
        // Check if grades are uploaded via upload system
        $grades_query = pg_query_params($connection, "SELECT COUNT(*) as count FROM grade_uploads WHERE student_id = $1", [$student_id]);
        $grades_row = pg_fetch_assoc($grades_query);
        $has_grades = $grades_row['count'] > 0;
    } else {
        // New student: check registration documents (temp files moved to permanent storage)
        $registration_docs = find_student_documents_by_id($connection, $student_id);
        $uploaded = array_keys($registration_docs);
        
        // For new registrants, check if they have temporary grade files or completed registration
        // They should have uploaded grades during registration process
        $has_grades = in_array('grades', $uploaded) || 
                     file_exists("../../assets/uploads/student/" . $student_id . "/grades/");
    }
    
    return count(array_diff($required, $uploaded)) === 0 && $has_grades;
}

// Pagination & Filtering logic
$page = max(1, intval($_GET['page'] ?? $_POST['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;
$sort = $_GET['sort'] ?? $_POST['sort'] ?? 'asc';
$search = trim($_GET['search_surname'] ?? $_POST['search_surname'] ?? '');

$where = "status = 'applicant'";
$params = [];
if ($search) {
    $where .= " AND last_name ILIKE $1";
    $params[] = "%$search%";
}
$countQuery = "SELECT COUNT(*) FROM students WHERE $where";
$totalApplicants = pg_fetch_assoc(pg_query_params($connection, $countQuery, $params))['count'];
$totalPages = max(1, ceil($totalApplicants / $perPage));

$query = "SELECT * FROM students WHERE $where ORDER BY last_name " . ($sort === 'desc' ? 'DESC' : 'ASC') . " LIMIT $perPage OFFSET $offset";
$applicants = $params ? pg_query_params($connection, $query, $params) : pg_query($connection, $query);

// Table rendering function with live preview
function render_table($applicants, $connection) {
    global $csrfApproveApplicantToken, $csrfRejectApplicantToken, $csrfOverrideApplicantToken;
    ob_start();
    ?>
    <table class="table table-bordered align-middle">
        <thead>
            <tr>
                <th>Name</th>
                <th>Contact</th>
                <th>Email</th>
                <th>Documents</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody id="applicantsTableBody">
        <?php if (pg_num_rows($applicants) === 0): ?>
            <tr><td colspan="5" class="text-center no-applicants">No applicants found.</td></tr>
        <?php else: ?>
            <?php while ($applicant = pg_fetch_assoc($applicants)) {
                $student_id = $applicant['student_id'];
                $isComplete = check_documents($connection, $student_id);
                ?>
                <tr>
                    <td data-label="Name">
                        <?= htmlspecialchars("{$applicant['last_name']}, {$applicant['first_name']} {$applicant['middle_name']}") ?>
                    </td>
                    <td data-label="Contact">
                        <?= htmlspecialchars($applicant['mobile']) ?>
                    </td>
                    <td data-label="Email">
                        <?= htmlspecialchars($applicant['email']) ?>
                    </td>
                    <td data-label="Documents">
                        <span class="badge <?= $isComplete ? 'badge-success' : 'badge-secondary' ?>">
                            <?= $isComplete ? 'Complete' : 'Incomplete' ?>
                        </span>
                    </td>
                    <td data-label="Action">
                        <button class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#modal<?= $student_id ?>">
                            <i class="bi bi-eye"></i> View
                        </button>
                        <?php if ($_SESSION['admin_role'] === 'super_admin'): ?>
                        <button class="btn btn-danger btn-sm ms-1" 
                                onclick="showBlacklistModal('<?= $student_id ?>', '<?= htmlspecialchars($applicant['first_name'] . ' ' . $applicant['last_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($applicant['email'], ENT_QUOTES) ?>', {
                                    barangay: '<?= htmlspecialchars($applicant['barangay'] ?? 'N/A', ENT_QUOTES) ?>',
                                    status: 'Applicant'
                                })"
                                title="Blacklist Student">
                            <i class="bi bi-shield-exclamation"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <!-- Modal -->
                <div class="modal fade" id="modal<?= $student_id ?>" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Documents for <?= htmlspecialchars($applicant['first_name']) ?> <?= htmlspecialchars($applicant['last_name']) ?></h5>
                                <button class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <?php
                                // First, get documents from database
                                $docs = pg_query_params($connection, "SELECT * FROM documents WHERE student_id = $1", [$student_id]);
                                $db_documents = [];
                                while ($doc = pg_fetch_assoc($docs)) {
                                    $db_documents[$doc['type']] = $doc['file_path'];
                                }

                                // Then, search for documents in new file structure by applicant name
                                $found_documents = find_student_documents($applicant['first_name'] ?? '', $applicant['last_name'] ?? '');

                                // Merge both sources, prioritizing new file structure
                                $all_documents = array_merge($db_documents, $found_documents);

                                $document_labels = [
                                    'eaf' => 'EAF',
                                    'letter_to_mayor' => 'Letter to Mayor',
                                    'certificate_of_indigency' => 'Certificate of Indigency'
                                ];

                                // Build cards grid
                                echo "<div class='doc-grid'>";
                                $has_documents = false;
                                foreach ($document_labels as $type => $label) {
                                    $cardTitle = htmlspecialchars($label);
                                    if (isset($all_documents[$type])) {
                                        $has_documents = true;
                                        $filePath = $all_documents[$type];

                                        // Resolve server path for metadata
                                        $server_root = dirname(__DIR__, 2);
                                        $relative_from_root = ltrim(str_replace('../../', '', $filePath), '/');
                                        $server_path = $server_root . '/' . $relative_from_root;

                                        $is_image = preg_match('/\.(jpg|jpeg|png|gif)$/i', $filePath);
                                        $is_pdf   = preg_match('/\.pdf$/i', $filePath);

                                        $size_str = '';
                                        $date_str = '';
                                        if (file_exists($server_path)) {
                                            $size = filesize($server_path);
                                            $units = ['B','KB','MB','GB'];
                                            $pow = $size > 0 ? floor(log($size, 1024)) : 0;
                                            $size_str = number_format($size / pow(1024, $pow), $pow ? 2 : 0) . ' ' . $units[$pow];
                                            $date_str = date('M d, Y h:i A', filemtime($server_path));
                                        }

                                        $thumbHtml = $is_image
                                            ? "<img src='" . htmlspecialchars($filePath) . "' class='doc-thumb' alt='$cardTitle'>"
                                            : "<div class='doc-thumb doc-thumb-pdf'><i class='bi bi-file-earmark-pdf'></i></div>";

                                        $safeSrc = htmlspecialchars($filePath);
                                        echo "<div class='doc-card'>
                                                <div class='doc-card-header'>$cardTitle</div>
                                                <div class='doc-card-body' onclick=\"openDocumentViewer('$safeSrc','$cardTitle')\">$thumbHtml</div>
                                                <div class='doc-meta'>" .
                                                    ($date_str ? "<span><i class='bi bi-calendar-event me-1'></i>$date_str</span>" : "") .
                                                    ($size_str ? "<span><i class='bi bi-hdd me-1'></i>$size_str</span>" : "") .
                                                "</div>
                                                <div class='doc-actions'>
                                                    <button type='button' class='btn btn-sm btn-primary' onclick=\"openDocumentViewer('$safeSrc','$cardTitle')\"><i class='bi bi-eye me-1'></i>View</button>
                                                    <a class='btn btn-sm btn-outline-secondary' href='$safeSrc' target='_blank'><i class='bi bi-box-arrow-up-right me-1'></i>Open</a>
                                                    <a class='btn btn-sm btn-outline-success' href='$safeSrc' download><i class='bi bi-download me-1'></i>Download</a>
                                                </div>
                                              </div>";
                                    } else {
                                        echo "<div class='doc-card doc-card-missing'>
                                                <div class='doc-card-header'>$cardTitle</div>
                                                <div class='doc-card-body missing'>
                                                    <div class='missing-icon'><i class='bi bi-exclamation-triangle'></i></div>
                                                    <div class='missing-text'>Not uploaded</div>
                                                </div>
                                                <div class='doc-actions'>
                                                    <span class='text-muted small'>Awaiting submission</span>
                                                </div>
                                              </div>";
                                    }
                                }
                                echo "</div>"; // end doc-grid

                                if (!$has_documents) {
                                    echo "<p class='text-muted'>No documents uploaded.</p>";
                                }

                                // Check for grades
                                $grades_query = pg_query_params($connection, "SELECT * FROM grade_uploads WHERE student_id = $1 ORDER BY upload_date DESC LIMIT 1", [$student_id]);
                                if (pg_num_rows($grades_query) > 0) {
                                    $grade_upload = pg_fetch_assoc($grades_query);
                                    echo "<hr><div class='grades-section'>";
                                    echo "<h6><i class='bi bi-file-earmark-text me-2'></i>Academic Grades</h6>";
                                    echo "<div class='d-flex justify-content-between align-items-center mb-2'>";
                                    echo "<span><strong>Status:</strong> <span class='badge bg-" . 
                                         ($grade_upload['validation_status'] === 'passed' ? 'success' : 
                                          ($grade_upload['validation_status'] === 'failed' ? 'danger' : 'warning')) . 
                                         "'>" . ucfirst($grade_upload['validation_status']) . "</span></span>";
                                    if ($grade_upload['ocr_confidence']) {
                                        echo "<span><strong>OCR Confidence:</strong> " . round($grade_upload['ocr_confidence'], 1) . "%</span>";
                                    }
                                    echo "</div>";
                                    
                                    if ($grade_upload['file_path']) {
                                        $grades_file_path = htmlspecialchars($grade_upload['file_path']);
                                        if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $grades_file_path)) {
                                            echo "<img src='$grades_file_path' alt='Grades' class='img-fluid rounded border mb-2' style='max-height: 200px; max-width: 100%;' onclick='openImageZoom(this.src, \"Grades\")'>";
                                        } elseif (preg_match('/\.pdf$/i', $grades_file_path)) {
                                            echo "<iframe src='$grades_file_path' width='100%' height='300' style='border: 1px solid #ccc;'></iframe>";
                                        }
                                    }
                                    
                                    echo "<div class='mt-2'>";
                                    echo "<a href='validate_grades.php' class='btn btn-outline-primary btn-sm'>";
                                    echo "<i class='bi bi-eye me-1'></i>Review in Grades Validator</a>";
                                    echo "</div>";
                                    echo "</div>";
                                } else {
                                    echo "<hr><div class='alert alert-warning'>";
                                    echo "<i class='bi bi-exclamation-triangle me-2'></i>";
                                    echo "<strong>Missing:</strong> Academic grades not uploaded.";
                                    echo "</div>";
                                }
                                ?>
                            </div>
                            <div class="modal-footer">
                                <?php if ($isComplete): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Verify this student?');">
                                        <input type="hidden" name="student_id" value="<?= $student_id ?>">
                                        <input type="hidden" name="mark_verified" value="1">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfApproveApplicantToken) ?>">
                                        <button class="btn btn-success btn-sm"><i class="bi bi-check-circle me-1"></i> Verify</button>
                                    </form>
                                    <form method="POST" class="d-inline ms-2" onsubmit="return confirm('Reject and reset uploads?');">
                                        <input type="hidden" name="student_id" value="<?= $student_id ?>">
                                        <input type="hidden" name="reject_applicant" value="1">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfRejectApplicantToken) ?>">
                                        <button class="btn btn-danger btn-sm"><i class="bi bi-x-circle me-1"></i> Reject</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted">Incomplete documents</span>
                                    <?php if (!empty($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'super_admin'): ?>
                                    <form method="POST" class="d-inline ms-2" onsubmit="return confirm('Override verification and mark this student as Active even without complete grades/documents?');">
                                        <input type="hidden" name="student_id" value="<?= $student_id ?>">
                                        <input type="hidden" name="mark_verified_override" value="1">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfOverrideApplicantToken) ?>">
                                        <button class="btn btn-warning btn-sm"><i class="bi bi-exclamation-triangle me-1"></i> Override Verify</button>
                                    </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php if ($_SESSION['admin_role'] === 'super_admin'): ?>
                                <div class="ms-auto">
                                    <button class="btn btn-outline-danger btn-sm" 
                                            onclick="showBlacklistModal('<?= $student_id ?>', '<?= htmlspecialchars($applicant['first_name'] . ' ' . $applicant['last_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($applicant['email'], ENT_QUOTES) ?>', {
                                                barangay: '<?= htmlspecialchars($applicant['barangay'] ?? 'N/A', ENT_QUOTES) ?>',
                                                status: 'Applicant'
                                            })"
                                            data-bs-dismiss="modal">
                                        <i class="bi bi-shield-exclamation me-1"></i> Blacklist Student
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php } ?>
        <?php endif; ?>
        </tbody>
    </table>
    <?php
    return ob_get_clean();
}

// Pagination rendering function
function render_pagination($page, $totalPages) {
    if ($totalPages <= 1) return '';
    ?>
    <nav aria-label="Table pagination" class="mt-3">
        <ul class="pagination justify-content-end">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="#" data-page="<?= $page-1 ?>">&lt;</a>
            </li>
            <li class="page-item">
                <span class="page-link">
                    Page <input type="number" min="1" max="<?= $totalPages ?>" value="<?= $page ?>" id="manualPage" style="width:55px; text-align:center;" /> of <?= $totalPages ?>
                </span>
            </li>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="#" data-page="<?= $page+1 ?>">&gt;</a>
            </li>
        </ul>
    </nav>
    <?php
}

// Handle verify/reject actions before AJAX or page render
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $applicantCsrfAction = null;
    if (!empty($_POST['mark_verified']) && isset($_POST['student_id'])) {
        $applicantCsrfAction = 'approve_applicant';
    } elseif (!empty($_POST['mark_verified_override']) && isset($_POST['student_id'])) {
        $applicantCsrfAction = 'override_applicant';
    } elseif (!empty($_POST['reject_applicant']) && isset($_POST['student_id'])) {
        $applicantCsrfAction = 'reject_applicant';
    }

    if ($applicantCsrfAction !== null) {
        $token = $_POST['csrf_token'] ?? '';
        if (!CSRFProtection::validateToken($applicantCsrfAction, $token)) {
            $_SESSION['error'] = 'Security validation failed. Please refresh the page and try again.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }

    // Verify student
    if (!empty($_POST['mark_verified']) && isset($_POST['student_id'])) {
        $sid = trim($_POST['student_id']); // Remove intval for TEXT student_id
        
        // Get student name for notification
        $studentQuery = pg_query_params($connection, "SELECT first_name, last_name FROM students WHERE student_id = $1", [$sid]);
        $student = pg_fetch_assoc($studentQuery);
        
        /** @phpstan-ignore-next-line */
        pg_query_params($connection, "UPDATE students SET status = 'active' WHERE student_id = $1", [$sid]);
        
        // Add admin notification
        if ($student) {
            $student_name = $student['first_name'] . ' ' . $student['last_name'];
            $notification_msg = "Student promoted to active: " . $student_name . " (ID: " . $sid . ")";
            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
        }
        
        // Redirect to refresh list
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    // Override verify even if incomplete (super_admin only)
    if (!empty($_POST['mark_verified_override']) && isset($_POST['student_id'])) {
        if (!empty($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'super_admin') {
            $sid = trim($_POST['student_id']);
            // Get student name for notification
            $studentQuery = pg_query_params($connection, "SELECT first_name, last_name FROM students WHERE student_id = $1", [$sid]);
            $student = pg_fetch_assoc($studentQuery);

            /** @phpstan-ignore-next-line */
            pg_query_params($connection, "UPDATE students SET status = 'active' WHERE student_id = $1", [$sid]);

            // Add admin notification noting override
            if ($student) {
                $student_name = $student['first_name'] . ' ' . $student['last_name'];
                $notification_msg = "OVERRIDE: Student promoted to active without complete grades/docs: " . $student_name . " (ID: " . $sid . ")";
                pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
            }
        }
        // Redirect to refresh list
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    // Reject applicant and reset documents
    if (!empty($_POST['reject_applicant']) && isset($_POST['student_id'])) {
        $sid = trim($_POST['student_id']); // Remove intval for TEXT student_id
        
        // Get student name for notification
        $studentQuery = pg_query_params($connection, "SELECT first_name, last_name FROM students WHERE student_id = $1", [$sid]);
        $student = pg_fetch_assoc($studentQuery);
        
        // Delete uploaded files
        /** @phpstan-ignore-next-line */
        $docs = pg_query_params($connection, "SELECT file_path FROM documents WHERE student_id = $1", [$sid]);
        while ($d = pg_fetch_assoc($docs)) {
            $path = $d['file_path'];
            if ($path && file_exists($path)) {
                @unlink($path);
            }
        }
        /** @phpstan-ignore-next-line */
        pg_query_params($connection, "DELETE FROM documents WHERE student_id = $1", [$sid]);
        
        // Student notification
        $msg = 'Your uploaded documents were rejected on ' . date('F j, Y, g:i a') . '. Please re-upload.';
        /** @phpstan-ignore-next-line */
        pg_query_params($connection, "INSERT INTO notifications (student_id, message) VALUES ($1, $2)", [$sid, $msg]);
        
        // Add admin notification
        if ($student) {
            $student_name = $student['first_name'] . ' ' . $student['last_name'];
            $notification_msg = "Documents rejected for applicant: " . $student_name . " (ID: " . $sid . ")";
            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
        }
        
        // Redirect to refresh list
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}
// --------- AJAX handler ---------
if ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '' === 'XMLHttpRequest' || (isset($_GET['ajax']) && $_GET['ajax'] === '1')) {
    // Return table content and stats for real-time updates
    ob_start();
    ?>
    <div class="section-header mb-3 d-flex justify-content-between align-items-center">
        <h2 class="fw-bold text-primary mb-0">
            <i class="bi bi-person-vcard"></i>
            Manage Applicants
        </h2>
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#migrationModal">
                <i class="bi bi-upload me-1"></i> Migrate from CSV
            </button>
            <span class="badge bg-info fs-6"><?php echo $totalApplicants; ?> Total Applicants</span>
        </div>
    </div>
    <?php
    echo render_table($applicants, $connection);
    render_pagination($page, $totalPages);
    echo ob_get_clean();
    exit;
}

// Normal page output below...
?>
<?php $page_title='Manage Applicants'; $extra_css=['../../assets/css/admin/manage_applicants.css']; include '../../includes/admin/admin_head.php'; ?>
<body>
<?php include '../../includes/admin/admin_topbar.php'; ?>
<div id="wrapper" class="admin-wrapper">
    <?php include '../../includes/admin/admin_sidebar.php'; ?>
    <?php include '../../includes/admin/admin_header.php'; ?>
    <section class="home-section" id="mainContent">
    <div class="container-fluid py-4 px-4">
            <div class="section-header mb-3 d-flex justify-content-between align-items-center">
                <h2 class="fw-bold text-primary mb-0">
                    <i class="bi bi-person-vcard" ></i>
                    Manage Applicants
                </h2>
                <div class="d-flex align-items-center gap-2">
                    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#migrationModal">
                        <i class="bi bi-upload me-1"></i> Migrate from CSV
                    </button>
                    <span class="badge bg-info fs-6"><?php echo $totalApplicants; ?> Total Applicants</span>
                </div>
            </div>
      <!-- Filter Container -->
      <div class="filter-container card shadow-sm mb-4 p-3">
        <form class="row g-3" id="filterForm" method="GET">
          <div class="col-sm-4">
            <label class="form-label fw-bold" style="color:#1182FF;">Sort by Surname</label>
            <select name="sort" class="form-select">
              <option value="asc" <?= $sort === 'asc' ? 'selected' : '' ?>>A to Z</option>
              <option value="desc" <?= $sort === 'desc' ? 'selected' : '' ?>>Z to A</option>
            </select>
          </div>
          <div class="col-sm-4">
            <label class="form-label fw-bold" style="color:#1182FF;">Search by Surname</label>
            <input type="text" name="search_surname" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Enter surname...">
          </div>
          <div class="col-sm-4 d-flex align-items-end gap-2">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i> Apply Filters</button>
            <button type="button" class="btn btn-secondary w-100" id="clearFiltersBtn">Clear</button>
          </div>
        </form>
      </div>
      <!-- Applicants Table -->
      <div class="table-responsive" id="tableWrapper">
        <?= render_table($applicants, $connection) ?>
      </div>
      <div id="pagination">
        <?php render_pagination($page, $totalPages); ?>
      </div>
    </div>
  </section>
</div>

<!-- Include Blacklist Modal -->
<?php include '../../includes/admin/blacklist_modal.php'; ?>

<!-- Migration Modal -->
<div class="modal fade" id="migrationModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title mb-0"><i class="bi bi-upload me-2"></i>CSV Migration</h5>
                            <small class="text-muted">Upload your CSV, review conflicts, select rows, then confirm to migrate.</small>
                        </div>
                                <form method="POST" class="ms-auto" id="migrationCancelForm">
                                    <input type="hidden" name="migration_action" value="cancel">
                                </form>
                                <button class="btn-close" data-bs-dismiss="modal" aria-label="Close" id="migrationCloseBtn"></button>
                    </div>
            <div class="modal-body">
        <?php /* Migration result is now shown via JS alert after reload; avoid unsetting here to prevent race */ ?>

            <form method="POST" enctype="multipart/form-data" class="mb-3" id="migrationUploadForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfMigrationToken) ?>">
                    <input type="hidden" name="migration_action" value="preview">
                                            <div class="row g-3 align-items-end">
                                                <div class="col-12 col-md-6">
                                                    <label class="form-label fw-semibold text-primary">CSV File</label>
                                                    <input type="file" name="csv_file" id="csvFileInput" class="form-control" accept=".csv" required>
                                                    <div class="form-text">Format: Lastname, firstname, extension name, middle name, age, gender, barangay, university, year level, email, number</div>
                                                    <div id="csvFilename" class="small text-muted mt-1" aria-live="polite"></div>
                                                </div>
                                                            <div class="col-12 col-md-4">
                                                                <label class="form-label fw-semibold text-primary">Municipality</label>
                                                                <?php if (!empty($adminMunicipalityId)): ?>
                                                                    <div class="form-control bg-light" disabled>
                                                                        <span class="badge bg-secondary-subtle text-dark border"><?= htmlspecialchars($adminMunicipalityName ?: 'Unknown') ?></span>
                                                                    </div>
                                                                    <input type="hidden" name="municipality_id" value="<?= htmlspecialchars((string)$adminMunicipalityId) ?>">
                                                                <?php else: ?>
                                                                    <select name="municipality_id" class="form-select" required>
                                                                        <option value="" disabled selected>Select municipality</option>
                                                                        <?php $munis = pg_fetch_all(pg_query($connection, "SELECT municipality_id,name FROM municipalities ORDER BY name")) ?: [];
                                                                            foreach ($munis as $m) echo '<option value="'.$m['municipality_id'].'">'.htmlspecialchars($m['name']).'</option>'; ?>
                                                                    </select>
                                                                <?php endif; ?>
                                                            </div>
                                                <div class="col-12 col-md-2 text-md-end">
                                                    <button class="btn btn-primary w-100"><i class="bi bi-search me-1"></i> Preview</button>
                                                </div>
                                            </div>
                </form>

                        <?php if (!empty($_SESSION['migration_preview'])): $mp = $_SESSION['migration_preview']; ?>
                    <form method="POST" id="migrationForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfMigrationToken) ?>">
                        <input type="hidden" name="migration_action" value="confirm">
                                                        <div class="d-flex justify-content-end gap-2 mb-2 preview-scroll-controls">
                                                <button type="button" class="btn btn-outline-secondary btn-sm" id="scrollStartBtn" title="Scroll to start"><i class="bi bi-skip-backward"></i></button>
                                                <button type="button" class="btn btn-outline-secondary btn-sm" id="scrollConflictsBtn" title="Scroll to conflicts"><i class="bi bi-skip-forward"></i></button>
                                            </div>
                                            <div class="table-responsive border rounded migration-preview">
                                    <table class="table table-sm align-middle mb-0 preview-table">
                                <thead class="table-light">
                                    <tr>
                                        <th>Select</th>
                                        <th>Name</th>
                                        <th>Sex</th>
                                        <th>Bdate</th>
                                        <th>Barangay</th>
                                        <th>University</th>
                                        <th>Year Level</th>
                                        <th>Email</th>
                                        <th>Mobile</th>
                                        <th>Conflicts</th>
                                    </tr>
                                </thead>
                                <tbody>
                                        <?php foreach ($mp['rows'] as $idx => $r): $row=$r['row']; $conf=$r['conflicts']; ?>
                                            <tr class="<?= $conf? 'table-warning':'' ?>" data-has-conflict="<?= $conf? '1':'0' ?>">
                                                <td data-label="Select"><input type="checkbox" class="row-select" name="select[<?= $idx ?>]" <?= $conf? '':'checked' ?>></td>
                                                <td data-label="Name"><?= htmlspecialchars(trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['extension_name'] ?? ''))) ?></td>
                                                <td data-label="Sex"><?= htmlspecialchars($row['sex'] ?: '-') ?></td>
                                                <td data-label="Bdate"><?= htmlspecialchars($row['bdate'] ?: '-') ?></td>
                                                <td data-label="Barangay"><?= htmlspecialchars(($r['barangay']['name'] ?? ($row['barangay_name'] ?? ''))) ?></td>
                                                <td data-label="University"><?= htmlspecialchars(($r['university']['name'] ?? ($row['university_name'] ?? ''))) ?></td>
                                                <td data-label="Year Level"><?= htmlspecialchars(($r['year_level']['name'] ?? ($row['year_level_name'] ?? ''))) ?></td>
                                                <td data-label="Email"><?= htmlspecialchars($row['email'] ?? '') ?></td>
                                                <td data-label="Mobile"><?= htmlspecialchars($row['mobile'] ?? '') ?></td>
                                                <td data-label="Conflicts" class="small">
                                                    <?php if ($conf) { echo '<ul class="mb-0 ps-3">'; foreach ($conf as $c) echo '<li>'.htmlspecialchars($c).'</li>'; echo '</ul>'; } else { echo '<span class="text-success"><i class="bi bi-check2 me-1"></i>Ready</span>'; } ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                                <div class="mt-3 d-flex flex-wrap gap-2 align-items-center justify-content-between">
                                    <div class="d-flex flex-wrap gap-2">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="selectAllValidBtn"><i class="bi bi-check2-all me-1"></i> Select All Valid</button>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="showConflictsOnly">
                                            <label class="form-check-label" for="showConflictsOnly">Show conflicts only</label>
                                        </div>
                                    </div>
                                    <div class="ms-auto small text-muted" id="selectedCounter">0 selected</div>
                                </div>

                                            <div class="modal-footer justify-content-end mt-3 sticky-confirm">
                                                <button type="submit" class="btn btn-success" id="confirmMigrateBtn">
                                                  <span class="migration-btn-text"><i class="bi bi-check2-circle me-1"></i> Confirm & Migrate</span>
                                                  <span class="migration-btn-loading d-none">
                                                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                                                    Migrating... <span id="migrationProgress">0</span>/<span id="migrationTotal">0</span>
                                                  </span>
                                                </button>
                                </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Password Confirmation Modal -->
<div class="modal fade" id="passwordConfirmModal" tabindex="-1" aria-labelledby="passwordConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="passwordConfirmModalLabel"><i class="bi bi-shield-lock me-2"></i>Confirm Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Please enter your admin password to confirm this migration:</p>
                <div class="form-floating">
                    <input type="password" class="form-control" id="adminPasswordInput" placeholder="Password" required>
                    <label for="adminPasswordInput">Admin Password</label>
                </div>
                <div id="passwordError" class="alert alert-danger mt-2 d-none">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <span></span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmPasswordBtn">
                    <i class="bi bi-check2-circle me-1"></i>Confirm Migration
                </button>
            </div>
        </div>
    </div>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/admin/sidebar.js"></script>
<!-- Removed external manage_applicants.js include (404 caused script parse error) -->
<script>
// Image Zoom Functionality
function openImageZoom(imageSrc, imageTitle) {
    // Create modal if it doesn't exist
    let modal = document.getElementById('imageZoomModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'imageZoomModal';
        modal.className = 'image-zoom-modal';
        modal.innerHTML = `
            <span class="image-zoom-close" onclick="closeImageZoom()">&times;</span>
            <div class="image-zoom-content">
                <div class="image-loading">Loading...</div>
                <img id="zoomedImage" style="display: none;" alt="${imageTitle}">
            </div>
        `;
        document.body.appendChild(modal);
    }

    // Show modal
    modal.style.display = 'block';
    
    // Load image
    const img = document.getElementById('zoomedImage');
    const loading = modal.querySelector('.image-loading');
    
    img.onload = function() {
        loading.style.display = 'none';
        img.style.display = 'block';
    };
    
    img.onerror = function() {
        loading.textContent = 'Failed to load image';
    };
    
    img.src = imageSrc;
    
    // Close on background click
    modal.onclick = function(event) {
        if (event.target === modal) {
            closeImageZoom();
        }
    };
}

function closeImageZoom() {
    const modal = document.getElementById('imageZoomModal');
    if (modal) {
        modal.style.display = 'none';
        // Reset image
        const img = document.getElementById('zoomedImage');
        const loading = modal.querySelector('.image-loading');
        img.style.display = 'none';
        loading.style.display = 'block';
        loading.textContent = 'Loading...';
    }
}

// Close zoom on Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeImageZoom();
    }
});

// Real-time updates
let isUpdating = false;
let lastUpdateData = null;

function updateTableData() {
    if (isUpdating) return;
    isUpdating = true;

    const currentUrl = new URL(window.location);
    const params = new URLSearchParams(currentUrl.search);
    params.set('ajax', '1');

    fetch(window.location.pathname + '?' + params.toString())
        .then(response => response.text())
        .then(data => {
            if (data !== lastUpdateData) {
                // Parse the response to extract content
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = data;
                
                // Update section header with total count
                const newHeader = tempDiv.querySelector('.section-header');
                const currentHeader = document.querySelector('.section-header');
                if (newHeader && currentHeader) {
                    currentHeader.innerHTML = newHeader.innerHTML;
                }

                // Update table content
                const newTable = tempDiv.querySelector('table');
                const currentTable = document.querySelector('#tableWrapper table');
                if (newTable && currentTable && newTable.innerHTML !== currentTable.innerHTML) {
                    currentTable.innerHTML = newTable.innerHTML;
                }

                // Update pagination
                const newPagination = tempDiv.querySelector('nav[aria-label="Table pagination"]');
                const currentPagination = document.querySelector('#pagination nav[aria-label="Table pagination"]');
                if (newPagination && currentPagination) {
                    currentPagination.innerHTML = newPagination.innerHTML;
                } else if (newPagination && !currentPagination) {
                    document.getElementById('pagination').innerHTML = newPagination.outerHTML;
                } else if (!newPagination && currentPagination) {
                    document.getElementById('pagination').innerHTML = '';
                }

                lastUpdateData = data;
            }
        })
        .catch(error => {
            console.log('Update failed:', error);
        })
        .finally(() => {
        isUpdating = false;
        // Slow down polling to avoid racing with migrations and reduce load
        setTimeout(updateTableData, 3000); // Update every 3s
        });
}

// Start real-time updates when page loads
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(updateTableData, 300);
    // Auto-open migration modal if preview/result exists
    <?php if (!empty($_SESSION['migration_preview']) || !empty($_SESSION['migration_result'])): ?>
    const migrationModalEl = document.getElementById('migrationModal');
    if (migrationModalEl) {
        const modal = new bootstrap.Modal(migrationModalEl);
        modal.show();
    }
    <?php endif; ?>

    // Migration UI helpers
    const csvInput = document.getElementById('csvFileInput');
    const csvFilename = document.getElementById('csvFilename');
    if (csvInput && csvFilename) {
        csvInput.addEventListener('change', () => {
            const file = csvInput.files && csvInput.files[0];
            csvFilename.textContent = file ? `Selected: ${file.name} (${Math.round(file.size/1024)} KB)` : '';
        });
    }

    function updateSelectedCounter() {
        const counter = document.getElementById('selectedCounter');
        if (!counter) return;
        const checks = document.querySelectorAll('.migration-preview .row-select');
        let n = 0; checks.forEach(c => { if (c.checked) n++; });
        counter.textContent = `${n} selected`;
    }

    // Initialize selection counter and controls if preview table is present
    const previewTable = document.querySelector('.migration-preview');
    if (previewTable) {
        document.querySelectorAll('.migration-preview .row-select').forEach(cb => cb.addEventListener('change', updateSelectedCounter));
        updateSelectedCounter();

        const selectAllBtn = document.getElementById('selectAllValidBtn');
        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', () => {
                document.querySelectorAll('.migration-preview tbody tr').forEach(tr => {
                    const hasConflict = tr.getAttribute('data-has-conflict') === '1';
                    const cb = tr.querySelector('.row-select');
                    if (cb && !hasConflict) cb.checked = true;
                });
                updateSelectedCounter();
            });
        }

        const conflictToggle = document.getElementById('showConflictsOnly');
        if (conflictToggle) {
            conflictToggle.addEventListener('change', () => {
                const only = conflictToggle.checked;
                document.querySelectorAll('.migration-preview tbody tr').forEach(tr => {
                    const hasConflict = tr.getAttribute('data-has-conflict') === '1';
                    tr.style.display = (!only || hasConflict) ? '' : 'none';
                });
            });
        }

        // Horizontal scroll helpers
        const scrollWrap = document.querySelector('.migration-preview');
        const scrollStartBtn = document.getElementById('scrollStartBtn');
        const scrollConflictsBtn = document.getElementById('scrollConflictsBtn');
        function smoothScrollTo(x) {
            if (!scrollWrap) return;
            scrollWrap.scrollTo({ left: x, behavior: 'smooth' });
        }
        if (scrollStartBtn) scrollStartBtn.addEventListener('click', () => smoothScrollTo(0));
        if (scrollConflictsBtn) scrollConflictsBtn.addEventListener('click', () => smoothScrollTo(scrollWrap.scrollWidth));
    }

    // Cancel migration on modal close with confirmation
    const migrationModalEl2 = document.getElementById('migrationModal');
    let _isSubmittingMigration = false;
    // Note: Form submission is now handled by password confirmation modal
    if (migrationModalEl2) {
        migrationModalEl2.addEventListener('hide.bs.modal', function (e) {
            if (_isSubmittingMigration) { return; }
            // If there is a preview in session, confirm cancel
            <?php if (!empty($_SESSION['migration_preview'])): ?>
            const ok = confirm('Closing will cancel the migration preview. Continue?');
            if (!ok) { e.preventDefault(); return; }
            // Post cancel to clear preview
            const form = document.getElementById('migrationCancelForm');
            if (form) {
                fetch(window.location.pathname, { method: 'POST', body: new FormData(form) })
                    .then(() => { /* cleared */ })
                    .catch(() => { /* ignore */ });
            }
            <?php endif; ?>
        });
    }

    // Password confirmation for migration
    const confirmMigrateBtn = document.getElementById('confirmMigrateBtn');
    const passwordModal = document.getElementById('passwordConfirmModal');
    const adminPasswordInput = document.getElementById('adminPasswordInput');
    const confirmPasswordBtn = document.getElementById('confirmPasswordBtn');
    const passwordError = document.getElementById('passwordError');
    let pendingFormData = null;

    // Debug logging
    console.log('Elements found:', {
        confirmMigrateBtn: !!confirmMigrateBtn,
        passwordModal: !!passwordModal,
        adminPasswordInput: !!adminPasswordInput,
        confirmPasswordBtn: !!confirmPasswordBtn,
        passwordError: !!passwordError
    });

    // Show migration result alerts (only on GET). On POST (fetch), don't clear result yet.
    <?php if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_SESSION['migration_result'])): ?>
        const result = <?= json_encode($_SESSION['migration_result']) ?>;
        console.log('Migration result found:', result);
        showMigrationAlert(result);
        <?php unset($_SESSION['migration_result']); ?>
    <?php endif; ?>

    // Intercept confirm migration button click
    if (confirmMigrateBtn) {
        console.log('Migration button found, adding event listener');
        confirmMigrateBtn.addEventListener('click', function(e) {
            console.log('Migration button clicked');
            e.preventDefault();
            
            // Check if any rows are selected
            const selectedCheckboxes = document.querySelectorAll('.migration-preview .row-select:checked');
            console.log('Selected checkboxes:', selectedCheckboxes.length);
            if (selectedCheckboxes.length === 0) {
                alert('Please select at least one row to migrate.');
                return;
            }
            
            // Collect form data
            const form = document.getElementById('migrationForm');
            if (!form) {
                console.error('Migration form not found');
                alert('Error: Migration form not found. Please refresh the page.');
                return;
            }
            console.log('Migration form found:', form);
            
            pendingFormData = new FormData(form);
            pendingFormData.set('migration_action', 'confirm');
            
            // Store selected count for later use
            window.migrationSelectedCount = selectedCheckboxes.length;
            
            // Show password modal
            if (adminPasswordInput) adminPasswordInput.value = '';
            if (passwordError) passwordError.classList.add('d-none');
            
            if (passwordModal) {
                console.log('Showing password modal');
                const passwordModalInstance = new bootstrap.Modal(passwordModal);
                passwordModalInstance.show();
            } else {
                console.error('Password modal not found');
                alert('Error: Password confirmation modal not found. Please refresh the page.');
            }
        });
    } else {
        console.error('Confirm migrate button not found');
    }

    // Handle password confirmation
    if (confirmPasswordBtn) {
        confirmPasswordBtn.addEventListener('click', function() {
            const password = adminPasswordInput ? adminPasswordInput.value.trim() : '';
            if (!password) {
                showPasswordError('Please enter your password.');
                return;
            }

            // Add password to form data
            if (pendingFormData) {
                pendingFormData.set('admin_password', password);
            } else {
                console.error('No pending form data');
                showPasswordError('Error: Please try again.');
                return;
            }
            
            // Show loading state on password button
            const originalText = confirmPasswordBtn.innerHTML;
            confirmPasswordBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Verifying...';
            confirmPasswordBtn.disabled = true;
            
            // Submit the form
            fetch(window.location.href, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                body: pendingFormData
            }).then(response => {
                console.log('Migration response received:', response.status);
                if (response.ok) {
                    // Close password modal
                    if (passwordModal && bootstrap.Modal.getInstance(passwordModal)) {
                        bootstrap.Modal.getInstance(passwordModal).hide();
                    }
                    
                    // Show migration loading in original modal
                    _isSubmittingMigration = true;
                    const btn = document.getElementById('confirmMigrateBtn');
                    if (btn) {
                        btn.disabled = true;
                        const btnText = btn.querySelector('.migration-btn-text');
                        const btnLoading = btn.querySelector('.migration-btn-loading');
                        
                        if (btnText) btnText.classList.add('d-none');
                        if (btnLoading) btnLoading.classList.remove('d-none');
                        
                        const total = window.migrationSelectedCount || 1;
                        const totalEl = document.getElementById('migrationTotal');
                        if (totalEl) totalEl.textContent = total;
                        
                        // Simulate progress
                        let progress = 0;
                        const progressEl = document.getElementById('migrationProgress');
                        if (progressEl) {
                            const interval = setInterval(() => {
                                if (progress < total) {
                                    progress++;
                                    progressEl.textContent = progress;
                                } else {
                                    clearInterval(interval);
                                    console.log('Migration progress simulation complete');
                                }
                            }, 100);
                        }
                    }
                    
                    // Try to parse JSON result and show alert immediately
                    response.clone().json().then(data => {
                        try { showMigrationAlert(data); } catch (_) { /* ignore */ }
                    }).catch(() => { /* not JSON, fallback to reload */ });

                    // Reload page to show results after migration completes (fallback)
                    setTimeout(() => {
                        console.log('Reloading page to show migration results');
                        window.location.reload();
                    }, 2000);
                } else {
                    console.error('Migration request failed:', response.status);
                    throw new Error('Migration request failed with status: ' + response.status);
                }
            }).catch(error => {
                console.error('Migration error:', error);
                showPasswordError(error.message || 'Migration failed. Please try again.');
                confirmPasswordBtn.innerHTML = originalText;
                confirmPasswordBtn.disabled = false;
            });
        });
        
        // Enter key in password field
        if (adminPasswordInput) {
            adminPasswordInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    confirmPasswordBtn.click();
                }
            });
        }
    }

    function showPasswordError(message) {
        console.log('Password error:', message);
        if (passwordError && passwordError.querySelector('span')) {
            passwordError.querySelector('span').textContent = message;
            passwordError.classList.remove('d-none');
        } else {
            console.error('Password error element not found');
            alert('Error: ' + message);
        }
    }

    function showMigrationAlert(result) {
        console.log('Showing migration alert:', result);
        const alertContainer = document.createElement('div');
        alertContainer.className = 'container-fluid mt-3';
        
        let alertType, icon, title;
        if (result.status === 'success') {
            alertType = 'alert-success';
            icon = 'bi-check-circle-fill';
            title = 'Migration Successful';
        } else if (result.status === 'warning') {
            alertType = 'alert-warning';
            icon = 'bi-exclamation-triangle-fill';
            title = 'Migration Warning';
        } else {
            alertType = 'alert-danger';
            icon = 'bi-x-circle-fill';
            title = 'Migration Failed';
        }
        
        let message = '';
        if (result.inserted > 0) {
            message += `<strong>${result.inserted}</strong> student(s) successfully migrated.`;
        }
        if (result.errors && result.errors.length > 0) {
            if (message) message += '<br>';
            message += '<strong>Errors:</strong><ul class="mb-0 mt-2">';
            result.errors.forEach(error => {
                message += `<li>${error}</li>`;
            });
            message += '</ul>';
        }
        
        alertContainer.innerHTML = `
            <div class="alert ${alertType} alert-dismissible fade show" role="alert">
                <i class="bi ${icon} me-2"></i>
                <strong>${title}</strong><br>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
        
        // Insert after header
        const header = document.querySelector('.section-header');
        if (header && header.parentNode) {
            header.parentNode.insertBefore(alertContainer, header.nextSibling);
            console.log('Migration alert inserted successfully');
            
            // Auto dismiss after 10 seconds for success
            if (result.status === 'success') {
                setTimeout(() => {
                    const alert = alertContainer.querySelector('.alert');
                    if (alert) {
                        bootstrap.Alert.getOrCreateInstance(alert).close();
                    }
                }, 10000);
                
                // Clear the migration session and close modal after showing success
                setTimeout(() => {
                    clearMigrationSession();
                    const migrationModal = document.getElementById('migrationModal');
                    if (migrationModal && bootstrap.Modal.getInstance(migrationModal)) {
                        bootstrap.Modal.getInstance(migrationModal).hide();
                    }
                }, 3000);
            }
        } else {
            console.error('Header element not found for alert insertion');
        }
    }

    function clearMigrationSession() {
        // Clear migration session on server
        fetch(window.location.href + '?clear_migration=1', {
            method: 'GET'
        }).then(() => {
            console.log('Migration session cleared');
        }).catch(error => {
            console.error('Error clearing migration session:', error);
        });
    }
});
</script>
<style>
/* Document grid */
.doc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
.doc-card { border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; display: flex; flex-direction: column; }
.doc-card-header { font-weight: 600; padding: 10px 12px; border-bottom: 1px solid #f0f0f0; }
.doc-card-body { padding: 8px; display: flex; align-items: center; justify-content: center; min-height: 160px; cursor: zoom-in; background: #fafafa; }
.doc-thumb { max-width: 100%; max-height: 150px; border-radius: 4px; }
.doc-thumb-pdf { font-size: 48px; color: #d32f2f; display: flex; align-items: center; justify-content: center; height: 150px; width: 100%; }
.doc-meta { display: flex; justify-content: space-between; gap: 8px; padding: 6px 12px; color: #6b7280; font-size: 12px; border-top: 1px dashed #eee; }
.doc-actions { display: flex; gap: 6px; padding: 8px 12px; border-top: 1px solid #f0f0f0; }
.doc-card-missing .missing { background: #fff7e6; color: #8a6d3b; min-height: 160px; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.doc-card-missing .missing-icon { font-size: 28px; margin-bottom: 6px; }

/* Fullscreen viewer */
.doc-viewer-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.8); display: none; z-index: 1060; }
.doc-viewer { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 95vw; max-width: 1280px; height: 85vh; background: #111; border-radius: 8px; overflow: hidden; display: flex; flex-direction: column; }
.doc-viewer-toolbar { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; justify-content: space-between; padding: 8px 12px; background: #1f2937; color: #fff; }
.doc-viewer-toolbar .btn { padding: 4px 8px; }
.doc-viewer-content { flex: 1; background: #000; display: flex; align-items: center; justify-content: center; overflow: hidden; position: relative; }
.doc-viewer-canvas { touch-action: none; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; }
.doc-viewer-content img { will-change: transform; transform-origin: center center; user-select: none; -webkit-user-drag: none; }
.doc-viewer-content iframe { width: 100%; height: 100%; border: none; }
.doc-viewer-close { background: transparent; border: 0; color: #fff; font-size: 20px; }

@media (max-width: 576px) {
    .doc-grid { grid-template-columns: 1fr; }
    .doc-viewer { width: 100vw; height: 90vh; border-radius: 0; }
}

/* Migration preview responsive table */
.migration-preview .preview-table thead { position: sticky; top: 0; z-index: 1; }
@media (max-width: 768px) {
    .migration-preview .preview-table thead { display: none; }
    .migration-preview .preview-table tbody tr { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 12px; padding: 10px; border-bottom: 1px solid #eee; }
    .migration-preview .preview-table tbody td { display: flex; justify-content: space-between; align-items: center; border: none !important; padding: 4px 0; }
    .migration-preview .preview-table tbody td::before { content: attr(data-label); font-weight: 600; color: #1182FF; margin-right: 8px; }
    .migration-preview .preview-table tbody td[data-label="Select"] { grid-column: 1 / -1; justify-content: flex-start; }
    .migration-preview .preview-table tbody td[data-label="Conflicts"] { grid-column: 1 / -1; }
}
.sticky-confirm { position: sticky; bottom: 0; background: #fff; border-top: 1px solid #eee; }

/* Horizontal scroll improvements for preview table */
.migration-preview { overflow-x: auto; }
.migration-preview .preview-table { min-width: 1100px; }
.migration-preview .preview-table th, .migration-preview .preview-table td { white-space: nowrap; }
.migration-preview .preview-table thead th { position: sticky; top: 0; background: #f8fbff; }
.migration-preview .preview-table td[data-label="Select"],
.migration-preview .preview-table th:first-child { position: sticky; left: 0; background: #fff; z-index: 2; }
.migration-preview .preview-table td[data-label="Conflicts"],
.migration-preview .preview-table th:last-child { position: sticky; right: 0; background: #fff; z-index: 2; }

/* Hide scroll controls on small screens and improve wrapping */
@media (max-width: 768px) {
    .preview-scroll-controls { display: none; }
    .migration-preview .preview-table { min-width: 100%; }
    .migration-preview .preview-table th, .migration-preview .preview-table td { white-space: normal; }
    .migration-preview .preview-table thead th { position: static; }
}
</style>

<script>
// Lightweight document viewer (image/pdf)
function ensureDocViewer() {
    let backdrop = document.getElementById('docViewerBackdrop');
    if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.id = 'docViewerBackdrop';
        backdrop.className = 'doc-viewer-backdrop';
        backdrop.innerHTML = `
            <div class="doc-viewer">
                <div class="doc-viewer-toolbar">
                    <div id="docViewerTitle"></div>
                    <div class="d-flex flex-wrap gap-1">
                        <button id="docZoomOutBtn" class="btn btn-sm btn-outline-light" title="Zoom Out"><i class="bi bi-zoom-out"></i></button>
                        <button id="docZoomInBtn" class="btn btn-sm btn-outline-light" title="Zoom In"><i class="bi bi-zoom-in"></i></button>
                        <button id="docRotateLeftBtn" class="btn btn-sm btn-outline-light" title="Rotate Left"><i class="bi bi-arrow-counterclockwise"></i></button>
                        <button id="docRotateRightBtn" class="btn btn-sm btn-outline-light" title="Rotate Right"><i class="bi bi-arrow-clockwise"></i></button>
                        <button id="docFitWidthBtn" class="btn btn-sm btn-outline-light" title="Fit Width"><i class="bi bi-arrows-expand"></i></button>
                        <button id="docFitScreenBtn" class="btn btn-sm btn-outline-light" title="Fit Screen"><i class="bi bi-arrows-fullscreen"></i></button>
                        <button id="docResetBtn" class="btn btn-sm btn-outline-secondary" title="Reset"><i class="bi bi-arrow-repeat"></i></button>
                        <div class="vr mx-1"></div>
                        <button id="docOpenBtn" class="btn btn-sm btn-outline-light"><i class="bi bi-box-arrow-up-right"></i> Open</button>
                        <button id="docDownloadBtn" class="btn btn-sm btn-success"><i class="bi bi-download"></i> Download</button>
                        <button class="doc-viewer-close ms-1" onclick="closeDocumentViewer()">&times;</button>
                    </div>
                </div>
                <div class="doc-viewer-content">
                    <div class="doc-viewer-canvas">
                        <img id="docViewerImg" alt="preview" style="display:none;" />
                        <iframe id="docViewerPdf" style="display:none;"></iframe>
                    </div>
                </div>
            </div>`;
        document.body.appendChild(backdrop);
        backdrop.addEventListener('click', (e) => { if (e.target === backdrop) closeDocumentViewer(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeDocumentViewer(); });
    }
    return backdrop;
}

// Viewer state
let _viewState = { scale: 1, rotation: 0, originX: 0, originY: 0, panX: 0, panY: 0, isImage: false };

function applyImageTransform(img) {
    img.style.transform = `translate(${_viewState.panX}px, ${_viewState.panY}px) rotate(${_viewState.rotation}deg) scale(${_viewState.scale})`;
}

function resetView(img) {
    _viewState = { scale: 1, rotation: 0, originX: 0, originY: 0, panX: 0, panY: 0, isImage: _viewState.isImage };
    if (img) applyImageTransform(img);
}

function openDocumentViewer(src, title) {
    const backdrop = ensureDocViewer();
    const img = document.getElementById('docViewerImg');
    const pdf = document.getElementById('docViewerPdf');
    const openBtn = document.getElementById('docOpenBtn');
    const downloadBtn = document.getElementById('docDownloadBtn');
    const zoomInBtn = document.getElementById('docZoomInBtn');
    const zoomOutBtn = document.getElementById('docZoomOutBtn');
    const rotateLeftBtn = document.getElementById('docRotateLeftBtn');
    const rotateRightBtn = document.getElementById('docRotateRightBtn');
    const fitWidthBtn = document.getElementById('docFitWidthBtn');
    const fitScreenBtn = document.getElementById('docFitScreenBtn');
    const resetBtn = document.getElementById('docResetBtn');
    document.getElementById('docViewerTitle').textContent = title || 'Document';

    // Reset
    img.style.display = 'none';
    pdf.style.display = 'none';
    img.src = '';
    pdf.src = '';
    resetView(img);

    const isImage = /\.(jpg|jpeg|png|gif)$/i.test(src);
    const isPdf = /\.pdf$/i.test(src);
    _viewState.isImage = isImage;
    if (isImage) {
        img.src = src;
        img.style.display = 'block';
    } else if (isPdf) {
        pdf.src = src;
        pdf.style.display = 'block';
    }

    openBtn.onclick = () => window.open(src, '_blank');
    downloadBtn.onclick = () => { const a = document.createElement('a'); a.href = src; a.download = ''; a.click(); };

    // Controls
    function setScale(mult) { _viewState.scale = Math.min(8, Math.max(0.25, _viewState.scale * mult)); applyImageTransform(img); }
    function rotate(delta) { _viewState.rotation = (_viewState.rotation + delta + 360) % 360; applyImageTransform(img); }
    function fitWidth() {
        const container = document.querySelector('.doc-viewer-content');
        if (!container || !img.naturalWidth) return; 
        _viewState.scale = (container.clientWidth * 0.95) / img.naturalWidth; _viewState.panX = 0; _viewState.panY = 0; applyImageTransform(img);
    }
    function fitScreen() {
        const container = document.querySelector('.doc-viewer-content');
        if (!container || !img.naturalWidth || !img.naturalHeight) return; 
        const scaleX = (container.clientWidth * 0.95) / img.naturalWidth;
        const scaleY = (container.clientHeight * 0.95) / img.naturalHeight;
        _viewState.scale = Math.min(scaleX, scaleY); _viewState.panX = 0; _viewState.panY = 0; applyImageTransform(img);
    }

    zoomInBtn.onclick = () => _viewState.isImage && setScale(1.2);
    zoomOutBtn.onclick = () => _viewState.isImage && setScale(1/1.2);
    rotateLeftBtn.onclick = () => _viewState.isImage && rotate(-90);
    rotateRightBtn.onclick = () => _viewState.isImage && rotate(90);
    fitWidthBtn.onclick = () => _viewState.isImage ? fitWidth() : (pdf.src = src + '#zoom=page-width');
    fitScreenBtn.onclick = () => _viewState.isImage ? fitScreen() : (pdf.src = src + '#zoom=page-fit');
    resetBtn.onclick = () => { resetView(img); if (!isImage) pdf.src = src; };

    // Pan & wheel zoom for images
    const canvas = document.querySelector('.doc-viewer-canvas');
    let dragging = false, lastX = 0, lastY = 0;
    canvas.onpointerdown = (e) => { if (!_viewState.isImage) return; dragging = true; lastX = e.clientX; lastY = e.clientY; canvas.setPointerCapture(e.pointerId); };
    canvas.onpointermove = (e) => { if (!_viewState.isImage || !dragging) return; _viewState.panX += (e.clientX - lastX); _viewState.panY += (e.clientY - lastY); lastX = e.clientX; lastY = e.clientY; applyImageTransform(img); };
    canvas.onpointerup = () => { dragging = false; };
    canvas.onwheel = (e) => { if (!_viewState.isImage) return; e.preventDefault(); setScale(e.deltaY < 0 ? 1.1 : 1/1.1); };

    // Double-tap/double-click to toggle zoom
    let lastTap = 0;
    canvas.ondblclick = () => { if (!_viewState.isImage) return; _viewState.scale = _viewState.scale < 2 ? 2 : 1; _viewState.panX = 0; _viewState.panY = 0; applyImageTransform(img); };
    canvas.ontouchend = () => { const now = Date.now(); if (now - lastTap < 300) { canvas.ondblclick(); } lastTap = now; };

    backdrop.style.display = 'block';
}

function closeDocumentViewer() {
    const backdrop = document.getElementById('docViewerBackdrop');
    if (backdrop) backdrop.style.display = 'none';
}
</script>
</body>
</html>
<?php pg_close($connection); ?>