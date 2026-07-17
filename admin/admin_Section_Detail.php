<?php
require_once __DIR__ . '/../functions/bootstrap.php';
// Admin - Section Detail Page
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}


$database = new Database();
$db = $database->getConnection();

$gradeLevel = (int)($_GET['grade_level'] ?? 0);
$section = trim((string)($_GET['section'] ?? ''));

if ($gradeLevel <= 0 || $section === '' || !$db) {
    header("Location: admin_Sections.php");
    exit();
}

// Adviser(s)
$adviserStmt = $db->prepare("SELECT GROUP_CONCAT(DISTINCT CONCAT(first_name, ' ', last_name) SEPARATOR ', ')
                              FROM users
                              WHERE role = 'teacher'
                                AND status IN ('active', 'pending')
                                AND grade_level = ?
                                AND LOWER(TRIM(COALESCE(section, ''))) = LOWER(TRIM(COALESCE(?, '')))");
$adviserStmt->execute([$gradeLevel, $section]);
$adviserName = $adviserStmt->fetchColumn();

// Students in section
$studentsStmt = $db->prepare("SELECT id, reference_code, first_name, last_name, sex, status
                              FROM users
                              WHERE role = 'student'
                                AND status IN ('active', 'pending')
                                AND grade_level = ?
                                AND LOWER(TRIM(COALESCE(section, ''))) = LOWER(TRIM(COALESCE(?, '')))
                              ORDER BY last_name, first_name");
$studentsStmt->execute([$gradeLevel, $section]);
$students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Classes for section
$classesStmt = $db->prepare("SELECT c.id,
                                    c.class_name,
                                    c.schedule,
                                    c.room,
                                    (SELECT COUNT(*) FROM enrollments e WHERE e.class_id = c.id AND e.status = 'enrolled') AS student_count,
                                    (SELECT GROUP_CONCAT(DISTINCT CONCAT(u.first_name, ' ', u.last_name) SEPARATOR ', ')
                                     FROM class_subjects cs
                                     JOIN users u ON cs.teacher_id = u.id
                                     WHERE cs.class_id = c.id AND u.status IN ('active', 'pending')) AS teacher_name
                             FROM classes c
                             WHERE c.status = 'active'
                               AND c.grade_level = ?
                               AND LOWER(TRIM(COALESCE(c.section, ''))) = LOWER(TRIM(COALESCE(?, '')))
                             ORDER BY c.class_name ASC");
$classesStmt->execute([$gradeLevel, $section]);
$classes = $classesStmt->fetchAll(PDO::FETCH_ASSOC);


function parseScheduleEntries($schedule) {
    $schedule = trim((string)$schedule);
    if ($schedule === '') {
        return [];
    }
    $segments = ScheduleParser::parseSegments($schedule);
    $entries = [];
    foreach ($segments as $seg) {
        $entries[] = [
            'day' => $seg['day'],
            'time' => $seg['start_label'] . ' - ' . $seg['end_label'],
        ];
    }
    return $entries;
}

$calendarDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
$scheduleBuckets = [];
foreach ($calendarDays as $day) {
    $scheduleBuckets[$day] = [];
}
$scheduleOther = [];
foreach ($classes as $class) {
    $entry = [
        'subject' => (string)($class['class_name'] ?? ''),
        'teacher' => (string)($class['teacher_name'] ?? ''),
        'room' => (string)($class['room'] ?? ''),
        'time' => ''
    ];
    $entries = parseScheduleEntries($class['schedule'] ?? '');
    $matchedDay = false;
    foreach ($entries as $schedEntry) {
        $dayKey = $schedEntry['day'] ?? '';
        if (isset($scheduleBuckets[$dayKey])) {
            $entry['time'] = $schedEntry['time'] ?? '';
            $scheduleBuckets[$dayKey][] = $entry;
            $matchedDay = true;
        }
    }
    if (!$matchedDay) {
        if (!empty($entries)) {
            foreach ($entries as $schedEntry) {
                $entry['time'] = $schedEntry['time'] ?? '';
                $scheduleOther[] = $entry;
            }
            continue;
        }
        $scheduleOther[] = $entry;
    }
}

$current_role = 'admin';
$current_page = 'sections';
$page_title = 'Section Detail - Grade ' . $gradeLevel . ' - ' . $section;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Balingasag Senior High School</title>
    <link href="<?php echo appAssetPath('src/vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo appAssetPath('src/vendor/bootstrap-icons/bootstrap-icons.css'); ?>">
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="../assets/css/role.css">
<?php echo pwaHeadHtml(); ?>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include '../includes/header.php'; ?>
        <div class="page-content">
            <a href="admin_Sections.php" class="btn btn-link text-decoration-none mb-3">
                <i class="bi bi-arrow-left me-2"></i>Back to Sections
            </a>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h4 class="mb-1">Grade <?php echo $gradeLevel; ?> - <?php echo htmlspecialchars($section); ?></h4>
                    <p class="text-muted mb-0">Section overview with adviser and enrolled students.</p>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="content-card-header">
                    <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Section Information</h5>
                </div>
                <div class="content-card-body">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="text-muted small">Adviser</label>
                            <p class="mb-0 fw-medium"><?php echo htmlspecialchars($adviserName ?: 'Not Assigned'); ?></p>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="text-muted small">Total Students</label>
                            <p class="mb-0 fw-medium"><?php echo count($students); ?> Students</p>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="text-muted small">Total Subject Classes</label>
                            <p class="mb-0 fw-medium"><?php echo count($classes); ?> Classes</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-12">
                    <div class="content-card">
                        <div class="content-card-header">
                            <h5 class="mb-0"><i class="bi bi-calendar-week me-2"></i>Subject Classes (<?php echo count($classes); ?>)</h5>
                        </div>
                        <div class="content-card-body">
                            <?php if (count($classes) > 0): ?>
                            <div class="table-responsive">
                                <table class="table custom-table mb-0">
                                    <thead>
                                        <tr>
                                            <?php foreach ($calendarDays as $day): ?>
                                            <th><?php echo htmlspecialchars($day); ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <?php foreach ($calendarDays as $day): ?>
                                            <td>
                                                <?php if (!empty($scheduleBuckets[$day])): ?>
                                                    <?php foreach ($scheduleBuckets[$day] as $entry): ?>
                                                        <div class="mb-3">
                                                            <div class="fw-semibold"><?php echo htmlspecialchars($entry['subject']); ?></div>
                                                            <div class="text-muted small"><?php echo htmlspecialchars($entry['time']); ?></div>
                                                            <div class="text-muted small"><?php echo htmlspecialchars($entry['teacher'] ?: 'Not Assigned'); ?></div>
                                                            <div class="text-muted small"><?php echo htmlspecialchars($entry['room'] ?: 'Room TBA'); ?></div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <div class="text-muted small">No class</div>
                                                <?php endif; ?>
                                            </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <?php if (count($scheduleOther) > 0): ?>
                            <div class="mt-3">
                                <div class="text-muted small mb-2">Other / TBA Schedules</div>
                                <div class="row g-2">
                                    <?php foreach ($scheduleOther as $entry): ?>
                                    <div class="col-md-6 col-lg-4">
                                        <div class="border rounded p-2 h-100">
                                            <div class="fw-semibold"><?php echo htmlspecialchars($entry['subject']); ?></div>
                                            <div class="text-muted small"><?php echo htmlspecialchars($entry['time']); ?></div>
                                            <div class="text-muted small"><?php echo htmlspecialchars($entry['teacher'] ?: 'Not Assigned'); ?></div>
                                            <div class="text-muted small"><?php echo htmlspecialchars($entry['room'] ?: 'Room TBA'); ?></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php else: ?>
                            <div class="text-center py-4">
                                <p class="text-muted mb-0">No subject classes created for this section yet.</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="content-card">
                        <div class="content-card-header">
                            <h5 class="mb-0"><i class="bi bi-people me-2"></i>Enrolled Students (<?php echo count($students); ?>)</h5>
                        </div>
                        <div class="content-card-body p-0">
                            <?php if (count($students) > 0): ?>
                            <div class="table-responsive">
                                <table class="table custom-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Reference Code</th>
                                            <th>Student Name</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $student): ?>
                                        <?php
                                            $status = strtolower(trim((string)$student['status']));
                                            $statusClass = 'bg-secondary';
                                            if ($status === 'active') {
                                                $statusClass = 'bg-success';
                                            } elseif ($status === 'pending') {
                                                $statusClass = 'bg-warning text-dark';
                                            } elseif ($status === 'inactive') {
                                                $statusClass = 'bg-secondary';
                                            }
                                        ?>
                                        <tr>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($student['reference_code']); ?></span></td>
                                            <td><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></td>
                                            <td><span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-4">
                                <p class="text-muted mb-0">No students assigned to this section yet.</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php include '../includes/footer.php'; ?>



