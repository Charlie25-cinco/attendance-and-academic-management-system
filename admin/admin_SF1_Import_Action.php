<?php
require_once __DIR__ . '/../functions/bootstrap.php';
// Admin - SF1 Bulk Import Action Handler
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}


header('Content-Type: application/json');

// CSRF check
$csrfToken = trim((string)($_POST['csrf_token'] ?? ''));
if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit();
}

$db = (new Database())->getConnection();
if (!$db) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit();
}
ensureStrengthenedShsColumns($db);

$action = trim((string)($_POST['action'] ?? ''));

function sf1UploadErrorMessage(int $errorCode): string {
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'The SF1 file is larger than the server upload limit. Use a smaller file or increase PHP upload limits.';
        case UPLOAD_ERR_PARTIAL:
            return 'The SF1 file was only partially uploaded. Please try again.';
        case UPLOAD_ERR_NO_FILE:
            return 'No SF1 file was uploaded.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'The server upload folder is missing. Please check deployment storage settings.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'The server could not save the uploaded SF1 file. Please check deployment storage permissions.';
        case UPLOAD_ERR_EXTENSION:
            return 'A PHP extension blocked the SF1 upload.';
        default:
            return 'The SF1 upload failed. Please try again.';
    }
}

function sf1NormalizeSex(string $value): string {
    $v = strtolower(trim($value));
    if ($v === 'm' || $v === 'male' || str_starts_with($v, 'm')) return 'male';
    if ($v === 'f' || $v === 'female' || str_starts_with($v, 'f')) return 'female';
    return '';
}

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

function ensureSf1Section(PDO $db, string $section, int $gradeLevel, string $track, string $academicYear): array {
    static $resolvedCache = [];

    $sectionTrimmed = trim($section);
    if ($sectionTrimmed === '' || $gradeLevel <= 0) {
        return ['id' => 0, 'name' => $sectionTrimmed, 'created' => false];
    }

    $trackNorm = in_array(strtolower(trim($track)), ['academic', 'techpro'], true) ? strtolower(trim($track)) : 'academic';
    $cacheKey = spl_object_id($db) . '|' . strtolower($gradeLevel . '|' . $trackNorm . '|' . $sectionTrimmed);
    if (isset($resolvedCache[$cacheKey])) {
        return $resolvedCache[$cacheKey];
    }

    $curriculum = strengthenedShsCurriculum($gradeLevel, $academicYear);
    $program = strengthenedShsProgram($gradeLevel, $trackNorm, $academicYear);

    $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $sectionSql = "(LOWER(TRIM(COALESCE(name, ''))) = LOWER(TRIM(COALESCE(?, ''))))";
        $params = [$gradeLevel, $trackNorm, $sectionTrimmed];
    } else {
        $sectionSql = sectionMatchSql('name');
        $params = [$gradeLevel, $trackNorm, $sectionTrimmed, $sectionTrimmed];
    }

    // 1. Resolve existing section strictly using grade level, track, and normalized section name
    $stmt = $db->prepare("SELECT id, name, grade_level, track, curriculum, program
                          FROM sections
                          WHERE grade_level = ?
                            AND LOWER(TRIM(track)) = LOWER(TRIM(?))
                            AND ({$sectionSql})
                          LIMIT 1");
    $stmt->execute($params);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $secId = (int)$existing['id'];
        $secName = (string)$existing['name'];

        // Backfill missing curriculum/program on existing section if needed
        $updates = [];
        $updateParams = [];
        if (empty($existing['curriculum']) && !empty($curriculum)) {
            $updates[] = "curriculum = ?";
            $updateParams[] = $curriculum;
        }
        if (empty($existing['program']) && !empty($program)) {
            $updates[] = "program = ?";
            $updateParams[] = $program;
        }
        if (!empty($updates)) {
            $updateParams[] = $secId;
            $db->prepare("UPDATE sections SET " . implode(', ', $updates) . " WHERE id = ?")->execute($updateParams);
        }

        $result = ['id' => $secId, 'name' => $secName, 'created' => false];
        $resolvedCache[$cacheKey] = $result;
        return $result;
    }

    // 2. Not found: insert new section for this grade and track context
    try {
        $insert = $db->prepare("INSERT INTO sections (name, grade_level, track, curriculum, program) VALUES (?, ?, ?, ?, ?)");
        $insert->execute([$sectionTrimmed, $gradeLevel, $trackNorm, $curriculum, $program]);
        $newId = (int)$db->lastInsertId();

        recordAdminAuditLog($db, 'section.auto_create_from_sf1', 'section', $newId, [
            'name' => $sectionTrimmed,
            'grade_level' => $gradeLevel,
            'track' => $trackNorm,
            'curriculum' => $curriculum,
            'program' => $program,
            'academic_year' => $academicYear,
        ]);

        $result = ['id' => $newId, 'name' => $sectionTrimmed, 'created' => true];
        $resolvedCache[$cacheKey] = $result;
        return $result;
    } catch (PDOException $e) {
        $code = (string)$e->getCode();
        $msg = $e->getMessage();
        $isUniqueViolation = ($code === '23000' || str_contains($msg, 'Duplicate') || str_contains($msg, 'UNIQUE constraint failed') || str_contains($msg, 'Integrity constraint violation'));
        if (!$isUniqueViolation) {
            throw $e;
        }

        // In case of a race condition or unique constraint, resolve the existing record for this grade & track
        $fallback = $db->prepare("SELECT id, name FROM sections WHERE grade_level = ? AND LOWER(TRIM(track)) = LOWER(TRIM(?)) AND LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
        $fallback->execute([$gradeLevel, $trackNorm, $sectionTrimmed]);
        $row = $fallback->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $result = ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'created' => false];
            $resolvedCache[$cacheKey] = $result;
            return $result;
        }

        throw $e;
    }
}

function parseSf1UploadedFile(array $file): array {
    $fileExt = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $isXlsx = $fileExt === 'xlsx';
    if (!in_array($fileExt, ['csv', 'xlsx'], true)) {
        throw new InvalidArgumentException('Invalid file type. Please upload a CSV or XLSX file.');
    }

    $headerInfo = [];
    $importRows = [];

    if ($isXlsx) {
        if (!class_exists('ZipArchive') && !function_exists('zip_open') && !function_exists('gzinflate')) {
            throw new RuntimeException('XLSX import requires ZIP support on the server. Upload CSV instead or enable zip/zlib in PHP.');
        }
        if (!class_exists('SimpleXMLElement')) {
            throw new RuntimeException('XLSX import requires SimpleXML extension on the server. Upload CSV instead.');
        }

        $sf1 = new Sf1Parser($file['tmp_name']);
        $parsed = $sf1->parse();
        if (!empty($parsed['errors'])) {
            throw new RuntimeException(implode(' ', $parsed['errors']));
        }
        $headerInfo = $parsed['header'] ?? [];
        $importRows = $parsed['students'] ?? [];
    } else {
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            throw new RuntimeException('Cannot read CSV file.');
        }
        $firstChunk = fread($handle, 3);
        if ($firstChunk !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            throw new RuntimeException('Empty CSV file.');
        }
        $headers = array_map(function($h) { return strtolower(trim(preg_replace('/\s+/', ' ', (string)$h))); }, $headers);

        $colMap = [
            'lrn' => false, 'last_name' => false, 'first_name' => false, 'middle_name' => false,
            'name_extension' => false, 'sex' => false, 'grade_level' => false, 'section' => false,
            'date_of_birth' => false, 'address' => false, 'house_street' => false, 'barangay' => false,
            'municipality' => false, 'province' => false, 'contact_number' => false, 'parent_name' => false,
            'father_name' => false, 'mother_name' => false, 'guardian_name' => false, 'relationship' => false,
            'track' => false,
        ];
        foreach ($headers as $idx => $h) {
            if (str_contains($h, 'lrn')) $colMap['lrn'] = $idx;
            elseif (str_contains($h, 'last')) $colMap['last_name'] = $idx;
            elseif (str_contains($h, 'first')) $colMap['first_name'] = $idx;
            elseif (str_contains($h, 'middle')) $colMap['middle_name'] = $idx;
            elseif (str_contains($h, 'ext')) $colMap['name_extension'] = $idx;
            elseif (str_contains($h, 'sex') || str_contains($h, 'gender')) $colMap['sex'] = $idx;
            elseif (str_contains($h, 'grade')) $colMap['grade_level'] = $idx;
            elseif (str_contains($h, 'section')) $colMap['section'] = $idx;
            elseif (str_contains($h, 'birth') || str_contains($h, 'dob')) $colMap['date_of_birth'] = $idx;
            elseif (str_contains($h, 'street')) $colMap['house_street'] = $idx;
            elseif (str_contains($h, 'barangay')) $colMap['barangay'] = $idx;
            elseif (str_contains($h, 'municipality') || str_contains($h, 'city')) $colMap['municipality'] = $idx;
            elseif (str_contains($h, 'province')) $colMap['province'] = $idx;
            elseif (str_contains($h, 'address')) $colMap['address'] = $idx;
            elseif (str_contains($h, 'contact') || str_contains($h, 'phone')) $colMap['contact_number'] = $idx;
            elseif (str_contains($h, 'father')) $colMap['father_name'] = $idx;
            elseif (str_contains($h, 'mother')) $colMap['mother_name'] = $idx;
            elseif (str_contains($h, 'guardian')) $colMap['guardian_name'] = $idx;
            elseif (str_contains($h, 'parent')) $colMap['parent_name'] = $idx;
            elseif (str_contains($h, 'relation')) $colMap['relationship'] = $idx;
            elseif (str_contains($h, 'track') || str_contains($h, 'program')) $colMap['track'] = $idx;
        }

        if ($colMap['lrn'] === false || $colMap['last_name'] === false || $colMap['first_name'] === false) {
            fclose($handle);
            throw new RuntimeException('Required columns (LRN, Last Name, First Name) not found in CSV.');
        }

        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_filter($row, function($v) { return trim($v) !== ''; })) === 0) continue;
            $importRows[] = [
                'lrn'              => preg_replace('/\D/', '', (string)($row[$colMap['lrn']] ?? '')),
                'last_name'        => trim((string)($row[$colMap['last_name']] ?? '')),
                'first_name'       => trim((string)($row[$colMap['first_name']] ?? '')),
                'middle_name'      => $colMap['middle_name'] !== false ? trim((string)($row[$colMap['middle_name']] ?? '')) : '',
                'name_extension'   => $colMap['name_extension'] !== false ? trim((string)($row[$colMap['name_extension']] ?? '')) : '',
                'sex'              => $colMap['sex'] !== false ? trim((string)($row[$colMap['sex']] ?? '')) : '',
                'grade_level'      => $colMap['grade_level'] !== false ? (int)($row[$colMap['grade_level']] ?? 0) : 0,
                'section'          => $colMap['section'] !== false ? trim((string)($row[$colMap['section']] ?? '')) : '',
                'birthdate'        => $colMap['date_of_birth'] !== false ? trim((string)($row[$colMap['date_of_birth']] ?? '')) : '',
                'address'          => $colMap['address'] !== false ? trim((string)($row[$colMap['address']] ?? '')) : '',
                'house_street'     => $colMap['house_street'] !== false ? trim((string)($row[$colMap['house_street']] ?? '')) : '',
                'barangay'         => $colMap['barangay'] !== false ? trim((string)($row[$colMap['barangay']] ?? '')) : '',
                'municipality'     => $colMap['municipality'] !== false ? trim((string)($row[$colMap['municipality']] ?? '')) : '',
                'province'         => $colMap['province'] !== false ? trim((string)($row[$colMap['province']] ?? '')) : '',
                'contact_number'   => $colMap['contact_number'] !== false ? trim((string)($row[$colMap['contact_number']] ?? '')) : '',
                'parent_name'      => $colMap['parent_name'] !== false ? trim((string)($row[$colMap['parent_name']] ?? '')) : '',
                'father_name'      => $colMap['father_name'] !== false ? trim((string)($row[$colMap['father_name']] ?? '')) : '',
                'mother_name'      => $colMap['mother_name'] !== false ? trim((string)($row[$colMap['mother_name']] ?? '')) : '',
                'guardian_name'    => $colMap['guardian_name'] !== false ? trim((string)($row[$colMap['guardian_name']] ?? '')) : '',
                'relationship'     => $colMap['relationship'] !== false ? trim((string)($row[$colMap['relationship']] ?? '')) : '',
                'track'            => $colMap['track'] !== false ? trim((string)($row[$colMap['track']] ?? '')) : '',
            ];
        }
        fclose($handle);
    }

    return ['header' => $headerInfo, 'students' => $importRows];
}

// -------------------------------------------------------------
// 1. ACTION: PREVIEW (Parse and Return Editable Learner Grid)
// -------------------------------------------------------------
if ($action === 'preview' || (isset($_FILES['sf1_file']) && $action !== 'commit')) {
    $file = $_FILES['sf1_csv'] ?? $_FILES['sf1_file'] ?? null;
    if (!$file) {
        echo json_encode(['success' => false, 'message' => 'No SF1 file was uploaded.']);
        exit();
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => sf1UploadErrorMessage((int)$file['error'])]);
        exit();
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'File too large. Max 10MB.']);
        exit();
    }

    $academicYear = trim((string)($_POST['academic_year'] ?? date('Y') . '-' . (date('Y') + 1)));
    if (!preg_match('/^\d{4}-\d{4}$/', $academicYear)) {
        $academicYear = date('Y') . '-' . (date('Y') + 1);
    }

    try {
        $parsed = parseSf1UploadedFile($file);
        $headerInfo = $parsed['header'];
        $rawStudents = $parsed['students'];

        if (isset($headerInfo['school_year']) && $headerInfo['school_year'] !== '') {
            $syFromTemplate = preg_replace('/\s+/', '', (string)$headerInfo['school_year']);
            if (preg_match('/^\d{4}-\d{4}$/', $syFromTemplate)) {
                $academicYear = $syFromTemplate;
            }
        }

        $detectedGrade = sf1GradeFromHeader($headerInfo);
        $detectedSection = trim((string)($headerInfo['section'] ?? ''));
        $detectedTrack = sf1GetTrack(['track' => (string)($headerInfo['track_strand'] ?? '')]);

        // Preload existing LRNs in a single query
        $existingMap = [];
        if (!empty($rawStudents)) {
            $lrnList = array_values(array_filter(array_map(function($r) {
                return preg_replace('/\D/', '', (string)($r['lrn'] ?? ''));
            }, $rawStudents)));

            if (!empty($lrnList)) {
                $placeholders = implode(',', array_fill(0, count($lrnList), '?'));
                $existStmt = $db->prepare("SELECT id, lrn, first_name, last_name, section, grade_level FROM users WHERE lrn IN ($placeholders)");
                $existStmt->execute($lrnList);
                while ($ex = $existStmt->fetch(PDO::FETCH_ASSOC)) {
                    $existingMap[$ex['lrn']] = $ex;
                }
            }
        }

        $previewStudents = [];
        $summary = [
            'total' => 0,
            'male' => 0,
            'female' => 0,
            'new' => 0,
            'existing' => 0,
            'invalid_lrn' => 0,
        ];

        $idx = 0;
        foreach ($rawStudents as $row) {
            $idx++;
            $lrn = preg_replace('/\D/', '', (string)($row['lrn'] ?? ''));
            $lastName = trim((string)($row['last_name'] ?? ''));
            $firstName = trim((string)($row['first_name'] ?? ''));
            $middleName = trim((string)($row['middle_name'] ?? ''));
            $nameExtension = trim((string)($row['name_extension'] ?? ''));
            $sex = sf1NormalizeSex((string)($row['sex'] ?? ''));

            $gradeLevel = (int)($row['grade_level'] ?? 0);
            if ($gradeLevel <= 0) $gradeLevel = $detectedGrade > 0 ? $detectedGrade : 11;

            $section = trim((string)($row['section'] ?? ''));
            if ($section === '') $section = $detectedSection;

            $track = sf1GetTrack($row);
            if (empty($row['track']) && !empty($headerInfo['track_strand'])) {
                $track = $detectedTrack;
            }

            $dateOfBirth = trim((string)($row['birthdate'] ?? ''));
            if ($dateOfBirth !== '') {
                $parsedDate = Sf1Parser::parseBirthdate($dateOfBirth);
                if ($parsedDate !== null) $dateOfBirth = $parsedDate;
            }

            $houseStreet = trim((string)($row['house_street'] ?? ''));
            $barangay = trim((string)($row['barangay'] ?? ''));
            $municipality = trim((string)($row['municipality'] ?? ''));
            $province = trim((string)($row['province'] ?? ''));
            $address = trim((string)($row['address'] ?? ''));
            if ($address === '') {
                $address = implode(', ', array_filter([$houseStreet, $barangay, $municipality, $province]));
            }

            $contactNumber = trim((string)($row['contact_number'] ?? ''));
            $parentName = trim((string)($row['parent_name'] ?? ''));
            $fatherName = trim((string)($row['father_name'] ?? ''));
            $motherName = trim((string)($row['mother_name'] ?? ''));
            $guardianName = trim((string)($row['guardian_name'] ?? ''));
            $relationship = trim((string)($row['relationship'] ?? ''));

            if ($parentName === '') {
                if ($fatherName !== '') $parentName = $fatherName;
                elseif ($motherName !== '') $parentName = $motherName;
                elseif ($guardianName !== '') $parentName = $guardianName;
            }

            $isExisting = isset($existingMap[$lrn]);
            $isValidLrn = strlen($lrn) === 12;

            $summary['total']++;
            if ($sex === 'male') $summary['male']++;
            elseif ($sex === 'female') $summary['female']++;

            if ($isExisting) $summary['existing']++;
            else $summary['new']++;

            if (!$isValidLrn) $summary['invalid_lrn']++;

            $previewStudents[] = [
                'temp_id'        => $idx,
                'lrn'            => $lrn,
                'last_name'      => $lastName,
                'first_name'     => $firstName,
                'middle_name'    => $middleName,
                'name_extension' => $nameExtension,
                'sex'            => $sex ?: 'male',
                'grade_level'    => $gradeLevel,
                'section'        => $section,
                'track'          => $track,
                'birthdate'      => $dateOfBirth,
                'house_street'   => $houseStreet,
                'barangay'       => $barangay,
                'municipality'   => $municipality,
                'province'       => $province,
                'address'        => $address,
                'contact_number' => $contactNumber,
                'parent_name'    => $parentName,
                'father_name'    => $fatherName,
                'mother_name'    => $motherName,
                'guardian_name'  => $guardianName,
                'relationship'   => $relationship,
                'is_existing'    => $isExisting,
                'is_valid_lrn'   => $isValidLrn,
                'existing_name'  => $isExisting ? ($existingMap[$lrn]['first_name'] . ' ' . $existingMap[$lrn]['last_name']) : null,
            ];
        }

        echo json_encode([
            'success'       => true,
            'mode'          => 'preview',
            'header'        => [
                'school_name'   => $headerInfo['school_name'] ?? '',
                'school_id'     => $headerInfo['school_id'] ?? '',
                'district'      => $headerInfo['district'] ?? '',
                'division'      => $headerInfo['division'] ?? '',
                'region'        => $headerInfo['region'] ?? '',
                'school_year'   => $academicYear,
                'grade_level'   => $detectedGrade > 0 ? $detectedGrade : 11,
                'section'       => $detectedSection,
                'track'         => $detectedTrack,
            ],
            'students'      => $previewStudents,
            'summary'       => $summary,
            'file_name'     => (string)$file['name'],
        ]);
        exit();

    } catch (Throwable $e) {
        error_log('SF1 preview parse error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}

function commitSf1Students(PDO $db, array $students, string $academicYear = '2026-2027'): array {
    $academicYear = trim($academicYear);
    if (!preg_match('/^\d{4}-\d{4}$/', $academicYear)) {
        $academicYear = date('Y') . '-' . (date('Y') + 1);
    }

    $defaultPassword = getDefaultNewUserPassword();
    $hashedPassword = password_hash($defaultPassword, PASSWORD_BCRYPT);
    $rowNum = 0;

    $results = [
        'success'          => true,
        'mode'             => 'commit',
        'created'          => 0,
        'sections_created' => 0,
        'parents_created'  => 0,
        'parents_linked'   => 0,
        'skipped'          => 0,
        'errors'           => 0,
        'rows'             => []
    ];

    foreach ($students as $row) {
        $rowNum++;
        $lrn = preg_replace('/\D/', '', (string)($row['lrn'] ?? ''));
        $lastName = trim((string)($row['last_name'] ?? ''));
        $firstName = trim((string)($row['first_name'] ?? ''));
        $middleName = trim((string)($row['middle_name'] ?? ''));
        $nameExtension = trim((string)($row['name_extension'] ?? ''));
        $sex = strtolower(trim((string)($row['sex'] ?? '')));
        if (!in_array($sex, ['male', 'female'], true)) $sex = '';

        $gradeLevel = (int)($row['grade_level'] ?? 0);
        $section = trim((string)($row['section'] ?? ''));
        $track = sf1GetTrack($row);

        $dateOfBirth = trim((string)($row['birthdate'] ?? ''));
        if ($dateOfBirth !== '') {
            $parsedDate = Sf1Parser::parseBirthdate($dateOfBirth);
            if ($parsedDate !== null) $dateOfBirth = $parsedDate;
        }

        $houseStreet = trim((string)($row['house_street'] ?? ''));
        $barangay = trim((string)($row['barangay'] ?? ''));
        $municipality = trim((string)($row['municipality'] ?? ''));
        $province = trim((string)($row['province'] ?? ''));
        $address = trim((string)($row['address'] ?? ''));
        if ($address === '') {
            $address = implode(', ', array_filter([$houseStreet, $barangay, $municipality, $province]));
        }

        $contactNumber = trim((string)($row['contact_number'] ?? ''));
        $fatherName = trim((string)($row['father_name'] ?? ''));
        $motherName = trim((string)($row['mother_name'] ?? ''));
        $guardianName = trim((string)($row['guardian_name'] ?? ''));
        $guardianRelationship = trim((string)($row['relationship'] ?? ''));
        $parentName = trim((string)($row['parent_name'] ?? ''));
        if ($parentName === '') {
            if ($fatherName !== '') $parentName = $fatherName;
            elseif ($motherName !== '') $parentName = $motherName;
            elseif ($guardianName !== '') $parentName = $guardianName;
        }

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
        if ($section === '') {
            $results['rows'][] = ['row' => $rowNum, 'lrn' => $lrn, 'name' => "$firstName $lastName", 'status' => 'error', 'message' => 'Section is required.'];
            $results['errors']++;
            continue;
        }
        if (!in_array($track, ['academic', 'techpro'], true)) {
            $track = 'academic';
        }

        // Resolve section
        $sectionResult = ensureSf1Section($db, $section, $gradeLevel, $track, $academicYear);
        if (!empty($sectionResult['created'])) {
            $results['sections_created']++;
        }
        $canonicalSection = !empty($sectionResult['name']) ? (string)$sectionResult['name'] : $section;

        // Check if LRN already exists
        $existingUser = null;
        try {
            $lrnCheck = $db->prepare("SELECT id, first_name, last_name FROM users WHERE lrn = ? LIMIT 1");
            $lrnCheck->execute([$lrn]);
            $existingUser = $lrnCheck->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $results['rows'][] = [
                'row'     => $rowNum,
                'lrn'     => $lrn,
                'name'    => "$firstName $lastName",
                'status'  => 'error',
                'message' => 'Database error checking LRN: ' . $e->getMessage()
            ];
            $results['errors']++;
            continue;
        }

        if ($existingUser) {
            $existingUserId = (int)$existingUser['id'];
            try {
                if ($existingUserId > 0) {
                    syncStudentEnrollments($db, $existingUserId, $gradeLevel, $canonicalSection, $track, $academicYear);
                }
                $results['rows'][] = [
                    'row'     => $rowNum,
                    'lrn'     => $lrn,
                    'name'    => "$firstName $lastName",
                    'status'  => 'skipped',
                    'message' => 'LRN already registered in system (' . $existingUser['first_name'] . ' ' . $existingUser['last_name'] . ').'
                ];
                $results['skipped']++;
            } catch (Throwable $e) {
                $results['rows'][] = [
                    'row'     => $rowNum,
                    'lrn'     => $lrn,
                    'name'    => "$firstName $lastName",
                    'status'  => 'error',
                    'message' => 'Enrollment sync failed: ' . $e->getMessage()
                ];
                $results['errors']++;
            }
            continue;
        }

        $refCode = generateReferenceCode('student', $db, $academicYear);
        $emailBase = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstName . '.' . $lastName));
        $email = $emailBase . '.' . $lrn . '@students.balingasag.edu.ph';
        $curriculum = strengthenedShsCurriculum($gradeLevel, $academicYear);
        $program = strengthenedShsProgram($gradeLevel, $track, $academicYear);

        $now = date('Y-m-d H:i:s');
        try {
            $insertStmt = $db->prepare("INSERT INTO users
                (reference_code, email, lrn, password, first_name, middle_name, last_name, name_extension, sex, date_of_birth, religion, contact_number, address, house_street, barangay, municipality, province, father_name, mother_name, guardian_name, guardian_relationship, grade_level, section, track, curriculum, program, role, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'student', 'active', ?, ?)");
            $insertStmt->execute([
                $refCode, $email, $lrn, $hashedPassword,
                $firstName, $middleName ?: null, $lastName,
                $nameExtension ?: null,
                $sex ?: null, $dateOfBirth ?: null, null,
                $contactNumber ?: null, $address ?: null,
                $houseStreet ?: null, $barangay ?: null, $municipality ?: null, $province ?: null,
                $fatherName ?: null, $motherName ?: null,
                $guardianName ?: null, $guardianRelationship ?: null,
                $gradeLevel, $canonicalSection, $track, $curriculum, $program,
                $now, $now
            ]);

            $newUserId = (int)$db->lastInsertId();
            $parentInfoMsg = '';
            if ($newUserId > 0) {
                syncStudentEnrollments($db, $newUserId, $gradeLevel, $canonicalSection, $track, $academicYear);
                $parentLink = autoLinkSf1Parent($db, $newUserId, $row, $academicYear, $hashedPassword);
                if ($parentLink !== null) {
                    if ($parentLink['is_new']) {
                        $results['parents_created']++;
                    }
                    $results['parents_linked']++;
                    $parentInfoMsg = ' | Parent account linked (' . $parentLink['parent_ref_code'] . ')';
                }
            }

            $results['rows'][] = [
                'row'     => $rowNum,
                'lrn'     => $lrn,
                'name'    => "$firstName $lastName",
                'status'  => 'created',
                'message' => "Created ($refCode)$parentInfoMsg"
            ];
            $results['created']++;
        } catch (Throwable $e) {
            $errMsg = str_contains($e->getMessage(), 'Duplicate') ? 'Duplicate entry for email/reference code.' : 'Database error.';
            $results['rows'][] = ['row' => $rowNum, 'lrn' => $lrn, 'name' => "$firstName $lastName", 'status' => 'error', 'message' => $errMsg];
            $results['errors']++;
        }
    }

    if ($results['created'] === 0 && $results['errors'] === 0 && $results['skipped'] === 0) {
        $results['message'] = 'No learners processed.';
        $results['success'] = false;
    } elseif ($results['errors'] > 0 && $results['created'] === 0) {
        $results['success'] = false;
        $results['message'] = 'Import failed. Please review errors.';
    } else {
        $results['message'] = $results['created'] . ' learner(s) successfully imported.';
        if ($results['sections_created'] > 0) {
            $results['message'] .= ' ' . $results['sections_created'] . ' new section(s) created.';
        }
        if ($results['parents_created'] > 0 || $results['parents_linked'] > 0) {
            $results['message'] .= ' ' . $results['parents_created'] . ' parent account(s) generated (' . $results['parents_linked'] . ' linked).';
        }
    }

    recordAdminAuditLog($db, 'sf1.import_commit', 'student_import', null, [
        'academic_year'    => $academicYear,
        'created'          => (int)$results['created'],
        'sections_created' => (int)$results['sections_created'],
        'parents_created'  => (int)$results['parents_created'],
        'parents_linked'   => (int)$results['parents_linked'],
        'skipped'          => (int)$results['skipped'],
        'errors'           => (int)$results['errors'],
        'success'          => (bool)$results['success'],
    ]);

    return $results;
}

// -------------------------------------------------------------
// 2. ACTION: COMMIT (Insert Reviewed/Edited Students into DB)
// -------------------------------------------------------------
if ($action === 'commit') {
    $studentsRaw = $_POST['students'] ?? [];
    if (is_string($studentsRaw)) {
        $students = json_decode($studentsRaw, true) ?: [];
    } else {
        $students = is_array($studentsRaw) ? $studentsRaw : [];
    }

    if (empty($students)) {
        echo json_encode(['success' => false, 'message' => 'No student data provided to commit.']);
        exit();
    }

    $academicYear = trim((string)($_POST['academic_year'] ?? date('Y') . '-' . (date('Y') + 1)));
    $results = commitSf1Students($db, $students, $academicYear);
    echo json_encode($results);
    exit();
}

if ($action !== '' && $action !== 'noop') {
    echo json_encode(['success' => false, 'message' => 'Invalid action requested.']);
    exit();
}


