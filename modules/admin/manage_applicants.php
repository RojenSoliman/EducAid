<?php
require_once __DIR__ . '/../../includes/CSRFProtection.php';
session_start();
if (!isset($_SESSION['admin_username'])) {
    header("Location: ../../unified_login.php");
    exit;
}
include '../../config/database.php';

// Get workflow permissions to control approval actions
require_once __DIR__ . '/../../includes/workflow_control.php';
$workflow_status = getWorkflowStatus($connection);

require_once __DIR__ . '/../../phpmailer/vendor/autoload.php';
require_once __DIR__ . '/../../includes/util/student_id.php';

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
    // Use the standardized generator: YYYYMMDD-<yearlevel>-<sequence>
    global $adminMunicipalityId;
    $id = generateSystemStudentId($connection, intval($year_level_id), intval($adminMunicipalityId), intval(date('Y')));
    if ($id) return $id;
    // Fallback (should rarely happen): format MUNICIPALITY-YEAR-YEARLEVEL-SEQ
    $code = '0';
    $res = pg_query_params($connection, "SELECT code FROM year_levels WHERE year_level_id = $1", [intval($year_level_id)]);
    if ($res && pg_num_rows($res)) { $row = pg_fetch_assoc($res); $code = preg_replace('/[^0-9]/','',$row['code'] ?? '0'); if ($code==='') $code='0'; }
    $muniPrefix = 'MUNI' . intval($adminMunicipalityId ?: 0);
    $mr = @pg_query_params($connection, "SELECT COALESCE(NULLIF(slug,''), name) AS tag FROM municipalities WHERE municipality_id = $1", [intval($adminMunicipalityId)]);
    if ($mr && pg_num_rows($mr) > 0) { $mrow = pg_fetch_assoc($mr); $muniPrefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($mrow['tag'] ?? $muniPrefix)))); }
    return $muniPrefix . '-' . date('Y') . '-' . $code . '-' . mt_rand(1, 9999);
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
$csrfArchiveStudentToken = CSRFProtection::generateToken('archive_student');
$csrfRejectDocumentsToken = CSRFProtection::generateToken('reject_documents');

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
        'letter_to_mayor' => 'letter_mayor', // Fixed: matches DocumentReuploadService folder name
        'certificate_of_indigency' => 'indigency',
        'grades' => 'grades' // Map to 'grades' key for consistency
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

// Scan the students upload directory for files following the pattern {student_id}_{token}_{timestamp}.{ext}
// Supports tokens: eaf, letter, indigency, id, grades (grades handled separately in UI)
function find_student_documents_in_students_dir($student_id) {
    $found = [];
    $server_base = dirname(__DIR__, 2) . '/assets/uploads/students/'; // absolute server path
    $web_base    = '../../assets/uploads/students/';                   // web path from this PHP file

    if (!is_dir($server_base)) return $found;

    // Iterate each student folder and look for files starting with this student_id
    foreach (glob($server_base . '*', GLOB_ONLYDIR) as $studentFolder) {
        $pattern = $studentFolder . '/' . $student_id . '_*.*';
        $matches = glob($pattern);
        if (empty($matches)) continue;

        // Map tokens to doc types used in DB/UI
        $map = [
            'eaf' => 'eaf',
            'letter' => 'letter_to_mayor',
            'indigency' => 'certificate_of_indigency',
            'id' => 'id_picture',
            'grades' => 'grades', // Map to 'grades' for consistency
        ];

        // Keep latest file per type
        $latest = [];
        foreach ($matches as $file) {
            $base = basename($file);
            // Expect filename like: {student_id}_{token}_{timestamp}.ext
            if (preg_match('/^' . preg_quote($student_id, '/') . '_([a-zA-Z0-9_-]+)_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.[a-z0-9]+$/i', $base, $m)) {
                $token = strtolower($m[1]);
                $type = $map[$token] ?? null;
                if (!$type) continue;
                $mtime = @filemtime($file) ?: 0;
                if (!isset($latest[$type]) || $mtime > $latest[$type]['mtime']) {
                    $latest[$type] = ['file' => $file, 'mtime' => $mtime];
                }
            }
        }

        foreach ($latest as $type => $info) {
            // Build web path
            $rel = str_replace($server_base, $web_base, $info['file']);
            $found[$type] = $rel;
        }

        // We only need to check the specific student's folder
        // since student_id is embedded, first match is enough
        if (!empty($found)) break;
    }

    return $found;
}

// Function to check if all required documents are uploaded
function check_documents($connection, $student_id) {
    // Required document type codes: EAF, Letter to Mayor, Certificate of Indigency
    $required_codes = ['00', '02', '03'];
    
    // Check if student needs upload tab (existing student) or uses registration docs (new student)
    // Detect if column exists; if not, default to true (existing flow)
    $colCheck = pg_query($connection, "SELECT 1 FROM information_schema.columns WHERE table_name='students' AND column_name='needs_document_upload'");
    $hasNeedsUploadCol = $colCheck ? (pg_num_rows($colCheck) > 0) : false;
    if ($colCheck) { pg_free_result($colCheck); }

    if ($hasNeedsUploadCol) {
        $student_info_query = pg_query_params($connection, 
            "SELECT needs_document_upload, application_date FROM students WHERE student_id = $1", 
            [$student_id]
        );
        $student_info = $student_info_query ? pg_fetch_assoc($student_info_query) : null;
        // Default to FALSE (new registration) if NULL
        // PostgreSQL returns 'f'/'t' strings, not PHP booleans
        $needs_upload_tab = $student_info ? 
                           ($student_info['needs_document_upload'] === 't' || $student_info['needs_document_upload'] === true) : false;
    } else {
        // Column not present, assume existing students require upload tab
        $student_info_query = pg_query_params($connection, 
            "SELECT application_date FROM students WHERE student_id = $1", 
            [$student_id]
        );
        $student_info = $student_info_query ? pg_fetch_assoc($student_info_query) : null;
        $needs_upload_tab = true;
    }
    
    $uploaded_codes = [];
    
    if ($needs_upload_tab) {
        // Existing student: check documents table for document_type_codes
        $query = pg_query_params($connection, "SELECT document_type_code FROM documents WHERE student_id = $1", [$student_id]);
        while ($row = pg_fetch_assoc($query)) {
            $uploaded_codes[] = $row['document_type_code'];
        }
        
        // Also check file system - convert document names to codes
        $found_documents = find_student_documents_by_id($connection, $student_id);
        $name_to_code_map = [
            'eaf' => '00',
            'letter_to_mayor' => '02',
            'certificate_of_indigency' => '03',
            'id_picture' => '04',
            'grades' => '01'
        ];
        foreach (array_keys($found_documents) as $doc_name) {
            if (isset($name_to_code_map[$doc_name])) {
                $uploaded_codes[] = $name_to_code_map[$doc_name];
            }
        }
        $uploaded_codes = array_unique($uploaded_codes);
        
        // Check if grades are uploaded via documents table (document_type_code = '01')
        $has_grades = in_array('01', $uploaded_codes);
    } else {
        // New student: check BOTH documents table AND file system
        // After approval, documents are moved to permanent storage and recorded in documents table
        
        // 1. Check documents table first
        $query = pg_query_params($connection, "SELECT document_type_code FROM documents WHERE student_id = $1", [$student_id]);
        while ($row = pg_fetch_assoc($query)) {
            $uploaded_codes[] = $row['document_type_code'];
        }
        
        // 2. Also check file system (in case documents are only in filesystem)
        $registration_docs = find_student_documents_by_id($connection, $student_id);
        
        // Convert document names to codes
        $name_to_code_map = [
            'eaf' => '00',
            'letter_to_mayor' => '02',
            'certificate_of_indigency' => '03',
            'id_picture' => '04',
            'grades' => '01'
        ];
        foreach (array_keys($registration_docs) as $doc_name) {
            if (isset($name_to_code_map[$doc_name])) {
                $uploaded_codes[] = $name_to_code_map[$doc_name];
            }
        }
        
        // Remove duplicates
        $uploaded_codes = array_unique($uploaded_codes);
        
        // For new registrants, check if they have grades
        $has_grades = in_array('01', $uploaded_codes) || 
                     file_exists("../../assets/uploads/student/" . $student_id . "/grades/");
    }
    
    // Check if all required document codes are present
    return count(array_diff($required_codes, $uploaded_codes)) === 0 && $has_grades;
}

// Pagination & Filtering logic
$page = max(1, intval($_GET['page'] ?? $_POST['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;
$sort = $_GET['sort'] ?? $_POST['sort'] ?? 'asc';
$search = trim($_GET['search_surname'] ?? $_POST['search_surname'] ?? '');

// Exclude archived students from applicants list
$where = "status = 'applicant' AND (is_archived = FALSE OR is_archived IS NULL)";
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
    global $csrfApproveApplicantToken, $csrfRejectApplicantToken, $csrfOverrideApplicantToken, $csrfArchiveStudentToken, $csrfRejectDocumentsToken, $workflow_status;
    $canApprove = $workflow_status['can_manage_applicants'] ?? false;
    ob_start();
    ?>
    <table class="table table-bordered align-middle">
        <thead>
            <tr>
                <th>Name</th>
                <th>Contact</th>
                <th>Email</th>
                <th>Type</th>
                <th>Documents</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody id="applicantsTableBody">
        <?php if (pg_num_rows($applicants) === 0): ?>
            <tr><td colspan="6" class="text-center no-applicants">No applicants found.</td></tr>
        <?php else: ?>
            <?php while ($applicant = pg_fetch_assoc($applicants)) {
                $student_id = $applicant['student_id'];
                $isComplete = check_documents($connection, $student_id);
                
                // Determine applicant type
                // NULL or FALSE = New registrant (from registration system)
                // TRUE = Existing student requiring re-upload
                // PostgreSQL returns 'f'/'t' strings, not PHP booleans
                $needs_upload = isset($applicant['needs_document_upload']) ? 
                               ($applicant['needs_document_upload'] === 't' || $applicant['needs_document_upload'] === true) : false;
                $applicant_type = $needs_upload ? 're-upload' : 'new';
                $type_label = $needs_upload ? 'Re-upload' : 'New Registration';
                $type_icon = $needs_upload ? 'arrow-repeat' : 'person-plus';
                $type_color = $needs_upload ? 'bg-warning' : 'bg-info';
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
                    <td data-label="Type">
                        <span class="badge <?= $type_color ?> text-white" title="<?= $needs_upload ? 'Existing student required to re-upload documents' : 'New applicant from registration system' ?>">
                            <i class="bi bi-<?= $type_icon ?>"></i> <?= $type_label ?>
                        </span>
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
                        <button class="btn btn-warning btn-sm ms-1" 
                                onclick="showArchiveModal('<?= $student_id ?>', '<?= htmlspecialchars($applicant['first_name'] . ' ' . $applicant['last_name'], ENT_QUOTES) ?>')"
                                title="Archive Student">
                            <i class="bi bi-archive"></i>
                        </button>
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
                                <h5 class="modal-title">
                                    Documents for <?= htmlspecialchars($applicant['first_name']) ?> <?= htmlspecialchars($applicant['last_name']) ?>
                                    <span class="badge <?= $type_color ?> ms-2 text-white" style="font-size: 0.75rem;">
                                        <i class="bi bi-<?= $type_icon ?>"></i> <?= $type_label ?>
                                    </span>
                                </h5>
                                <button class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <?php if ($needs_upload): ?>
                                <div class="alert alert-warning mb-3">
                                    <i class="bi bi-info-circle"></i> 
                                    <strong>Re-upload Required:</strong> This student is an existing applicant who needs to upload/re-upload their documents via the Upload Documents tab.
                                </div>
                                <?php else: ?>
                                <div class="alert alert-info mb-3">
                                    <i class="bi bi-check-circle"></i> 
                                    <strong>New Registration:</strong> This student registered through the online registration system and submitted documents during registration.
                                </div>
                                <?php endif; ?>
                                <?php
                                // Map document type codes to readable names
                                $doc_type_map = [
                                    '04' => 'id_picture',
                                    '00' => 'eaf',
                                    '02' => 'letter_to_mayor',
                                    '03' => 'certificate_of_indigency',
                                    '01' => 'grades'
                                ];
                                
                                // First, get documents from database (only those with valid file paths that exist)
                                $docs = pg_query_params($connection, "SELECT document_type_code, file_path FROM documents WHERE student_id = $1", [$student_id]);
                                $db_documents = [];
                                while ($doc = pg_fetch_assoc($docs)) {
                                    // Only include documents where the file actually exists
                                    // Try both temp and student directories
                                    $filePath = $doc['file_path'];
                                    $docTypeCode = $doc['document_type_code'];
                                    $docTypeName = $doc_type_map[$docTypeCode] ?? 'unknown';
                                    
                                    $server_root = dirname(__DIR__, 2);
                                    
                                    // Check if path contains 'temp' - replace with 'student' for approved students
                                    if (strpos($filePath, '/temp/') !== false) {
                                        $permanentPath = str_replace('/temp/', '/student/', $filePath);
                                        // Check if permanent file exists
                                        $relative_from_root = ltrim(str_replace('../../', '', $permanentPath), '/');
                                        $server_path = $server_root . '/' . $relative_from_root;
                                        
                                        if (file_exists($server_path)) {
                                            $db_documents[$docTypeName] = $permanentPath;
                                        } else {
                                            // Fallback to temp path
                                            $relative_from_root = ltrim(str_replace('../../', '', $filePath), '/');
                                            $server_path = $server_root . '/' . $relative_from_root;
                                            if (file_exists($server_path)) {
                                                $db_documents[$docTypeName] = $filePath;
                                            }
                                        }
                                    } else {
                                        // Already permanent path
                                        $relative_from_root = ltrim(str_replace('../../', '', $filePath), '/');
                                        $server_path = $server_root . '/' . $relative_from_root;
                                        if (file_exists($server_path)) {
                                            $db_documents[$docTypeName] = $filePath;
                                        }
                                    }
                                }
                                
                                // Map academic_grades to grades for consistency with other document lookups
                                if (isset($db_documents['academic_grades'])) {
                                    $db_documents['grades'] = $db_documents['academic_grades'];
                                }

                                // Then, search for documents in student directory using student_id pattern
                                $found_documents = [];
                                $server_base = dirname(__DIR__, 2) . '/assets/uploads/student/';
                                $web_base = '../../assets/uploads/student/';
                                
                                $document_folders = [
                                    'id_pictures' => 'id_picture',
                                    'enrollment_forms' => 'eaf',
                                    'letter_mayor' => 'letter_to_mayor', // Fixed: matches DocumentReuploadService folder name
                                    'indigency' => 'certificate_of_indigency',
                                    'grades' => 'grades'
                                ];
                                
                                foreach ($document_folders as $folder => $type) {
                                    $dir = $server_base . $folder . '/';
                                    if (is_dir($dir)) {
                                        // Look for files starting with student_id
                                        $pattern = $dir . $student_id . '_*';
                                        $matches = glob($pattern);
                                        if (!empty($matches)) {
                                            // Filter out associated files (.verify.json, .ocr.txt, etc)
                                            $matches = array_filter($matches, function($file) {
                                                return !preg_match('/\.(verify\.json|ocr\.txt|confidence\.json)$/', $file);
                                            });
                                            
                                            if (!empty($matches)) {
                                                // Get the newest file
                                                usort($matches, function($a, $b) {
                                                    return filemtime($b) - filemtime($a);
                                                });
                                                $newest = $matches[0];
                                                $found_documents[$type] = $web_base . $folder . '/' . basename($newest);
                                            }
                                        }
                                    }
                                }

                                // Merge all sources, prioritizing file system results over DB
                                $all_documents = array_merge($db_documents, $found_documents);

                                $document_labels = [
                                    'id_picture' => 'ID Picture',
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
                                        $filePath = trim($all_documents[$type]); // Trim any whitespace

                                        // Resolve server path for metadata
                                        $server_root = dirname(__DIR__, 2);
                                        $relative_from_root = ltrim(str_replace('../../', '', $filePath), '/');
                                        $server_path = $server_root . '/' . $relative_from_root;
                                        
                                        // Convert to web-root relative path for browser (not file system)
                                        // From modules/admin/, ../../ goes to root, so just use the path after ../../
                                        $webPath = '../../' . $relative_from_root;

                                        // Check extension for image vs PDF (trim filename for safety)
                                        $cleanPath = basename($filePath);
                                        $is_image = preg_match('/\.(jpg|jpeg|png|gif)$/i', $cleanPath);
                                        $is_pdf   = preg_match('/\.pdf$/i', $cleanPath);

                                        $size_str = '';
                                        $date_str = '';
                                        if (file_exists($server_path)) {
                                            $size = filesize($server_path);
                                            $units = ['B','KB','MB','GB'];
                                            $pow = $size > 0 ? floor(log($size, 1024)) : 0;
                                            $size_str = number_format($size / pow(1024, $pow), $pow ? 2 : 0) . ' ' . $units[$pow];
                                            $date_str = date('M d, Y h:i A', filemtime($server_path));
                                        }

                                        // Fetch OCR confidence for this document
                                        // Map type name back to document_type_code
                                        $type_to_code = [
                                            'id_picture' => '04',
                                            'eaf' => '00',
                                            'letter_to_mayor' => '02',
                                            'certificate_of_indigency' => '03',
                                            'grades' => '01'
                                        ];
                                        $doc_code = $type_to_code[$type] ?? null;
                                        
                                        $ocr_confidence_badge = '';
                                        if ($doc_code) {
                                            $ocr_query = pg_query_params($connection, 
                                                "SELECT ocr_confidence FROM documents WHERE student_id = $1 AND document_type_code = $2 ORDER BY upload_date DESC LIMIT 1", 
                                                [$student_id, $doc_code]);
                                            if ($ocr_query && pg_num_rows($ocr_query) > 0) {
                                                $ocr_data = pg_fetch_assoc($ocr_query);
                                                if ($ocr_data['ocr_confidence'] !== null && $ocr_data['ocr_confidence'] > 0) {
                                                    $conf_val = round($ocr_data['ocr_confidence'], 1);
                                                    $conf_color = $conf_val >= 80 ? 'success' : ($conf_val >= 60 ? 'warning' : 'danger');
                                                    $ocr_confidence_badge = "<span class='badge bg-{$conf_color} ms-2'><i class='bi bi-robot me-1'></i>{$conf_val}%</span>";
                                                }
                                            }
                                        }

                                        $thumbHtml = $is_image
                                            ? "<img src='" . htmlspecialchars($webPath) . "' class='doc-thumb' alt='$cardTitle' onerror=\"console.error('Failed to load:', this.src); this.parentElement.innerHTML='<div class=\\'doc-thumb doc-thumb-pdf\\'><i class=\\'bi bi-exclamation-triangle\\'></i></div>';\">"
                                            : "<div class='doc-thumb doc-thumb-pdf'><i class='bi bi-file-earmark-pdf'></i></div>";

                                        $safeSrc = htmlspecialchars($webPath);
                                        
                                        // Get verification status and score from documents table
                                        $verification_badge = '';
                                        $verification_btn = '';
                                        if ($doc_code) {
                                            $verify_query = pg_query_params($connection, 
                                                "SELECT verification_score, verification_status FROM documents WHERE student_id = $1 AND document_type_code = $2 ORDER BY upload_date DESC LIMIT 1", 
                                                [$student_id, $doc_code]);
                                            if ($verify_query && pg_num_rows($verify_query) > 0) {
                                                $verify_data = pg_fetch_assoc($verify_query);
                                                $verify_score = $verify_data['verification_score'];
                                                $verify_status = $verify_data['verification_status'];
                                                
                                                if ($verify_score !== null && $verify_score > 0) {
                                                    $verify_val = round($verify_score, 1);
                                                    $verify_color = $verify_val >= 80 ? 'success' : ($verify_val >= 60 ? 'warning' : 'danger');
                                                    $verify_icon = $verify_val >= 80 ? 'check-circle' : ($verify_val >= 60 ? 'exclamation-triangle' : 'x-circle');
                                                    $verification_badge = " <span class='badge bg-{$verify_color}'><i class='bi bi-{$verify_icon} me-1'></i>{$verify_val}%</span>";
                                                    
                                                    // Add view validation button
                                                    $verification_btn = "<button type='button' class='btn btn-sm btn-outline-info w-100' 
                                                        onclick=\"event.stopPropagation(); loadValidationData('$type', '$student_id'); showValidationModal();\">
                                                        <i class='bi bi-clipboard-check me-1'></i>View Validation Details
                                                    </button>";
                                                }
                                            }
                                        }
                                        
                                        echo "<div class='doc-card'>
                                                <div class='doc-card-header'>
                                                    <div class='d-flex justify-content-between align-items-center'>
                                                        <span>$cardTitle</span>
                                                        <div class='d-flex gap-1'>
                                                            $ocr_confidence_badge
                                                            $verification_badge
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class='doc-card-body' onclick=\"openDocumentViewer('$safeSrc','$cardTitle')\">$thumbHtml</div>
                                                <div class='doc-meta'>" .
                                                    ($date_str ? "<span><i class='bi bi-calendar-event me-1'></i>$date_str</span>" : "") .
                                                    ($size_str ? "<span><i class='bi bi-hdd me-1'></i>$size_str</span>" : "") .
                                                "</div>
                                                <div class='doc-actions'>
                                                    <button type='button' class='btn btn-sm btn-primary' onclick=\"openDocumentViewer('$safeSrc','$cardTitle')\" title='View Document'><i class='bi bi-eye'></i></button>
                                                    <a class='btn btn-sm btn-outline-secondary' href='$safeSrc' target='_blank' title='Open in New Tab'><i class='bi bi-box-arrow-up-right'></i></a>
                                                    <a class='btn btn-sm btn-outline-success' href='$safeSrc' download title='Download'><i class='bi bi-download'></i></a>
                                                </div>";
                                        
                                        // Add validation button if verification data exists (full width, new row)
                                        if ($verification_btn) {
                                            echo "<div class='doc-actions' style='border-top: 0; padding-top: 0;'>
                                                  $verification_btn
                                                  </div>";
                                        }
                                        
                                        echo "</div>";
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

                                // Add Academic Grades card using same pattern as other documents
                                $cardTitle = 'Academic Grades';
                                
                                // Check if grades exist in all_documents array first
                                if (isset($all_documents['grades'])) {
                                    $filePath = trim($all_documents['grades']); // Trim any whitespace
                                    
                                    // Resolve server path for metadata
                                    $server_root = dirname(__DIR__, 2);
                                    $relative_from_root = ltrim(str_replace('../../', '', $filePath), '/');
                                    $server_path = $server_root . '/' . $relative_from_root;
                                    
                                    // Convert to web-root relative path for browser
                                    $webPath = '../../' . $relative_from_root;

                                    // Check extension for image vs PDF (trim filename for safety)
                                    $cleanPath = basename($filePath);
                                    $is_image = preg_match('/\.(jpg|jpeg|png|gif)$/i', $cleanPath);
                                    $is_pdf   = preg_match('/\.pdf$/i', $cleanPath);

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
                                        ? "<img src='" . htmlspecialchars($webPath) . "' class='doc-thumb' alt='$cardTitle' onerror=\"console.error('Failed to load:', this.src); this.parentElement.innerHTML='<div class=\\'doc-thumb doc-thumb-pdf\\'><i class=\\'bi bi-exclamation-triangle\\'></i></div>';\">"
                                        : "<div class='doc-thumb doc-thumb-pdf'><i class='bi bi-file-earmark-pdf'></i></div>";

                                    $safeSrc = htmlspecialchars($webPath);
                                    
                                    // Check for OCR confidence and verification from documents table (document_type_code '01' = grades)
                                    $ocr_confidence = '';
                                    $verification_badge = '';
                                    $verification_btn = '';
                                    
                                    $docs_query = pg_query_params($connection, 
                                        "SELECT ocr_confidence, verification_score, verification_status FROM documents WHERE student_id = $1 AND document_type_code = '01' ORDER BY upload_date DESC LIMIT 1", 
                                        [$student_id]);
                                    
                                    if ($docs_query && pg_num_rows($docs_query) > 0) {
                                        $doc_data = pg_fetch_assoc($docs_query);
                                        
                                        // OCR Confidence
                                        if ($doc_data['ocr_confidence'] !== null && $doc_data['ocr_confidence'] > 0) {
                                            $conf_val = round($doc_data['ocr_confidence'], 1);
                                            $conf_color = $conf_val >= 80 ? 'success' : ($conf_val >= 60 ? 'warning' : 'danger');
                                            $ocr_confidence = "<span class='badge bg-{$conf_color}'><i class='bi bi-robot me-1'></i>{$conf_val}%</span>";
                                        }
                                        
                                        // Verification Score
                                        $verify_score = $doc_data['verification_score'];
                                        if ($verify_score !== null && $verify_score > 0) {
                                            $verify_val = round($verify_score, 1);
                                            $verify_color = $verify_val >= 80 ? 'success' : ($verify_val >= 60 ? 'warning' : 'danger');
                                            $verify_icon = $verify_val >= 80 ? 'check-circle' : ($verify_val >= 60 ? 'exclamation-triangle' : 'x-circle');
                                            $verification_badge = " <span class='badge bg-{$verify_color}'><i class='bi bi-{$verify_icon} me-1'></i>{$verify_val}%</span>";
                                            
                                            // Add view validation button
                                            $verification_btn = "<button type='button' class='btn btn-sm btn-outline-info w-100' 
                                                onclick=\"event.stopPropagation(); loadValidationData('grades', '$student_id'); showValidationModal();\">
                                                <i class='bi bi-clipboard-check me-1'></i>View Validation Details
                                            </button>";
                                        }
                                    }
                                    
                                    echo "<div class='doc-card'>
                                            <div class='doc-card-header'>
                                                <div class='d-flex justify-content-between align-items-center'>
                                                    <span>$cardTitle</span>
                                                    <div class='d-flex gap-1'>
                                                        $ocr_confidence
                                                        $verification_badge
                                                    </div>
                                                </div>
                                            </div>
                                            <div class='doc-card-body' onclick=\"openDocumentViewer('$safeSrc','$cardTitle')\">$thumbHtml</div>
                                            <div class='doc-meta'>" .
                                                ($date_str ? "<span><i class='bi bi-calendar-event me-1'></i>$date_str</span>" : "") .
                                                ($size_str ? "<span><i class='bi bi-hdd me-1'></i>$size_str</span>" : "") .
                                            "</div>
                                            <div class='doc-actions'>
                                                <button type='button' class='btn btn-sm btn-primary' onclick=\"openDocumentViewer('$safeSrc','$cardTitle')\" title='View Document'><i class='bi bi-eye'></i></button>
                                                <a class='btn btn-sm btn-outline-secondary' href='$safeSrc' target='_blank' title='Open in New Tab'><i class='bi bi-box-arrow-up-right'></i></a>
                                                <a class='btn btn-sm btn-outline-success' href='$safeSrc' download title='Download'><i class='bi bi-download'></i></a>
                                            </div>";
                                    
                                    // Add validation button if verification data exists (full width, new row)
                                    if ($verification_btn) {
                                        echo "<div class='doc-actions' style='border-top: 0; padding-top: 0;'>
                                              $verification_btn
                                              </div>";
                                    }
                                    
                                    echo "</div>";
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
                                ?>
                            </div>
                            <div class="modal-footer">
                                <?php if (!$canApprove): ?>
                                    <div class="alert alert-warning mb-0 w-100">
                                        <i class="bi bi-exclamation-triangle me-2"></i>
                                        <strong>Distribution Not Active:</strong> Please start a distribution first to approve or reject applicants.
                                        <a href="distribution_control.php" class="alert-link">Go to Distribution Control</a>
                                    </div>
                                <?php elseif ($isComplete): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Verify this student?');">
                                        <input type="hidden" name="student_id" value="<?= $student_id ?>">
                                        <input type="hidden" name="mark_verified" value="1">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfApproveApplicantToken) ?>">
                                        <button class="btn btn-success btn-sm"><i class="bi bi-check-circle me-1"></i> Verify</button>
                                    </form>
                                    <!-- Reject Documents Button -->
                                    <form method="POST" class="d-inline ms-2" onsubmit="return confirm('⚠️ DELETE ALL DOCUMENTS?\n\nThis will:\n• Delete all uploaded files from the server\n• Clear all document records from database\n• Require student to re-upload everything\n\nThis action cannot be undone. Continue?');">
                                        <input type="hidden" name="student_id" value="<?= $student_id ?>">
                                        <input type="hidden" name="reject_documents" value="1">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfRejectDocumentsToken) ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="Delete all documents and request re-upload">
                                            <i class="bi bi-trash me-1"></i> Reject Documents
                                        </button>
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
                                    <!-- Reject Documents Button (also available for incomplete) -->
                                    <form method="POST" class="d-inline ms-2" onsubmit="return confirm('⚠️ DELETE ALL DOCUMENTS?\n\nThis will:\n• Delete all uploaded files from the server\n• Clear all document records from database\n• Require student to re-upload everything\n\nThis action cannot be undone. Continue?');">
                                        <input type="hidden" name="student_id" value="<?= $student_id ?>">
                                        <input type="hidden" name="reject_documents" value="1">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfRejectDocumentsToken) ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="Delete all documents and request re-upload">
                                            <i class="bi bi-trash me-1"></i> Reject Documents
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <?php if ($_SESSION['admin_role'] === 'super_admin'): ?>
                                <div class="ms-auto">
                                    <button class="btn btn-outline-warning btn-sm me-2" 
                                            onclick="showArchiveModal('<?= $student_id ?>', '<?= htmlspecialchars($applicant['first_name'] . ' ' . $applicant['last_name'], ENT_QUOTES) ?>')"
                                            data-bs-dismiss="modal">
                                        <i class="bi bi-archive me-1"></i> Archive Student
                                    </button>
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

// Handle verify/reject/archive actions before AJAX or page render
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $applicantCsrfAction = null;
    if (!empty($_POST['mark_verified']) && isset($_POST['student_id'])) {
        $applicantCsrfAction = 'approve_applicant';
    } elseif (!empty($_POST['mark_verified_override']) && isset($_POST['student_id'])) {
        $applicantCsrfAction = 'override_applicant';
    } elseif (!empty($_POST['archive_student']) && isset($_POST['student_id'])) {
        $applicantCsrfAction = 'archive_student';
    } elseif (!empty($_POST['reject_documents']) && isset($_POST['student_id'])) {
        $applicantCsrfAction = 'reject_documents';
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
        // Check if approval is allowed
        if (!$workflow_status['can_manage_applicants']) {
            $_SESSION['error_message'] = "Cannot approve applicants. Please start a distribution first.";
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        
        $sid = trim($_POST['student_id']); // Remove intval for TEXT student_id
        
        // Get student name for notification
        $studentQuery = pg_query_params($connection, "SELECT first_name, last_name, email FROM students WHERE student_id = $1", [$sid]);
        $student = pg_fetch_assoc($studentQuery);
        
        /** @phpstan-ignore-next-line */
        pg_query_params($connection, "UPDATE students SET status = 'active' WHERE student_id = $1", [$sid]);
        
        // Move files from temp to permanent storage
        require_once __DIR__ . '/../../services/FileManagementService.php';
        $fileService = new FileManagementService($connection);
        $fileMoveResult = $fileService->moveTemporaryFilesToPermanent($sid);
        
        if (!$fileMoveResult['success']) {
            error_log("FileManagement: Error moving files for student $sid: " . implode(', ', $fileMoveResult['errors']));
        }
        
        // Add admin notification
        if ($student) {
            $student_name = $student['first_name'] . ' ' . $student['last_name'];
            $notification_msg = "Student promoted to active: " . $student_name . " (ID: " . $sid . ")";
            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
            
            // Log applicant approval in audit trail
            require_once __DIR__ . '/../../services/AuditLogger.php';
            $auditLogger = new AuditLogger($connection);
            $auditLogger->logApplicantApproved(
                $_SESSION['admin_id'],
                $_SESSION['admin_username'],
                $sid,
                [
                    'first_name' => $student['first_name'],
                    'last_name' => $student['last_name'],
                    'email' => $student['email']
                ]
            );
        }
        
        // Redirect to refresh list
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    // Override verify even if incomplete (super_admin only)
    if (!empty($_POST['mark_verified_override']) && isset($_POST['student_id'])) {
        // Check if approval is allowed
        if (!$workflow_status['can_manage_applicants']) {
            $_SESSION['error_message'] = "Cannot override approve applicants. Please start a distribution first.";
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        
        if (!empty($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'super_admin') {
            $sid = trim($_POST['student_id']);
            // Get student name for notification
            $studentQuery = pg_query_params($connection, "SELECT first_name, last_name FROM students WHERE student_id = $1", [$sid]);
            $student = pg_fetch_assoc($studentQuery);

            /** @phpstan-ignore-next-line */
            pg_query_params($connection, "UPDATE students SET status = 'active' WHERE student_id = $1", [$sid]);

            // Move files from temp to permanent storage
            require_once __DIR__ . '/../../services/FileManagementService.php';
            $fileService = new FileManagementService($connection);
            $fileMoveResult = $fileService->moveTemporaryFilesToPermanent($sid);
            
            if (!$fileMoveResult['success']) {
                error_log("FileManagement: Error moving files for student $sid (override): " . implode(', ', $fileMoveResult['errors']));
            }

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
    
    // Archive Student
    if (!empty($_POST['archive_student']) && isset($_POST['student_id'], $_POST['archive_reason'])) {
        $sid = trim($_POST['student_id']);
        $archiveReason = trim($_POST['archive_reason']);
        $archiveOtherReason = trim($_POST['archive_other_reason'] ?? '');
        
        // If reason is "other", use the custom reason text
        if ($archiveReason === 'other' && !empty($archiveOtherReason)) {
            $archiveReason = $archiveOtherReason;
        }
        
        // Get student details for logging
        $studentQuery = pg_query_params($connection, 
            "SELECT first_name, last_name, email, status FROM students WHERE student_id = $1", 
            [$sid]
        );
        
        if (!$studentQuery || pg_num_rows($studentQuery) === 0) {
            $_SESSION['error_message'] = "Student not found.";
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        
        $student = pg_fetch_assoc($studentQuery);
        $fullName = trim($student['first_name'] . ' ' . $student['last_name']);
        
        // Check if already archived
        if ($student['status'] === 'archived') {
            $_SESSION['error_message'] = "Student is already archived.";
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        
        // Archive files first
        require_once __DIR__ . '/../../services/FileManagementService.php';
        $fileService = new FileManagementService($connection);
        $archiveResult = $fileService->compressArchivedStudent($sid);
        
        if (!$archiveResult['success']) {
            error_log("Archive Error: Failed to compress files for student $sid");
            $_SESSION['error_message'] = "Failed to archive student files. Please try again.";
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        
        // Update student record using SQL function
        $archiveQuery = pg_query_params($connection,
            "SELECT archive_student($1, $2, $3) as success",
            [$sid, $adminId, $archiveReason]
        );
        
        if ($archiveQuery && pg_fetch_assoc($archiveQuery)['success'] === 't') {
            // Log the archival action
            require_once __DIR__ . '/../../services/AuditLogger.php';
            $auditLogger = new AuditLogger($connection);
            $auditLogger->logStudentArchived(
                $adminId,
                $adminUsername,
                $sid,
                [
                    'full_name' => $fullName,
                    'email' => $student['email'],
                    'files_archived' => $archiveResult['files_archived'] ?? 0,
                    'space_saved' => $archiveResult['space_saved'] ?? 0
                ],
                $archiveReason,
                false // Manual archival
            );
            
            $_SESSION['success_message'] = "Student {$fullName} has been archived successfully.";
            
            // Add admin notification
            $notification_msg = "Student archived: {$fullName} (ID: {$sid}) - Reason: {$archiveReason}";
            pg_query_params($connection, "INSERT INTO admin_notifications (message) VALUES ($1)", [$notification_msg]);
        } else {
            $_SESSION['error_message'] = "Failed to archive student. Please try again.";
        }
        
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Reject Documents - Delete all uploaded files and request re-upload
    if (!empty($_POST['reject_documents']) && isset($_POST['student_id'])) {
        $student_id = trim($_POST['student_id']);
        error_log("Reject documents triggered for student: " . $student_id);
        
        try {
            // Delete all document files from filesystem
            $uploadsPath = dirname(__DIR__, 2) . '/assets/uploads/student';
            $documentTypes = ['enrollment_forms', 'grades', 'id_pictures', 'indigency', 'letter_mayor'];
            $deletedCount = 0;
            
            foreach ($documentTypes as $type) {
                $folderPath = $uploadsPath . '/' . $type;
                if (is_dir($folderPath)) {
                    $files = glob($folderPath . '/' . $student_id . '_*');
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            @unlink($file);
                            $deletedCount++;
                        }
                    }
                }
            }
            
            // Delete all document records from database
            $deleteDocsQuery = "DELETE FROM documents WHERE student_id = $1";
            pg_query_params($connection, $deleteDocsQuery, [$student_id]);
            
            // Set needs_document_upload flag and mark documents_to_reupload
            $updateQuery = "UPDATE students 
                           SET needs_document_upload = TRUE,
                               documents_to_reupload = $1
                           WHERE student_id = $2";
            pg_query_params($connection, $updateQuery, [
                json_encode(['00', '01', '02', '03', '04']), // All document types
                $student_id
            ]);
            
            // Log audit
            $auditQuery = "INSERT INTO audit_log (admin_id, student_id, action, description, ip_address, created_at)
                          VALUES ($1, $2, 'reject_documents', $3, $4, NOW())";
            pg_query_params($connection, $auditQuery, [
                $_SESSION['admin_id'] ?? null,
                $student_id,
                "Admin rejected all documents. Deleted $deletedCount files. Student must re-upload all documents.",
                $_SERVER['REMOTE_ADDR']
            ]);
            
            $_SESSION['success'] = "All documents rejected. Student will be notified to re-upload.";
            
        } catch (Exception $e) {
            $_SESSION['error'] = "Error rejecting documents: " . $e->getMessage();
        }
        
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
            <?php if (!empty($_SESSION['error_message']) || !empty($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <?= htmlspecialchars($_SESSION['error_message'] ?? $_SESSION['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error_message'], $_SESSION['error']); ?>
            <?php endif; ?>

            <?php if (!empty($_SESSION['success_message']) || !empty($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle me-2"></i>
                <?= htmlspecialchars($_SESSION['success_message'] ?? $_SESSION['success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['success_message'], $_SESSION['success']); ?>
            <?php endif; ?>

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

<!-- Archive Student Modal -->
<div class="modal fade" id="archiveModal" tabindex="-1" aria-labelledby="archiveModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title" id="archiveModalLabel">
                    <i class="bi bi-archive-fill me-2"></i>Archive Student
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="archiveForm">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>What happens when you archive a student:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Student account will be deactivated</li>
                            <li>All documents will be compressed into a ZIP file</li>
                            <li>Student will not be able to login</li>
                            <li>Student will be moved to "Archived Students" page</li>
                            <li>You can unarchive the student later if needed</li>
                        </ul>
                    </div>
                    
                    <p class="mb-3">
                        You are about to archive: <strong id="archiveStudentName"></strong>
                    </p>
                    
                    <input type="hidden" name="student_id" id="archiveStudentId">
                    <input type="hidden" name="archive_student" value="1">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfArchiveStudentToken) ?>">
                    
                    <div class="mb-3">
                        <label for="archiveReason" class="form-label">
                            Reason for Archiving <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="archiveReason" name="archive_reason" required onchange="handleArchiveReasonChange()">
                            <option value="">-- Select Reason --</option>
                            <option value="graduated">Graduated</option>
                            <option value="ineligible">Ineligible</option>
                            <option value="duplicate">Duplicate Account</option>
                            <option value="inactive">Inactive/No Longer Enrolled</option>
                            <option value="transferred">Transferred to Another Municipality</option>
                            <option value="did_not_attend">Did Not Attend Distribution</option>
                            <option value="other">Other (Please Specify)</option>
                        </select>
                    </div>
                    
                    <div class="mb-3" id="otherReasonContainer" style="display: none;">
                        <label for="archiveOtherReason" class="form-label">
                            Please specify the reason <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" id="archiveOtherReason" name="archive_other_reason" rows="3" placeholder="Enter the specific reason for archiving this student..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-warning" id="confirmArchiveBtn">
                        <i class="bi bi-archive me-1"></i> Archive Student
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Password Confirmation Modal for Archive -->
<!-- Removed - Password confirmation disabled for archive -->

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
    // Move all modals to be direct children of body to avoid stacking context issues
    // But do this AFTER a delay to let sidebar.js and Bootstrap initialize first
    setTimeout(() => {
        document.querySelectorAll('.modal').forEach(function(modalEl){
            if (modalEl.parentElement !== document.body) {
                document.body.appendChild(modalEl);
            }
        });
    }, 100);
    
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
/* Ensure Bootstrap modal appears above any custom overlay/backdrop from the admin layout */
.modal { z-index: 200000 !important; }
.modal-backdrop { z-index: 199999 !important; }
/* Document grid */
.doc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; }
.doc-card { 
    border: 1px solid #e5e7eb; 
    border-radius: 10px; 
    background: #fff; 
    display: flex; 
    flex-direction: column;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    transition: box-shadow 0.2s, transform 0.2s;
}
.doc-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    transform: translateY(-2px);
}
.doc-card-header { 
    font-weight: 600; 
    font-size: 0.95rem;
    padding: 12px 14px; 
    border-bottom: 1px solid #f0f0f0; 
    background: linear-gradient(to bottom, #f8f9fa, #fff);
}
.doc-card-header .badge {
    font-size: 0.75rem;
    font-weight: 500;
    padding: 0.35em 0.6em;
}
.doc-card-body { 
    padding: 10px; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    min-height: 180px; 
    cursor: zoom-in; 
    background: #fafafa;
    border-radius: 4px;
    margin: 8px;
}
.doc-card-body:hover {
    background: #f5f5f5;
}
.doc-thumb { 
    max-width: 100%; 
    max-height: 165px; 
    border-radius: 6px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.doc-thumb-pdf { 
    font-size: 56px; 
    color: #dc3545; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    height: 165px; 
    width: 100%; 
}
.doc-meta { 
    display: flex; 
    justify-content: space-between; 
    gap: 10px; 
    padding: 8px 14px; 
    color: #6b7280; 
    font-size: 0.75rem; 
    border-top: 1px dashed #eee; 
    background: #fafbfc;
}
.doc-meta i {
    opacity: 0.7;
}
.doc-actions { 
    display: flex; 
    flex-wrap: wrap; 
    gap: 6px; 
    padding: 10px 12px; 
    border-top: 1px solid #f0f0f0; 
}
.doc-actions .btn { 
    flex: 1 1 auto; 
    min-width: 40px; 
    font-size: 0.8rem; 
    padding: 6px 10px; 
}
.doc-actions .w-100 {
    flex: 1 1 100%;
}
.doc-card-missing .missing { 
    background: #fff7e6; 
    color: #8a6d3b; 
    min-height: 180px; 
    display: flex; 
    flex-direction: column; 
    align-items: center; 
    justify-content: center;
    border-radius: 8px;
    margin: 8px;
    border: 2px dashed #ffc107;
}
.doc-card-missing .missing-icon { 
    font-size: 36px; 
    margin-bottom: 8px; 
    opacity: 0.6;
}

/* Fullscreen viewer - Must appear ABOVE modals (z-index 200000+) */
.doc-viewer-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.85); display: none; z-index: 210000 !important; }
.doc-viewer { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 95vw; max-width: 1280px; height: 85vh; background: #111; border-radius: 8px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); }
.doc-viewer-toolbar { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; justify-content: space-between; padding: 8px 12px; background: #1f2937; color: #fff; }
.doc-viewer-toolbar .btn { padding: 4px 8px; }
.doc-viewer-content { flex: 1; background: #000; display: flex; align-items: center; justify-content: center; overflow: hidden; position: relative; }
.doc-viewer-canvas { touch-action: none; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; }
.doc-viewer-content img { will-change: transform; transform-origin: center center; user-select: none; -webkit-user-drag: none; }
.doc-viewer-content iframe { width: 100%; height: 100%; border: none; }
.doc-viewer-close { background: transparent; border: 0; color: #fff; font-size: 20px; }

/* Validation modal should appear above student info modal but below document viewer */
#validationModal { z-index: 205000 !important; }
#validationModal + .modal-backdrop { z-index: 204999 !important; }

/* Custom backdrop for validation modal to dim the student info modal behind it */
.validation-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    z-index: 204998 !important;
    display: none;
}

.validation-backdrop.show {
    display: block;
}

/* Verification checklist styling (matching registration page) */
.verification-checklist {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.verification-checklist .form-check {
    padding: 0.75rem 1rem;
    background: #f8f9fa;
    border-radius: 6px;
    border: 1px solid #dee2e6;
    margin: 0;
}

.verification-checklist .form-check.check-passed {
    background: #d1e7dd;
    border-color: #badbcc;
}

.verification-checklist .form-check.check-failed {
    background: #f8d7da;
    border-color: #f5c2c7;
}

.verification-checklist .form-check.check-warning {
    background: #fff3cd;
    border-color: #ffe69c;
}

.confidence-score {
    font-size: 0.875rem;
    padding: 0.25rem 0.5rem;
    min-width: 50px;
    text-align: center;
}

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

// Archive Student Modal and Functions
function showArchiveModal(studentId, studentName) {
    const modal = document.getElementById('archiveModal');
    if (!modal) {
        console.error('Archive modal element not found');
        return;
    }

    const idInput = document.getElementById('archiveStudentId');
    const nameLabel = document.getElementById('archiveStudentName');
    const reasonSelect = document.getElementById('archiveReason');
    const otherReason = document.getElementById('archiveOtherReason');
    const otherContainer = document.getElementById('otherReasonContainer');

    if (idInput) idInput.value = studentId;
    if (nameLabel) nameLabel.textContent = studentName;
    if (reasonSelect) reasonSelect.value = '';
    if (otherReason) otherReason.value = '';
    if (otherContainer) otherContainer.style.display = 'none';

    const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
    bsModal.show();
}

function handleArchiveReasonChange() {
    const select = document.getElementById('archiveReason');
    const otherContainer = document.getElementById('otherReasonContainer');
    const otherInput = document.getElementById('archiveOtherReason');

    if (!select || !otherContainer || !otherInput) {
        return;
    }

    if (select.value === 'other') {
        otherContainer.style.display = 'block';
        otherInput.focus();
    } else {
        otherContainer.style.display = 'none';
        otherInput.value = '';
    }
}

// Handle archive form submission with confirmation dialog
document.addEventListener('DOMContentLoaded', function() {
    const archiveForm = document.getElementById('archiveForm');

    if (archiveForm) {
        archiveForm.addEventListener('submit', function(e) {
            e.preventDefault();

            // Validate reason selection
            const reasonSelect = document.getElementById('archiveReason');
            const otherReasonText = document.getElementById('archiveOtherReason');
            
            if (!reasonSelect.value) {
                alert('Please select a reason for archiving.');
                return;
            }

            if (reasonSelect.value === 'other' && !otherReasonText.value.trim()) {
                alert('Please specify the reason for archiving.');
                otherReasonText.focus();
                return;
            }

            // Get student name for confirmation
            const studentName = document.getElementById('archiveStudentName').textContent;
            const reason = reasonSelect.value === 'other' ? otherReasonText.value : reasonSelect.options[reasonSelect.selectedIndex].text;

            // Show confirmation dialog
            if (confirm(`⚠️ CONFIRM ARCHIVE\n\nStudent: ${studentName}\nReason: ${reason}\n\nThis will:\n• Deactivate the student account\n• Compress all documents to ZIP\n• Prevent student login\n• Move student to archived list\n\nAre you sure you want to proceed?`)) {
                // Submit the form
                archiveForm.submit();
            }
        });
    }
});

// Load validation data into modal (modal will be shown automatically by Bootstrap data attributes)
async function loadValidationData(docType, studentId) {
    console.log('loadValidationData called:', docType, studentId);
    
    const modalBody = document.getElementById('validationModalBody');
    const modalTitle = document.getElementById('validationModalLabel');
    
    const docNames = {
        'id_picture': 'ID Picture',
        'eaf': 'EAF',
        'letter_to_mayor': 'Letter to Mayor',
        'certificate_of_indigency': 'Certificate of Indigency',
        'grades': 'Academic Grades'
    };
    modalTitle.textContent = `${docNames[docType] || docType} - Validation Results`;
    
    modalBody.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-info"></div><p class="mt-3">Loading...</p></div>';
    
    try {
        const response = await fetch('../student/get_validation_details.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({doc_type: docType, student_id: studentId})
        });
        
        console.log('Response status:', response.status);
        const responseText = await response.text();
        console.log('Raw response:', responseText);
        
        let data;
        try {
            data = JSON.parse(responseText);
            console.log('Parsed data:', data);
        } catch (parseError) {
            console.error('JSON parse error:', parseError);
            modalBody.innerHTML = `<div class="alert alert-danger">
                <h6>Error parsing response</h6>
                <pre style="max-height:200px;overflow:auto;">${responseText.substring(0, 500)}</pre>
            </div>`;
            return;
        }
        
        if (data.success) {
            const html = generateValidationHTML(data.validation, docType);
            console.log('Generated HTML length:', html.length);
            modalBody.innerHTML = html;
        } else {
            modalBody.innerHTML = `<div class="alert alert-warning">
                <h6><i class="bi bi-exclamation-triangle me-2"></i>${data.message || 'No validation data available.'}</h6>
                <small>Document Type: ${docType}, Student ID: ${studentId}</small>
            </div>`;
        }
    } catch (error) {
        console.error('Validation fetch error:', error);
        modalBody.innerHTML = `<div class="alert alert-danger">
            <h6><i class="bi bi-x-circle me-2"></i>Error loading validation data</h6>
            <p>${error.message}</p>
            <small>Document Type: ${docType}, Student ID: ${studentId}</small>
        </div>`;
    }
}

function generateValidationHTML(validation, docType) {
    console.log('=== generateValidationHTML DEBUG ===');
    console.log('docType:', docType);
    console.log('validation object:', validation);
    console.log('Has identity_verification?', !!validation.identity_verification);
    if (validation.identity_verification) {
        console.log('identity_verification keys:', Object.keys(validation.identity_verification));
        console.log('identity_verification data:', validation.identity_verification);
    }
    
    if (!validation || typeof validation !== 'object') {
        return `<div class="alert alert-warning p-4">
            <h6><i class="bi bi-exclamation-triangle me-2"></i>No Validation Data</h6>
            <p>Validation data is not available or malformed for this document.</p>
            <small>Document Type: ${docType}</small>
        </div>`;
    }
    
    let html = '';
    
    // === OCR CONFIDENCE BANNER ===
    if (validation.ocr_confidence !== undefined) {
        const conf = parseFloat(validation.ocr_confidence);
        const confColor = conf >= 80 ? 'success' : (conf >= 60 ? 'warning' : 'danger');
        html += `<div class="alert alert-${confColor} d-flex justify-content-between align-items-center mb-4">
            <div><h5 class="mb-0"><i class="bi bi-robot me-2"></i>Overall OCR Confidence</h5></div>
            <h3 class="mb-0 fw-bold">${conf.toFixed(1)}%</h3>
        </div>`;
    }
    
    // === DETAILED VERIFICATION CHECKLIST ===
    if (validation.identity_verification) {
        const idv = validation.identity_verification;
        const isIdOrEaf = (docType === 'id_picture' || docType === 'eaf');
        const isLetter = (docType === 'letter_to_mayor');
        const isCert = (docType === 'certificate_of_indigency');
        
        html += '<div class="card mb-4"><div class="card-header bg-primary text-white">';
        html += '<h5 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Verification Checklist</h5>';
        html += '</div><div class="card-body"><div class="verification-checklist">';
        
        // FIRST NAME
        const fnMatch = idv.first_name_match;
        const fnConf = parseFloat(idv.first_name_confidence || 0);
        const fnClass = fnMatch ? 'check-passed' : 'check-failed';
        const fnIcon = fnMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
        html += `<div class="form-check ${fnClass} d-flex justify-content-between align-items-center">
            <div><i class="bi bi-${fnIcon} me-2" style="font-size:1.2rem;"></i>
            <span><strong>First Name</strong> ${fnMatch ? 'Match' : 'Not Found'}</span></div>
            <span class="badge ${fnMatch ? 'bg-success' : 'bg-danger'} confidence-score">${fnConf.toFixed(0)}%</span>
        </div>`;
        
        // MIDDLE NAME (ID/EAF only)
        if (isIdOrEaf) {
            const mnMatch = idv.middle_name_match;
            const mnConf = parseFloat(idv.middle_name_confidence || 0);
            const mnClass = mnMatch ? 'check-passed' : 'check-failed';
            const mnIcon = mnMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
            html += `<div class="form-check ${mnClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${mnIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>Middle Name</strong> ${mnMatch ? 'Match' : 'Not Found'}</span></div>
                <span class="badge ${mnMatch ? 'bg-success' : 'bg-danger'} confidence-score">${mnConf.toFixed(0)}%</span>
            </div>`;
        }
        
        // LAST NAME
        const lnMatch = idv.last_name_match;
        const lnConf = parseFloat(idv.last_name_confidence || 0);
        const lnClass = lnMatch ? 'check-passed' : 'check-failed';
        const lnIcon = lnMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
        html += `<div class="form-check ${lnClass} d-flex justify-content-between align-items-center">
            <div><i class="bi bi-${lnIcon} me-2" style="font-size:1.2rem;"></i>
            <span><strong>Last Name</strong> ${lnMatch ? 'Match' : 'Not Found'}</span></div>
            <span class="badge ${lnMatch ? 'bg-success' : 'bg-danger'} confidence-score">${lnConf.toFixed(0)}%</span>
        </div>`;
        
        // YEAR LEVEL or BARANGAY
        if (isIdOrEaf) {
            const ylMatch = idv.year_level_match;
            const ylClass = ylMatch ? 'check-passed' : 'check-failed';
            const ylIcon = ylMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
            html += `<div class="form-check ${ylClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${ylIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>Year Level</strong> ${ylMatch ? 'Match' : 'Not Found'}</span></div>
                <span class="badge ${ylMatch ? 'bg-success' : 'bg-secondary'} confidence-score">${ylMatch ? '✓' : '✗'}</span>
            </div>`;
        } else if (isLetter || isCert) {
            const brgyMatch = idv.barangay_match;
            const brgyConf = parseFloat(idv.barangay_confidence || 0);
            const brgyClass = brgyMatch ? 'check-passed' : 'check-failed';
            const brgyIcon = brgyMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
            html += `<div class="form-check ${brgyClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${brgyIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>Barangay</strong> ${brgyMatch ? 'Match' : 'Not Found'}</span></div>
                <span class="badge ${brgyMatch ? 'bg-success' : 'bg-danger'} confidence-score">${brgyConf.toFixed(0)}%</span>
            </div>`;
        }
        
        // UNIVERSITY/SCHOOL (ID/EAF only)
        if (isIdOrEaf) {
            const schoolMatch = idv.school_match || idv.university_match;
            const schoolConf = parseFloat(idv.school_confidence || idv.university_confidence || 0);
            const schoolClass = schoolMatch ? 'check-passed' : 'check-failed';
            const schoolIcon = schoolMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
            html += `<div class="form-check ${schoolClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${schoolIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>University/School</strong> ${schoolMatch ? 'Match' : 'Not Found'}</span></div>
                <span class="badge ${schoolMatch ? 'bg-success' : 'bg-danger'} confidence-score">${schoolConf.toFixed(0)}%</span>
            </div>`;
        } else if (isLetter) {
            const officeMatch = idv.office_header_found;
            const officeConf = parseFloat(idv.office_header_confidence || 0);
            const officeClass = officeMatch ? 'check-passed' : 'check-warning';
            const officeIcon = officeMatch ? 'check-circle-fill text-success' : 'exclamation-circle-fill text-warning';
            html += `<div class="form-check ${officeClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${officeIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>Mayor's Office Header</strong> ${officeMatch ? 'Found' : 'Not Found'}</span></div>
                <span class="badge ${officeMatch ? 'bg-success' : 'bg-warning'} confidence-score">${officeConf.toFixed(0)}%</span>
            </div>`;
        } else if (isCert) {
            const certMatch = idv.certificate_title_found;
            const certConf = parseFloat(idv.certificate_title_confidence || 0);
            const certClass = certMatch ? 'check-passed' : 'check-warning';
            const certIcon = certMatch ? 'check-circle-fill text-success' : 'exclamation-circle-fill text-warning';
            html += `<div class="form-check ${certClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${certIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>Certificate Title</strong> ${certMatch ? 'Found' : 'Not Found'}</span></div>
                <span class="badge ${certMatch ? 'bg-success' : 'bg-warning'} confidence-score">${certConf.toFixed(0)}%</span>
            </div>`;
        }
        
        // OFFICIAL KEYWORDS (ID/EAF only)
        if (isIdOrEaf) {
            const kwMatch = idv.official_keywords;
            const kwConf = parseFloat(idv.keywords_confidence || 0);
            const kwClass = kwMatch ? 'check-passed' : 'check-failed';
            const kwIcon = kwMatch ? 'check-circle-fill text-success' : 'x-circle-fill text-danger';
            html += `<div class="form-check ${kwClass} d-flex justify-content-between align-items-center">
                <div><i class="bi bi-${kwIcon} me-2" style="font-size:1.2rem;"></i>
                <span><strong>Official Document Keywords</strong> ${kwMatch ? 'Found' : 'Not Found'}</span></div>
                <span class="badge ${kwMatch ? 'bg-success' : 'bg-danger'} confidence-score">${kwConf.toFixed(0)}%</span>
            </div>`;
        }
        
        html += '</div></div></div>'; // Close checklist, card-body, card
        
        // === OVERALL SUMMARY ===
        const avgConf = parseFloat(idv.average_confidence || validation.ocr_confidence || 0);
        const passedChecks = idv.passed_checks || 0;
        const totalChecks = idv.total_checks || 6;
        const verificationScore = ((passedChecks / totalChecks) * 100);
        
        let statusMessage = '';
        let statusClass = '';
        let statusIcon = '';
        
        if (verificationScore >= 80) {
            statusMessage = 'Document validation successful';
            statusClass = 'alert-success';
            statusIcon = 'check-circle-fill';
        } else if (verificationScore >= 60) {
            statusMessage = 'Document validation passed with warnings';
            statusClass = 'alert-warning';
            statusIcon = 'exclamation-triangle-fill';
        } else {
            statusMessage = 'Document validation failed - manual review required';
            statusClass = 'alert-danger';
            statusIcon = 'x-circle-fill';
        }
        
        html += `<div class="card mb-4"><div class="card-header bg-light"><h6 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Overall Analysis</h6></div><div class="card-body">`;
        html += `<div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="text-center p-3 bg-light rounded">
                    <small class="text-muted d-block mb-1">Average Confidence</small>
                    <h4 class="mb-0 fw-bold text-primary">${avgConf.toFixed(1)}%</h4>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-center p-3 bg-light rounded">
                    <small class="text-muted d-block mb-1">Passed Checks</small>
                    <h4 class="mb-0 fw-bold text-success">${passedChecks}/${totalChecks}</h4>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-center p-3 bg-light rounded">
                    <small class="text-muted d-block mb-1">Verification Score</small>
                    <h4 class="mb-0 fw-bold ${verificationScore >= 80 ? 'text-success' : (verificationScore >= 60 ? 'text-warning' : 'text-danger')}">${verificationScore.toFixed(0)}%</h4>
                </div>
            </div>
        </div>`;
        
        html += `<div class="alert ${statusClass} mb-0">
            <h6 class="mb-0"><i class="bi bi-${statusIcon} me-2"></i>${statusMessage}</h6>`;
        if (idv.recommendation) {
            html += `<small class="mt-2 d-block"><strong>Recommendation:</strong> ${idv.recommendation}</small>`;
        }
        html += `</div></div></div>`; // Close card-body, card
    }
    
    // === EXTRACTED GRADES (for grades document) ===
    if (docType === 'grades' && validation.extracted_grades) {
        html += '<div class="card mb-4"><div class="card-header bg-success text-white">';
        html += '<h6 class="mb-0"><i class="bi bi-list-check me-2"></i>Extracted Grades</h6>';
        html += '</div><div class="card-body p-0"><div class="table-responsive">';
        html += '<table class="table table-bordered table-hover mb-0"><thead class="table-light"><tr><th>Subject</th><th>Grade</th><th>Confidence</th><th>Status</th></tr></thead><tbody>';
        
        validation.extracted_grades.forEach(grade => {
            const conf = parseFloat(grade.extraction_confidence || 0);
            const confColor = conf >= 80 ? 'success' : (conf >= 60 ? 'warning' : 'danger');
            const statusIcon = grade.is_passing === 't' ? 'check-circle-fill' : 'x-circle-fill';
            const statusColor = grade.is_passing === 't' ? 'success' : 'danger';
            
            html += `<tr>
                <td>${grade.subject_name || 'N/A'}</td>
                <td><strong>${grade.grade_value || 'N/A'}</strong></td>
                <td><span class="badge bg-${confColor}">${conf.toFixed(1)}%</span></td>
                <td><i class="bi bi-${statusIcon} text-${statusColor}"></i> ${grade.is_passing === 't' ? 'Passing' : 'Failing'}</td>
            </tr>`;
        });
        
        html += '</tbody></table></div></div></div>';
        
        if (validation.validation_status) {
            const statusColors = {'passed': 'success', 'failed': 'danger', 'manual_review': 'warning', 'pending': 'info'};
            const statusColor = statusColors[validation.validation_status] || 'secondary';
            html += `<div class="alert alert-${statusColor}"><strong>Grade Validation Status:</strong> ${validation.validation_status.toUpperCase().replace('_', ' ')}</div>`;
        }
    }
    
    // === EXTRACTED TEXT ===
    if (validation.extracted_text) {
        html += '<div class="card"><div class="card-header bg-secondary text-white">';
        html += '<h6 class="mb-0"><i class="bi bi-file-text me-2"></i>Extracted Text (OCR)</h6>';
        html += '</div><div class="card-body">';
        const textPreview = validation.extracted_text.substring(0, 2000);
        const hasMore = validation.extracted_text.length > 2000;
        html += `<pre style="max-height:400px;overflow-y:auto;font-size:0.85em;white-space:pre-wrap;background:#f8f9fa;padding:15px;border-radius:4px;border:1px solid #dee2e6;">${textPreview}${hasMore ? '\n\n... (text truncated)' : ''}</pre>`;
        html += '</div></div>';
    }
    
    return html;
}

// Show validation modal without closing parent student info modal
function showValidationModal() {
    // Get or create the validation modal instance
    const validationModalEl = document.getElementById('validationModal');
    let validationModal = bootstrap.Modal.getInstance(validationModalEl);
    
    if (!validationModal) {
        validationModal = new bootstrap.Modal(validationModalEl, {
            backdrop: 'static',
            keyboard: true,
            focus: true
        });
    }
    
    // Create custom backdrop to dim the student info modal
    let backdrop = document.getElementById('validationModalBackdrop');
    if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.id = 'validationModalBackdrop';
        backdrop.className = 'validation-backdrop';
        document.body.appendChild(backdrop);
    }
    
    // Show backdrop
    backdrop.classList.add('show');
    
    // Show the validation modal (it will appear on top of student info modal)
    validationModal.show();
    
    // Hide backdrop when modal is closed
    validationModalEl.addEventListener('hidden.bs.modal', function() {
        backdrop.classList.remove('show');
    }, { once: true });
}
</script>

<!-- Validation Modal -->
<div class="modal fade" id="validationModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="validationModalLabel">
                    <i class="bi bi-clipboard-check me-2"></i>Validation Results
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="validationModalBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-info" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3 text-muted">Loading validation data...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

</body>
</html>
<?php pg_close($connection); ?>