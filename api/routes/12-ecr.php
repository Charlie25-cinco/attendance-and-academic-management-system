<?php
$__appRoot = __DIR__;
while ($__appRoot !== dirname($__appRoot) && !is_file($__appRoot . '/functions/bootstrap.php')) {
    $__appRoot = dirname($__appRoot);
}
require_once $__appRoot . '/functions/bootstrap.php';
unset($__appRoot);

// =============================================================================
// DEPED E-CLASS RECORD (ECR) IMPORT ENDPOINTS
// =============================================================================

if (!function_exists('apiEcrSchoolMetaValue')) {
    function apiEcrSchoolMetaValue(string $localKey, string $envKey, string $fallback = ''): string {
        $envValue = trim(apiEnvValue($envKey));
        if ($envValue !== '') {
            return $envValue;
        }

        $localPath = __DIR__ . '/../../config/App.local.php';
        if (is_file($localPath)) {
            $config = require $localPath;
            if (is_array($config)) {
                $localValue = trim((string)($config[$localKey] ?? ''));
                if ($localValue !== '') {
                    return $localValue;
                }
            }
        }

        if ($fallback !== '') {
            return $fallback;
        }

        return '';
    }
}

$DB = null;
$IS_ADMIN = false;

function ecrEnsureUploadDir(string $subDir = ''): string {
    $uploadsRoot = __DIR__ . '/../../deped';
    if (!is_dir($uploadsRoot)) {
        @mkdir($uploadsRoot, 0755, true);
    }
    if ($subDir !== '') {
        $target = $uploadsRoot . '/' . ltrim($subDir, '/');
        if (!is_dir($target)) {
            @mkdir($target, 0755, true);
        }
        return $target;
    }
    return $uploadsRoot;
}

function ecrValidAcademicYear(string $year): bool {
    return preg_match('/^\d{4}-\d{4}$/', $year) === 1;
}

function ecrValidSection(string $section): bool {
    $section = trim($section);
    return $section !== '';
}

function ecrSchoolId(): string {
    return apiEcrSchoolMetaValue('school_id', 'ECR_SCHOOL_ID', '341227');
}

function ecrSchoolName(): string {
    return apiEcrSchoolMetaValue('school_name', 'ECR_SCHOOL_NAME', 'Balingasag Senior High School');
}

function ecrDivision(): string {
    return apiEcrSchoolMetaValue('division', 'ECR_DIVISION', 'Misamis Oriental');
}

function ecrRegion(): string {
    return apiEcrSchoolMetaValue('region', 'ECR_REGION', 'Region X');
}

function ecrDistrict(): string {
    return apiEcrSchoolMetaValue('district', 'ECR_DISTRICT', 'Balingasag');
}

function ecrRequireTeacherOrAdmin(PDO $db, int $classId = 0): array {
    $user = apiRequireUser();
    $role = (string)($user['role'] ?? '');
    if (!in_array($role, ['teacher', 'admin'], true)) {
        apiJson(['ok' => false, 'message' => 'Forbidden'], 403);
    }
    if ($classId > 0 && $role === 'teacher' && !apiTeacherOwnsClass($db, (int)$user['id'], $classId)) {
        apiJson(['ok' => false, 'message' => 'You are not assigned to this class'], 403);
    }
    return $user;
}

function ecrValidTermForYear(string $academicYear, string $term): string {
    $gradingSystem = SshsGradeCalculator::gradingSystem($academicYear);
    $valid = SshsGradeCalculator::validTerms($gradingSystem);
    return in_array($term, $valid, true) ? $term : $valid[0];
}

function ecrSemesterForTerm(string $academicYear, string $term): ?string {
    if (SshsGradeCalculator::gradingSystem($academicYear) === '3_term') {
        return null;
    }
    return in_array($term, ['Q3', 'Q4'], true) ? 'S2' : 'S1';
}

function ecrPeriodBounds(PDO $db, string $academicYear, string $term, ?string $semester): array {
    if (!preg_match('/^(\d{4})-(\d{4})$/', $academicYear, $m)) {
        return ['', ''];
    }
    $startYear = (int)$m[1];
    $endYear = (int)$m[2];
    $gradingSystem = SshsGradeCalculator::gradingSystem($academicYear);

    if ($gradingSystem === '4_quarter') {
        if ($semester === 'S2') {
            if ($term === 'Q1') return [$startYear . '-12-01', $endYear . '-02-28'];
            if ($term === 'Q2') return [$endYear . '-03-01', $endYear . '-05-31'];
            return [$startYear . '-12-01', $endYear . '-05-31'];
        }
        if ($term === 'Q1') return [$startYear . '-06-01', $startYear . '-08-31'];
        if ($term === 'Q2') return [$startYear . '-09-01', $startYear . '-11-30'];
        return [$startYear . '-06-01', $startYear . '-11-30'];
    }

    if ($term === 'Term1') return [$startYear . '-07-01', $startYear . '-09-30'];
    if ($term === 'Term2') return [$startYear . '-10-01', $startYear . '-12-31'];
    if ($term === 'Term3') return [$endYear . '-01-01', $endYear . '-03-31'];
    return [$startYear . '-07-01', $endYear . '-03-31'];
}

function ecrClassContext(PDO $db, int $classId, int $teacherId, string $academicYear): array {
    $stmt = $db->prepare(
        "SELECT c.*, u.first_name AS teacher_first_name, u.last_name AS teacher_last_name
         FROM classes c
         LEFT JOIN users u ON u.id = COALESCE(c.teacher_id, ?)
         WHERE c.id = ? AND c.status = 'active'
         LIMIT 1"
    );
    $stmt->execute([$teacherId, $classId]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$class) {
        apiJson(['ok' => false, 'message' => 'Class not found'], 404);
    }

    $studentsStmt = $db->prepare(
        "SELECT u.id, u.lrn, u.reference_code, u.first_name, u.middle_name, u.last_name, u.sex
         FROM users u
         INNER JOIN enrollments e ON e.student_id = u.id
         WHERE e.class_id = ?
         AND e.academic_year = ?
         AND COALESCE(e.status, 'enrolled') = 'enrolled'
         AND u.role = 'student'
         AND u.status = 'active'
         ORDER BY u.sex DESC, u.last_name, u.first_name"
    );
    $studentsStmt->execute([$classId, $academicYear]);
    $students = [];
    foreach ($studentsStmt->fetchAll(PDO::FETCH_ASSOC) as $student) {
        $lrn = trim((string)($student['lrn'] ?? ''));
        if ($lrn === '') {
            $lrn = trim((string)($student['reference_code'] ?? ''));
        }
        $name = trim((string)($student['last_name'] ?? '') . ', ' . (string)($student['first_name'] ?? '') . ' ' . (string)($student['middle_name'] ?? ''));
        $student['lrn'] = $lrn;
        $student['name'] = $name;
        $student['export_key'] = $lrn !== '' ? $lrn : ('student:' . (int)$student['id']);
        $students[] = $student;
    }

    return [$class, $students];
}

function ecrGradeItems(PDO $db, int $classId, int $teacherId, array $students, string $academicYear, string $term): array {
    if (!apiTableExists($db, 'grade_items') || !apiTableExists($db, 'grade_item_scores')) {
        return ['items' => ['ww' => [], 'pt' => [], 'qa' => []], 'scores' => [], 'export_items' => [], 'truncated' => ['ww' => false, 'pt' => false, 'qa' => false]];
    }

    $semester = ecrSemesterForTerm($academicYear, $term);
    $bounds = ecrPeriodBounds($db, $academicYear, $term, $semester);
    [$dateFrom, $dateTo] = $bounds;

    $where = "gi.class_id = ?";
    $params = [$classId];
    if ($teacherId > 0) {
        $where .= " AND gi.teacher_id = ?";
        $params[] = $teacherId;
    }
    if ($dateFrom !== '' && $dateTo !== '') {
        $where .= " AND COALESCE(DATE(gi.finished_at), gi.activity_date, DATE(gi.created_at)) BETWEEN ? AND ?";
        $params[] = $dateFrom;
        $params[] = $dateTo;
    }

    $itemStmt = $db->prepare(
        "SELECT gi.id, gi.title, gi.component, gi.total_score, gi.status, gi.activity_date, gi.finished_at
         FROM grade_items gi
         WHERE $where
         ORDER BY FIELD(UPPER(gi.component), 'WW', 'PT', 'ASSESSMENT'), gi.activity_date, gi.id"
    );
    $itemStmt->execute($params);
    $allItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    $limits = ['ww' => 10, 'pt' => 10, 'qa' => 3];
    $items = ['ww' => [], 'pt' => [], 'qa' => []];
    $itemLookup = [];
    $truncated = ['ww' => false, 'pt' => false, 'qa' => false];
    foreach ($allItems as $item) {
        $component = strtolower(trim((string)$item['component']));
        $key = $component === 'assessment' ? 'qa' : $component;
        if (!isset($items[$key])) {
            continue;
        }
        if (count($items[$key]) >= $limits[$key]) {
            $truncated[$key] = true;
            continue;
        }
        $item['component_key'] = $key;
        $items[$key][] = $item;
        $itemLookup[(int)$item['id']] = $item;
    }

    $scores = [];
    $exportItems = [];
    $studentById = [];
    foreach ($students as $student) {
        $studentById[(int)$student['id']] = $student;
    }

    if (!empty($itemLookup)) {
        $ids = array_keys($itemLookup);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $scoreStmt = $db->prepare("SELECT grade_item_id, student_id, score FROM grade_item_scores WHERE grade_item_id IN ($placeholders)");
        $scoreStmt->execute($ids);
        foreach ($scoreStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $itemId = (int)$row['grade_item_id'];
            $studentId = (int)$row['student_id'];
            if (!isset($itemLookup[$itemId], $studentById[$studentId])) {
                continue;
            }
            $score = $row['score'] !== null ? (float)$row['score'] : null;
            $scores[(string)$studentId][(string)$itemId] = $score;
            $item = $itemLookup[$itemId];
            $student = $studentById[$studentId];
            $exportItems[] = [
                'export_key' => $student['export_key'],
                'lrn' => $student['lrn'],
                'item_id' => $itemId,
                'component' => $item['component_key'] === 'qa' ? 'assessment' : $item['component_key'],
                'total_score' => $item['total_score'],
                'scores' => $score === null ? [] : [$score],
            ];
        }
    }

    return ['items' => $items, 'scores' => $scores, 'export_items' => $exportItems, 'truncated' => $truncated];
}

function ecrConfiguredExporter(PDO $db, int $classId, int $teacherId, string $academicYear, string $term): array {
    [$class, $students] = ecrClassContext($db, $classId, $teacherId, $academicYear);
    $gradeData = ecrGradeItems($db, $classId, $teacherId, $students, $academicYear, $term);
    $weights = [
        'ww' => (float)($class['ww_weight'] ?? 25),
        'pt' => (float)($class['pt_weight'] ?? 50),
        'assessment' => (float)($class['assessment_weight'] ?? 25),
    ];
    $teacherName = trim((string)($class['teacher_first_name'] ?? '') . ' ' . (string)($class['teacher_last_name'] ?? ''));

    $exporter = new EcrExporter();
    $exporter->setHeader([
        'school' => ecrSchoolName(),
        'school_id' => ecrSchoolId(),
        'division' => ecrDivision(),
        'region' => ecrRegion(),
        'grade_level' => (int)($class['grade_level'] ?? 11),
        'section' => (string)($class['section'] ?? ''),
        'grade_section' => 'Grade ' . (int)($class['grade_level'] ?? 11) . ' - ' . (string)($class['section'] ?? ''),
        'teacher' => $teacherName,
        'subject' => (string)($class['class_name'] ?? ''),
        'subject_type' => SshsGradeCalculator::subjectCategoryLabel((string)($class['subject_category'] ?? 'core')),
    ]);
    $exporter->setStudents($students);
    $exporter->setGradeItems($gradeData['export_items']);
    $exporter->setWeights($weights);
    $exporter->setAcademicYear($academicYear);
    $exporter->setTerm($term);
    $semester = ecrSemesterForTerm($academicYear, $term);
    if ($semester !== null) {
        $exporter->setSemester($semester);
        $exporter->setQuarter($term);
    }

    return [$exporter, $class, $students, $gradeData, $weights];
}

if ($route === 'ecr-template' && $method === 'GET') {
    $db = apiDb();
    $user = apiRequireUser();
    if (!$db || !in_array(($user['role'] ?? ''), ['admin', 'teacher'], true)) {
        apiJson(['ok' => false, 'message' => 'Forbidden'], 403);
    }

    $exporter = new EcrExporter();
    $templatePath = $exporter->getTemplatePath();
    $info = $exporter->getTemplateInfo($templatePath);
    $meta = $info['exists'] ? ['size' => filesize($templatePath), 'modified' => date('Y-m-d H:i:s', filemtime($templatePath))] : null;

    apiJson(['ok' => true, 'exists' => $info['exists'], 'file' => basename($templatePath), 'meta' => $meta, 'template' => $info]);
}

if ($route === 'ecr-export-preview' && $method === 'GET') {
    $db = apiDb();
    $classId = (int)($_GET['class_id'] ?? 0);
    $academicYear = trim((string)($_GET['academic_year'] ?? currentAcademicYear()));
    $term = ecrValidTermForYear($academicYear, trim((string)($_GET['term'] ?? 'Term1')));
    if ($classId <= 0 || !ecrValidAcademicYear($academicYear)) {
        apiJson(['ok' => false, 'message' => 'Class and academic year are required'], 422);
    }

    $user = ecrRequireTeacherOrAdmin($db, $classId);
    [, , $students, $gradeData, $weights] = ecrConfiguredExporter($db, $classId, (int)$user['id'], $academicYear, $term);

    apiJson([
        'ok' => true,
        'students' => $students,
        'items' => $gradeData['items'],
        'scores' => $gradeData['scores'],
        'weights' => $weights,
        'term' => $term,
        'academic_year' => $academicYear,
        'truncated' => $gradeData['truncated'],
    ]);
}

if ($route === 'ecr-export' && $method === 'GET') {
    $db = apiDb();
    $classId = (int)($_GET['class_id'] ?? 0);
    $academicYear = trim((string)($_GET['academic_year'] ?? currentAcademicYear()));
    $term = ecrValidTermForYear($academicYear, trim((string)($_GET['term'] ?? 'Term1')));
    $format = strtolower(trim((string)($_GET['format'] ?? 'xlsx')));
    if ($classId <= 0 || !ecrValidAcademicYear($academicYear)) {
        apiJson(['ok' => false, 'message' => 'Class and academic year are required'], 422);
    }
    if (!in_array($format, ['csv', 'xlsx'], true)) {
        apiJson(['ok' => false, 'message' => 'ECR export supports CSV and XLSX only'], 422);
    }

    $user = ecrRequireTeacherOrAdmin($db, $classId);
    [$exporter, $class] = ecrConfiguredExporter($db, $classId, (int)$user['id'], $academicYear, $term);
    $safeClass = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($class['class_name'] ?? 'class'));
    $safeSection = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($class['section'] ?? ''));
    $baseName = "ECR_G" . (int)($class['grade_level'] ?? 11) . "_{$safeSection}_{$safeClass}_{$term}_{$academicYear}";

    $tmp = tempnam(sys_get_temp_dir(), 'ecr_');
    if ($tmp === false) {
        apiJson(['ok' => false, 'message' => 'Unable to prepare ECR download'], 500);
    }
    $outputPath = $tmp . '.' . $format;
    @rename($tmp, $outputPath);
    $ok = $format === 'csv' ? $exporter->exportToCsv($outputPath) : $exporter->exportToXlsx($outputPath);
    if (!$ok || !is_file($outputPath)) {
        @unlink($outputPath);
        apiJson(['ok' => false, 'message' => 'Unable to generate ECR file. Check that a compatible .xlsx template is available.'], 500);
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header($format === 'csv' ? 'Content-Type: text/csv; charset=utf-8' : 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $baseName . '.' . $format . '"');
    header('Content-Length: ' . filesize($outputPath));
    readfile($outputPath);
    @unlink($outputPath);
    exit();
}

if ($route === 'ecr-upload' && $method === 'POST') {
    $db = apiDb();
    ecrRequireTeacherOrAdmin($db);
    if (!isset($_FILES['ecr_file']) || $_FILES['ecr_file']['error'] !== UPLOAD_ERR_OK) {
        apiJson(['ok' => false, 'message' => 'File upload failed'], 400);
    }

    $nameLower = strtolower((string)($_FILES['ecr_file']['name'] ?? ''));
    if (!str_ends_with($nameLower, '.xlsx')) {
        apiJson(['ok' => false, 'message' => 'Only .xlsx files are allowed'], 422);
    }

    $parser = new EcrParser();
    if (!$parser->parse($_FILES['ecr_file']['tmp_name'])) {
        apiJson(['ok' => false, 'message' => 'Unable to read ECR file', 'errors' => $parser->getErrors()], 422);
    }

    $uploadDir = ecrEnsureUploadDir('imports');
    $fileKey = 'ecr_import_' . bin2hex(random_bytes(8)) . '.xlsx';
    $dest = $uploadDir . '/' . $fileKey;
    if (!move_uploaded_file($_FILES['ecr_file']['tmp_name'], $dest)) {
        apiJson(['ok' => false, 'message' => 'Failed to save uploaded ECR file'], 500);
    }

    apiJson(['ok' => true, 'file_key' => $fileKey, 'preview' => $parser->getPreview()]);
}

if ($route === 'ecr-import' && $method === 'POST') {
    $db = apiDb();
    $body = apiRequestBody();
    $classId = (int)($body['class_id'] ?? 0);
    $academicYear = trim((string)($body['academic_year'] ?? currentAcademicYear()));
    $term = ecrValidTermForYear($academicYear, trim((string)($body['term'] ?? 'Term1')));
    $fileKey = basename((string)($body['file_key'] ?? ''));
    if ($classId <= 0 || !ecrValidAcademicYear($academicYear) || $fileKey === '' || !str_ends_with(strtolower($fileKey), '.xlsx')) {
        apiJson(['ok' => false, 'message' => 'Class, academic year, and .xlsx ECR file are required'], 422);
    }

    $user = ecrRequireTeacherOrAdmin($db, $classId);
    $filePath = ecrEnsureUploadDir('imports') . '/' . $fileKey;
    if (!is_file($filePath)) {
        apiJson(['ok' => false, 'message' => 'Uploaded ECR file was not found'], 404);
    }

    $parser = new EcrParser();
    if (!$parser->parse($filePath)) {
        apiJson(['ok' => false, 'message' => 'Unable to read ECR file', 'errors' => $parser->getErrors()], 422);
    }

    $importer = new GradeImporter($db, (int)$user['id']);
    $result = $importer->import($parser->getFullData(), [
        'class_id' => $classId,
        'academic_year' => $academicYear,
        'term' => $term,
        'quarter' => $term,
        'semester' => ecrSemesterForTerm($academicYear, $term),
        'mode' => 'merge',
    ]);

    apiJson(['ok' => (bool)($result['success'] ?? false), 'message' => ($result['success'] ?? false) ? 'ECR imported successfully' : 'ECR import completed with errors', 'result' => $result]);
}
