<?php
require_once __DIR__ . '/../functions/bootstrap.php';
// Admin - SF1 Bulk Import Action Handler
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}


header('Content-Type: application/json');

function sf1ImportFail(string $message, int $status = 200): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

function sf1UploadErrorMessage(int $errorCode): string {
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is larger than the server upload limit. Please upload a file up to 10MB or increase upload_max_filesize/post_max_size.',
        UPLOAD_ERR_PARTIAL => 'File upload was interrupted. Please try uploading the SF1 file again.',
        UPLOAD_ERR_NO_FILE => 'No SF1 file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server upload temporary directory is missing. Please contact the administrator.',
        UPLOAD_ERR_CANT_WRITE => 'Server could not save the uploaded file. Please contact the administrator.',
        UPLOAD_ERR_EXTENSION => 'A server extension blocked the upload. Please contact the administrator.',
        default => 'No file uploaded or upload error.',
    };
}

function sf1XlsxRuntimeReady(): bool {
    return (class_exists('ZipArchive') || function_exists('zip_open')) && function_exists('simplexml_load_string');
}

// CSRF check
$csrfToken = trim((string)($_POST['csrf_token'] ?? ''));
if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrfToken)) {
    sf1ImportFail('Invalid CSRF token.', 403);
}

$db = (new Database())->getConnection();
if (!$db) {
    sf1ImportFail('Database error.', 500);
}
ensureStrengthenedShsColumns($db);

$file = $_FILES['sf1_csv'] ?? $_FILES['sf1_file'] ?? null;
if (!$file || !isset($file['error'])) {
    sf1ImportFail('No SF1 file was uploaded.', 400);
}
if ((int)$file['error'] !== UPLOAD_ERR_OK) {
    sf1ImportFail(sf1UploadErrorMessage((int)$file['error']), 400);
}
if ($file['size'] > 10 * 1024 * 1024) {
    sf1ImportFail('File too large. Max 10MB.', 413);
}

$academicYear = trim((string)($_POST['academic_year'] ?? date('Y') . '-' . (date('Y') + 1)));
if (!preg_match('/^\d{4}-\d{4}$/', $academicYear)) {
    $academicYear = date('Y') . '-' . (date('Y') + 1);
}

$fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$isXlsx = $fileExt === 'xlsx';
if (!in_array($fileExt, ['csv', 'xlsx'], true)) {
    sf1ImportFail('Invalid file type. Please upload a CSV or XLSX file.', 422);
}

$results = ['success' => true, 'created' => 0, 'skipped' => 0, 'errors' => 0, 'rows' => []];
$importRows = [];
$headerInfo = [];

if ($isXlsx) {
    if (!sf1XlsxRuntimeReady()) {
        sf1ImportFail('XLSX import is unavailable on this server. Enable PHP zip and SimpleXML extensions, or upload the SF1 data as CSV.', 500);
    }
    try {
        $sf1 = new Sf1Parser($file['tmp_name']);
        $parsed = $sf1->parse();
        if (!empty($parsed['errors'])) {
            sf1ImportFail(implode(' ', $parsed['errors']), 422);
        }
        $headerInfo = $parsed['header'];
        $importRows = $parsed['students'];
        $results['_header'] = $headerInfo;
        if (isset($headerInfo['school_year']) && $headerInfo['school_year'] !== '') {
            $syFromTemplate = preg_replace('/\s+/', '', $headerInfo['school_year']);
            if (preg_match('/^\d{4}-\d{4}$/', $syFromTemplate)) {
                $academicYear = $syFromTemplate;
            }
        }
    } catch (Throwable $e) {
        error_log('SF1 XLSX parse error: ' . $e->getMessage());
        sf1ImportFail('Failed to parse XLSX. Please confirm the file uses the official DepEd SF1 .xlsx template and that server XLSX support is enabled.', 422);
    }
} else {
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        sf1ImportFail('Cannot read CSV file.', 422);
    }
    $firstChunk = fread($handle, 3);
    if ($firstChunk !== "\xEF\xBB\xBF") {
        rewind($handle);
    }
    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        sf1ImportFail('Empty CSV file.', 422);
    }
    $headers = array_map(function($h) { return strtolower(trim(preg_replace('/\s+/', ' ', (string)$h))); }, $headers);

    $colMap = [
        'lrn' => false, 'last_name' => false, 'first_name' => false, 'middle_name' => false,
        'sex' => false, 'grade_level' => false, 'section' => false, 'date_of_birth' => false,
        'address' => false, 'contact_number' => false, 'parent_name' => false, 'track' => false,
    ];
    foreach ($headers as $idx => $h) {
        if (str_contains($h, 'lrn')) $colMap['lrn'] = $idx;
        elseif (str_contains($h, 'last')) $colMap['last_name'] = $idx;
        elseif (str_contains($h, 'first')) $colMap['first_name'] = $idx;
        elseif (str_contains($h, 'middle')) $colMap['middle_name'] = $idx;
        elseif (str_contains($h, 'sex') || str_contains($h, 'gender')) $colMap['sex'] = $idx;
        elseif (str_contains($h, 'grade')) $colMap['grade_level'] = $idx;
        elseif (str_contains($h, 'section')) $colMap['section'] = $idx;
        elseif (str_contains($h, 'birth') || str_contains($h, 'dob')) $colMap['date_of_birth'] = $idx;
        elseif (str_contains($h, 'address')) $colMap['address'] = $idx;
        elseif (str_contains($h, 'contact')) $colMap['contact_number'] = $idx;
        elseif (str_contains($h, 'parent') || str_contains($h, 'guardian')) $colMap['parent_name'] = $idx;
        elseif (str_contains($h, 'track') || str_contains($h, 'program')) $colMap['track'] = $idx;
    }
    if ($colMap['lrn'] === false || $colMap['last_name'] === false || $colMap['first_name'] === false) {
        fclose($handle);
        sf1ImportFail('Required columns (LRN, Last Name, First Name) not found.', 422);
    }
    while (($row = fgetcsv($handle)) !== false) {
        if (count(array_filter($row, function($v) { return trim($v) !== ''; })) === 0) continue;
        $importRows[] = [
            'lrn'              => preg_replace('/\D/', '', (string)($row[$colMap['lrn']] ?? '')),
            'last_name'        => trim((string)($row[$colMap['last_name']] ?? '')),
            'first_name'       => trim((string)($row[$colMap['first_name']] ?? '')),
            'middle_name'      => trim((string)($row[$colMap['middle_name']] ?? '')),
            'sex'              => trim((string)($row[$colMap['sex']] ?? '')),
            'grade_level'      => (int)($row[$colMap['grade_level']] ?? 0),
            'section'          => trim((string)($row[$colMap['section']] ?? '')),
            'birthdate'        => trim((string)($row[$colMap['date_of_birth']] ?? '')),
            'address'          => trim((string)($row[$colMap['address']] ?? '')),
            'contact_number'   => trim((string)($row[$colMap['contact_number']] ?? '')),
            'track'            => trim((string)($row[$colMap['track']] ?? '')),
        ];
    }
    fclose($handle);
}

$defaultPassword = getDefaultNewUserPassword();
$hashedPassword = password_hash($defaultPassword, PASSWORD_BCRYPT);
$rowNum = 1;

function sf1GetTrack(array $row): string {
    $raw = $row['track'] ?? '';
    if ($raw !== '') {
        $lower = strtolower($raw);
        if (str_contains($lower, 'techpro') || str_contains($lower, 'technical') || str_contains($lower, 'professional') || str_contains($lower, 'tvl')) {
            return 'techpro';
        }
        if (str_contains($lower, 'academic')) return 'academic';
    }
    return 'academic';
}

function sf1GradeFromHeader(array $header): int {
    $raw = trim((string)($header['grade_level'] ?? ''));
    if (preg_match('/\b(11|12)\b/', $raw, $m)) {
        return (int)$m[1];
    }
    return 0;
}

foreach ($importRows as $row) {
    $rowNum++;
    $lrn = preg_replace('/\D/', '', (string)($row['lrn'] ?? ''));
    $lastName = trim((string)($row['last_name'] ?? ''));
    $firstName = trim((string)($row['first_name'] ?? ''));
    $middleName = trim((string)($row['middle_name'] ?? ''));
    $sex = strtolower(trim((string)(Sf1Parser::normalizeSex($row['sex'] ?? '') ?? '')));
    $gradeLevel = (int)($row['grade_level'] ?? 0);
    if ($gradeLevel <= 0) {
        $gradeLevel = sf1GradeFromHeader($headerInfo);
    }
    $section = trim((string)($row['section'] ?? ''));
    if ($section === '') {
        $section = trim((string)($headerInfo['section'] ?? ''));
    }
    $track = sf1GetTrack($row);
    if (empty($row['track']) && !empty($headerInfo['track_strand'])) {
        $track = sf1GetTrack(['track' => (string)$headerInfo['track_strand']]);
    }
    $dateOfBirth = trim((string)($row['birthdate'] ?? ''));
    $address = trim((string)($row['address'] ?? ''));
    $contactNumber = trim((string)($row['contact_number'] ?? ''));

    if ($lrn === '' || $lastName === '' || $firstName === '') {
        $results['rows'][] = ['row' => $rowNum, 'lrn' => $lrn, 'name' => "$firstName $lastName", 'status' => 'error', 'message' => 'Missing required fields (LRN, Last Name, First Name).'];
        $results['errors']++;
        continue;
    }
    if (strlen($lrn) !== 12) {
        $results['rows'][] = ['row' => $rowNum, 'lrn' => $lrn, 'name' => "$firstName $lastName", 'status' => 'error', 'message' => 'LRN must be exactly 12 digits.'];
        $results['errors']++;
        continue;
    }
    if (!in_array($gradeLevel, [11, 12], true)) {
        $results['rows'][] = ['row' => $rowNum, 'lrn' => $lrn, 'name' => "$firstName $lastName", 'status' => 'error', 'message' => 'Grade level must be 11 or 12.'];
        $results['errors']++;
        continue;
    }
    if (!in_array($sex, ['male', 'female'], true)) $sex = '';
    if ($section === '') {
        $results['rows'][] = ['row' => $rowNum, 'lrn' => $lrn, 'name' => "$firstName $lastName", 'status' => 'error', 'message' => 'Section is required.'];
        $results['errors']++;
        continue;
    }
    if (!in_array($track, ['academic', 'techpro'], true)) {
        $results['rows'][] = ['row' => $rowNum, 'lrn' => $lrn, 'name' => "$firstName $lastName", 'status' => 'error', 'message' => 'Track must be "academic" or "techpro".'];
        $results['errors']++;
        continue;
    }

    try {
        $lrnCheck = $db->prepare("SELECT id FROM users WHERE lrn = ? LIMIT 1");
        $lrnCheck->execute([$lrn]);
        if ($lrnCheck->fetch()) {
            $results['rows'][] = ['row' => $rowNum, 'lrn' => $lrn, 'name' => "$firstName $lastName", 'status' => 'skipped', 'message' => 'LRN already exists in the system.'];
            $results['skipped']++;
            continue;
        }
    } catch (Throwable $e) {}

    $refCode = generateReferenceCode('student', $db, $academicYear);
    $emailBase = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstName . '.' . $lastName));
    $email = $emailBase . '.' . $lrn . '@students.balingasag.edu.ph';
    $curriculum = strengthenedShsCurriculum($gradeLevel, $academicYear);
    $program = strengthenedShsProgram($gradeLevel, $track, $academicYear);

    $nameExtension = trim((string)($row['name_extension'] ?? ''));
    $religion = trim((string)($row['religion'] ?? ''));
    $fatherName = trim((string)($row['father_name'] ?? ''));
    $motherName = trim((string)($row['mother_name'] ?? ''));
    $guardianName = trim((string)($row['guardian_name'] ?? ''));
    $guardianRelationship = trim((string)($row['relationship'] ?? ''));

    if ($dateOfBirth !== '') {
        $parsedDate = Sf1Parser::parseBirthdate($dateOfBirth);
        if ($parsedDate !== null) $dateOfBirth = $parsedDate;
    }

    try {
        $insertStmt = $db->prepare("INSERT INTO users
            (reference_code, email, lrn, password, first_name, middle_name, last_name, name_extension, sex, date_of_birth, religion, contact_number, address, father_name, mother_name, guardian_name, guardian_relationship, grade_level, section, track, curriculum, program, role, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'student', 'active', NOW(), NOW())");
        $insertStmt->execute([
            $refCode, $email, $lrn, $hashedPassword,
            $firstName, $middleName ?: null, $lastName,
            $nameExtension ?: null,
            $sex ?: null, $dateOfBirth ?: null, $religion ?: null,
            $contactNumber ?: null, $address ?: null,
            $fatherName ?: null, $motherName ?: null,
            $guardianName ?: null, $guardianRelationship ?: null,
            $gradeLevel, $section, $track, $curriculum, $program
        ]);

        $newUserId = $db->lastInsertId();
        if ($newUserId) {
            syncStudentEnrollments($db, (int)$newUserId, $gradeLevel, $section, $track, $academicYear);
        }

        $results['rows'][] = ['row' => $rowNum, 'lrn' => $lrn, 'name' => "$firstName $lastName", 'status' => 'created', 'message' => "Created with ref code: $refCode"];
        $results['created']++;
    } catch (Throwable $e) {
        $errMsg = str_contains($e->getMessage(), 'Duplicate') ? 'Email or reference code already exists.' : 'Database error.';
        $results['rows'][] = ['row' => $rowNum, 'lrn' => $lrn, 'name' => "$firstName $lastName", 'status' => 'error', 'message' => $errMsg];
        $results['errors']++;
    }
}

if (isset($handle)) {
    fclose($handle);
}

if ($results['created'] === 0 && $results['errors'] === 0 && $results['skipped'] === 0) {
    $results['message'] = 'No valid data rows found in the CSV.';
    $results['success'] = false;
} elseif ($results['errors'] > 0 && $results['created'] === 0) {
    $results['success'] = false;
    $results['message'] = 'Import failed. Please check the errors above.';
} else {
    $results['message'] = $results['created'] . ' students imported successfully.';
}

recordAdminAuditLog($db, 'sf1.import', 'student_import', null, [
    'file_name' => (string)($file['name'] ?? ''),
    'academic_year' => $academicYear,
    'created' => (int)$results['created'],
    'skipped' => (int)$results['skipped'],
    'errors' => (int)$results['errors'],
    'success' => (bool)$results['success'],
]);

echo json_encode($results);



