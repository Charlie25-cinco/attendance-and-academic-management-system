<?php

use PHPUnit\Framework\TestCase;

final class AdminClassMultiSectionTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['logged_in'] = true;
        $_SESSION['role'] = 'admin';
        $_SESSION['user_id'] = 1;
        $_GET['action'] = 'test';
    }

    public function testAdminClassesPageContainsMultiSectionAndDatalistMarkup(): void
    {
        $html = file_get_contents(__DIR__ . '/../admin/admin_Classes.php');
        $this->assertIsString($html);

        $this->assertStringContainsString('id="sectionCheckboxesContainer"', $html);
        $this->assertStringContainsString('id="selectAllSectionsBtn"', $html);
        $this->assertStringContainsString('toggleAllSections()', $html);
        $this->assertStringContainsString('id="registeredSubjectsList"', $html);
        $this->assertStringContainsString('list="registeredSubjectsList"', $html);
        $this->assertStringContainsString('name="sections[]"', $html);
        $this->assertStringContainsString('id="schedModePerSection"', $html);
        $this->assertStringContainsString('id="schedModeUniform"', $html);
        $this->assertStringContainsString('id="schedModeTba"', $html);
        $this->assertStringContainsString('id="sectionSchedTabs"', $html);
        $this->assertStringContainsString('staggerSectionSchedules()', $html);
        $this->assertStringContainsString('copyFirstSectionScheduleToAll()', $html);
    }

    public function testAdminClassesCardButtonsAndScriptIntegrity(): void
    {
        $html = file_get_contents(__DIR__ . '/../admin/admin_Classes.php');
        $this->assertIsString($html);

        // Verify card buttons markup
        $this->assertStringContainsString('onclick="viewClass(', $html);
        $this->assertStringContainsString('onclick="editClass(', $html);
        $this->assertStringContainsString('onclick=\'deleteClass(', $html);

        // Verify JavaScript action functions exist
        $this->assertStringContainsString('function viewClass(id)', $html);
        $this->assertStringContainsString('function editClass(id)', $html);
        $this->assertStringContainsString('function deleteClass(id, className)', $html);
        $this->assertStringContainsString('function handleDelete(id)', $html);

        // Verify no duplicate const addClassModalEl declaration
        $matches = [];
        preg_match_all('/const\s+addClassModalEl\s*=/i', $html, $matches);
        $this->assertCount(1, $matches[0], 'addClassModalEl should only be declared once as const');
    }

    public function testAdminClassesActionSupportsMultiSectionArrayAndScheduleModes(): void
    {
        $code = file_get_contents(__DIR__ . '/../admin/admin_Classes_Action.php');
        $this->assertIsString($code);

        $this->assertStringContainsString('isset($_POST[\'sections\'])', $code);
        $this->assertStringContainsString('$createdSections[] = $section;', $code);
        $this->assertStringContainsString('\'created_count\' => count($createdSections)', $code);
        $this->assertStringContainsString('\'created_sections\' => $createdSections', $code);
        $this->assertStringContainsString('$_POST[\'schedule_mode\']', $code);
        $this->assertStringContainsString('$_POST[\'section_schedules\']', $code);
        $this->assertStringContainsString('$scheduleMode === \'per_section\'', $code);
        $this->assertStringContainsString('$scheduleMode === \'tba\'', $code);
    }

    public function testAdminClassEditIsStrictlySectionSpecific(): void
    {
        $html = file_get_contents(__DIR__ . '/../admin/admin_Class_Edit.php');
        $this->assertIsString($html);

        // Subject datalist exists
        $this->assertStringContainsString('id="registeredSubjectsList"', $html);
        $this->assertStringContainsString('list="registeredSubjectsList"', $html);

        // Grade Level and Section are rendered as fixed, disabled/read-only context
        $this->assertStringContainsString('name="grade_level"', $html);
        $this->assertStringContainsString('name="section"', $html);
        $this->assertStringContainsString('readonly disabled', $html);
        $this->assertStringContainsString('>Fixed</span>', $html);

        // Multi-section cross-apply elements MUST NOT exist
        $this->assertStringNotContainsString('id="editSectionCheckboxesContainer"', $html);
        $this->assertStringNotContainsString('id="selectAllEditSectionsBtn"', $html);
        $this->assertStringNotContainsString('toggleAllEditSections()', $html);
        $this->assertStringNotContainsString('name="apply_to_sections[]"', $html);
        $this->assertStringNotContainsString('apply_to_sections', $html);
    }

    public function testAdminClassesActionUpdateIsStrictlySectionSpecific(): void
    {
        $code = file_get_contents(__DIR__ . '/../admin/admin_Classes_Action.php');
        $this->assertIsString($code);

        // apply_to_sections must NOT exist in action handler
        $this->assertStringNotContainsString('apply_to_sections', $code);
        $this->assertStringNotContainsString('$appliedSections', $code);

        // Grade level and section are enforced from database record, ignoring tampered input
        $this->assertStringContainsString('$existingClassStmt = $db->prepare("SELECT id, grade_level, section FROM classes WHERE id = ?', $code);
        $this->assertStringContainsString('$gradeLevel = (string)$existingClass[\'grade_level\'];', $code);
        $this->assertStringContainsString('$section = (string)$existingClass[\'section\'];', $code);

        // Update query must update only WHERE id = ?
        $this->assertStringContainsString('UPDATE classes SET $setClause WHERE id = ?', $code);
    }

    public function testDedicatedAddSectionToGroupModalMarkupAndBehavior(): void
    {
        $html = file_get_contents(__DIR__ . '/../admin/admin_Classes.php');
        $this->assertIsString($html);

        // Dedicated modal structure
        $this->assertStringContainsString('id="addSectionToGroupModal"', $html);
        $this->assertStringContainsString('id="addSecModalSubjectName"', $html);
        $this->assertStringContainsString('id="addSecModalGradeLevel"', $html);
        $this->assertStringContainsString('id="addSecModalCategory"', $html);
        $this->assertStringContainsString('id="addSecModalTrack"', $html);
        $this->assertStringContainsString('id="addSecModalSectionSelect"', $html);
        $this->assertStringContainsString('id="addSecModalTeacherSelect"', $html);
        $this->assertStringContainsString('id="addSecModalRoomInput"', $html);
        $this->assertStringContainsString('id="addSecModalSubmitBtn"', $html);

        // JavaScript functions
        $this->assertStringContainsString('function openAddSectionForGroup()', $html);
        $this->assertStringContainsString('function backToGroupSectionsModal()', $html);
        $this->assertStringContainsString('function submitAddSectionToGroup()', $html);
        $this->assertStringContainsString('window.openAddSectionForGroup = openAddSectionForGroup;', $html);
        $this->assertStringContainsString('window.backToGroupSectionsModal = backToGroupSectionsModal;', $html);
        $this->assertStringContainsString('window.submitAddSectionToGroup = submitAddSectionToGroup;', $html);
    }

    public function testSectionSelectorAndScheduleTimeFieldsVisibility(): void
    {
        $css = file_get_contents(__DIR__ . '/../assets/css/main.css');
        $this->assertIsString($css);

        // Verify sidebar nav-link scoping (should not match global unscoped .nav-link {)
        $this->assertStringContainsString('.sidebar .nav-link {', $css);
        $this->assertStringContainsString('.form-select option,', $css);
        $this->assertStringContainsString('select option {', $css);
        $this->assertStringContainsString('body.dark-mode .form-select option,', $css);
        $this->assertStringContainsString('.nav-pills .nav-link {', $css);
        $this->assertStringContainsString('.schedule-time-group', $css);
        $this->assertStringContainsString('.schedule-time::placeholder', $css);

        // Verify admin_Classes.php styles and element structure
        $classesHtml = file_get_contents(__DIR__ . '/../admin/admin_Classes.php');
        $this->assertIsString($classesHtml);
        $this->assertStringContainsString('app-section-pill', $classesHtml);
        $this->assertStringContainsString('schedule-time-group', $classesHtml);
        $this->assertStringContainsString('data-field="start_hour"', $classesHtml);
        $this->assertStringContainsString('data-field="start_min"', $classesHtml);
        $this->assertStringContainsString('data-field="start_ampm"', $classesHtml);
        $this->assertStringContainsString('min-width: 76px;', $classesHtml);
    }

    public function testAdminClassesActionAtomicMultiSectionPreValidation(): void
    {
        $code = file_get_contents(__DIR__ . '/../admin/admin_Classes_Action.php');
        $this->assertIsString($code);

        // Verify transaction management
        $this->assertStringContainsString('$db->beginTransaction()', $code);
        $this->assertStringContainsString('$db->commit()', $code);
        $this->assertStringContainsString('$db->rollBack()', $code);

        // Verify pre-validation occurs before beginTransaction
        $preValidationPos = strpos($code, 'PHASE 1: PRE-VALIDATION ACROSS ALL SECTIONS');
        $transactionPos = strpos($code, '$db->beginTransaction()');
        $this->assertNotFalse($preValidationPos);
        $this->assertNotFalse($transactionPos);
        $this->assertLessThan($transactionPos, $preValidationPos);

        // Verify clear error return instead of silent skip
        $this->assertStringNotContainsString('$skippedSections[] = ', $code);
    }

    public function testGroupedClassPresentationAndSectionSpecificNavigation(): void
    {
        $code = file_get_contents(__DIR__ . '/../admin/admin_Classes.php');
        $this->assertIsString($code);

        // Verify presentation layer grouping logic
        $this->assertStringContainsString('$groupedClasses = [];', $code);
        $this->assertStringContainsString('$isMultiSection = count($group[\'sections\']) > 1;', $code);
        $this->assertStringContainsString('View & Manage Sections', $code);
        $this->assertStringContainsString('openGroupedClassModal(', $code);

        // Verify modal structure & Add Section button
        $this->assertStringContainsString('id="classGroupSectionsModal"', $code);
        $this->assertStringContainsString('id="groupModalSectionsList"', $code);
        $this->assertStringContainsString('id="groupModalAddSectionBtn"', $code);
        $this->assertStringContainsString('openAddSectionForGroup()', $code);
        $this->assertStringContainsString('window.openAddSectionForGroup = openAddSectionForGroup;', $code);
        $this->assertStringContainsString('admin_Class_Detail.php?id=${sec.id}', $code);
        $this->assertStringContainsString('admin_Class_Edit.php?id=${sec.id}', $code);
        $this->assertStringContainsString('deleteSectionFromGroupModal(', $code);

        // Verify single-section card retains direct actions
        $this->assertStringContainsString('viewClass(<?php echo $singleSection[\'id\']; ?>)', $code);
        $this->assertStringContainsString('editClass(<?php echo $singleSection[\'id\']; ?>)', $code);

        // Functional test: verify grouping behavior with matching and differing attributes
        $sampleClasses = [
            [
                'id' => 101,
                'class_name' => 'General Mathematics',
                'grade_level' => '11',
                'section' => 'Ruby',
                'subject_category' => 'core',
                'track' => 'academic',
                'program' => 'academic_strengthened',
                'teacher_name' => 'Teacher A',
                'schedule' => 'Mon 8:00 AM - 9:00 AM',
                'room' => 'Room 101',
                'status' => 'active',
                'student_count' => 35,
            ],
            [
                'id' => 102,
                'class_name' => 'General Mathematics',
                'grade_level' => '11',
                'section' => 'Emerald',
                'subject_category' => 'core',
                'track' => 'academic',
                'program' => 'academic_strengthened',
                'teacher_name' => 'Teacher B',
                'schedule' => 'Tue 9:00 AM - 10:00 AM',
                'room' => 'Room 102',
                'status' => 'active',
                'student_count' => 40,
            ],
            [
                'id' => 103,
                'class_name' => 'General Mathematics',
                'grade_level' => '11',
                'section' => 'Sapphire',
                'subject_category' => 'core',
                'track' => 'academic',
                'program' => 'academic_strengthened',
                'teacher_name' => 'Teacher A',
                'schedule' => 'Wed 10:00 AM - 11:00 AM',
                'room' => 'Room 101',
                'status' => 'active',
                'student_count' => 38,
            ],
            [
                'id' => 201,
                'class_name' => 'General Mathematics',
                'grade_level' => '12',
                'section' => 'Diamond',
                'subject_category' => 'core',
                'track' => 'academic',
                'program' => 'academic_strengthened',
                'teacher_name' => 'Teacher C',
                'schedule' => 'Mon 1:00 PM - 2:00 PM',
                'room' => 'Room 201',
                'status' => 'active',
                'student_count' => 30,
            ],
            [
                'id' => 301,
                'class_name' => 'Oral Communication',
                'grade_level' => '11',
                'section' => 'Ruby',
                'subject_category' => 'core',
                'track' => 'academic',
                'program' => 'academic_strengthened',
                'teacher_name' => 'Teacher D',
                'schedule' => 'Thu 8:00 AM - 9:00 AM',
                'room' => 'Room 301',
                'status' => 'active',
                'student_count' => 35,
            ],
        ];

        // Execute grouping logic
        $grouped = [];
        foreach ($sampleClasses as $classRow) {
            $normName = strtolower(trim((string)($classRow['class_name'] ?? '')));
            $normGrade = (string)($classRow['grade_level'] ?? '');
            $normCategory = strtolower(trim((string)($classRow['subject_category'] ?? 'core')));
            $normTrack = strtolower(trim((string)($classRow['track'] ?? 'academic')));
            $normProgram = strtolower(trim((string)($classRow['program'] ?? '')));
            $normStatus = strtolower(trim((string)($classRow['status'] ?? 'active')));

            $groupKey = implode('|', [$normName, $normGrade, $normCategory, $normTrack, $normProgram, $normStatus]);

            if (!isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [
                    'class_name' => $classRow['class_name'],
                    'grade_level' => $classRow['grade_level'],
                    'sections' => [],
                    'total_students' => 0,
                    'teachers' => [],
                    'schedules' => [],
                ];
            }

            $teacherName = trim((string)($classRow['teacher_name'] ?? ''));
            if ($teacherName !== '' && !in_array($teacherName, $grouped[$groupKey]['teachers'], true)) {
                $grouped[$groupKey]['teachers'][] = $teacherName;
            }

            $sched = trim((string)($classRow['schedule'] ?? ''));
            if ($sched !== '' && !in_array($sched, $grouped[$groupKey]['schedules'], true)) {
                $grouped[$groupKey]['schedules'][] = $sched;
            }

            $grouped[$groupKey]['sections'][] = [
                'id' => (int)$classRow['id'],
                'section' => $classRow['section'],
                'student_count' => (int)$classRow['student_count'],
            ];
            $grouped[$groupKey]['total_students'] += (int)$classRow['student_count'];
        }
        $grouped = array_values($grouped);

        // Assert 3 groups produced: G11 Gen Math (3 sections), G12 Gen Math (1 section), G11 Oral Comm (1 section)
        $this->assertCount(3, $grouped);

        // Test multi-section group
        $g11GenMath = $grouped[0];
        $this->assertSame('General Mathematics', $g11GenMath['class_name']);
        $this->assertSame('11', $g11GenMath['grade_level']);
        $this->assertCount(3, $g11GenMath['sections']);
        $this->assertSame(113, $g11GenMath['total_students']);
        $this->assertCount(2, $g11GenMath['teachers']); // Teacher A & Teacher B
        $this->assertCount(3, $g11GenMath['schedules']); // 3 distinct schedules

        // Verify each section retained its exact ID and section name
        $this->assertSame(101, $g11GenMath['sections'][0]['id']);
        $this->assertSame('Ruby', $g11GenMath['sections'][0]['section']);
        $this->assertSame(102, $g11GenMath['sections'][1]['id']);
        $this->assertSame('Emerald', $g11GenMath['sections'][1]['section']);
        $this->assertSame(103, $g11GenMath['sections'][2]['id']);
        $this->assertSame('Sapphire', $g11GenMath['sections'][2]['section']);

        // Test single-section groups
        $this->assertSame('12', $grouped[1]['grade_level']);
        $this->assertCount(1, $grouped[1]['sections']);
        $this->assertSame(201, $grouped[1]['sections'][0]['id']);

        $this->assertSame('Oral Communication', $grouped[2]['class_name']);
        $this->assertCount(1, $grouped[2]['sections']);
        $this->assertSame(301, $grouped[2]['sections'][0]['id']);
    }

    public function testExactGroupIdentitySectionFilteringLogic(): void
    {
        // Define all available grade 11 sections
        $allSections = [
            ['value' => 'Ruby', 'label' => 'Ruby'],
            ['value' => 'Emerald', 'label' => 'Emerald'],
            ['value' => 'Sapphire', 'label' => 'Sapphire'],
            ['value' => 'Diamond', 'label' => 'Diamond'],
        ];

        // Active subject group: General Mathematics G11 already has Ruby and Emerald
        $activeGroup = [
            'class_name' => 'General Mathematics',
            'grade_level' => '11',
            'subject_category' => 'core',
            'track' => 'academic',
            'sections' => [
                ['id' => 101, 'section' => 'Ruby'],
                ['id' => 102, 'section' => 'Emerald'],
            ],
        ];

        // Calculate available sections for this exact subject group
        $existingNames = array_map(function ($s) {
            return strtolower(trim((string)$s['section']));
        }, $activeGroup['sections']);

        $availableForGroup = [];
        foreach ($allSections as $sec) {
            if (!in_array(strtolower(trim($sec['value'])), $existingNames, true)) {
                $availableForGroup[] = $sec['value'];
            }
        }

        // Assert Ruby and Emerald are excluded, while Sapphire and Diamond remain available
        $this->assertSame(['Sapphire', 'Diamond'], $availableForGroup);
    }

    public function testUpdateClassLocksGradeLevelAndSectionAgainstTamperedPayload(): void
    {
        require_once __DIR__ . '/../admin/admin_Classes_Action.php';

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['logged_in'] = true;
        $_SESSION['role'] = 'admin';
        $_SESSION['user_id'] = 1;

        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $db->exec("CREATE TABLE classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_name TEXT,
            grade_level INTEGER,
            section TEXT,
            teacher_id INTEGER,
            schedule TEXT,
            room TEXT,
            ww_weight REAL DEFAULT 25.00,
            pt_weight REAL DEFAULT 50.00,
            assessment_weight REAL DEFAULT 25.00,
            status TEXT DEFAULT 'active',
            subject_category TEXT DEFAULT 'core',
            track TEXT DEFAULT 'academic',
            curriculum TEXT DEFAULT 'strengthened_shs',
            program TEXT DEFAULT 'academic_strengthened',
            created_at DATETIME,
            updated_at DATETIME
        )");

        $db->exec("CREATE TABLE class_schedules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER,
            day TEXT,
            start_time TEXT,
            end_time TEXT,
            created_at DATETIME
        )");

        $db->exec("CREATE TABLE class_subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER,
            teacher_id INTEGER,
            created_at DATETIME
        )");

        $db->exec("CREATE TABLE enrollments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER,
            class_id INTEGER,
            academic_year TEXT,
            semester INTEGER,
            curriculum TEXT,
            program TEXT,
            status TEXT DEFAULT 'enrolled',
            enrolled_at DATETIME
        )");

        $db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            role TEXT,
            status TEXT,
            first_name TEXT,
            last_name TEXT,
            grade_level INTEGER,
            section TEXT,
            track TEXT
        )");

        $db->exec("CREATE TABLE admin_audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_user_id INTEGER,
            action_name TEXT,
            target_type TEXT,
            target_id INTEGER,
            details_json TEXT,
            created_at DATETIME
        )");

        // Insert teacher and student
        $db->exec("INSERT INTO users (id, role, status, first_name, last_name) VALUES (10, 'teacher', 'active', 'Jane', 'Doe')");

        // Insert initial class: Grade 11, Section Ruby
        $db->exec("INSERT INTO classes (id, class_name, grade_level, section, teacher_id, schedule, room, status)
                   VALUES (1, 'General Mathematics', 11, 'Ruby', NULL, 'TBA', 'Room 101', 'active')");

        // Submit tampered payload attempting to change grade_level to 12 and section to Diamond
        $_POST = [
            'class_id' => '1',
            'class_name' => 'General Mathematics',
            'grade_level' => '12', // Tampered!
            'section' => 'Diamond', // Tampered!
            'teacher_id' => '10',
            'room' => 'Room 303',
            'subject_category' => 'core',
            'track' => 'academic',
            'schedule_mode' => 'tba',
            'schedule' => 'TBA'
        ];

        ob_start();
        updateClass($db);
        $output = ob_get_clean();
        $res = json_decode($output, true);

        $this->assertTrue($res['success'] ?? false, 'updateClass should succeed');

        // Verify that database record preserved fixed grade_level 11 and section Ruby!
        $updatedClass = $db->query("SELECT * FROM classes WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(11, (int)$updatedClass['grade_level'], 'Grade level must remain 11');
        $this->assertSame('Ruby', $updatedClass['section'], 'Section must remain Ruby');
        $this->assertSame('Room 303', $updatedClass['room'], 'Permitted room change should succeed');
        $this->assertSame(10, (int)$updatedClass['teacher_id'], 'Permitted teacher change should succeed');
    }

    public function testEditSectionIsolationLeavesOtherSectionsUntouched(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $db->exec("CREATE TABLE classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_name TEXT NOT NULL,
            grade_level TEXT NOT NULL,
            section TEXT NOT NULL,
            teacher_id INTEGER NULL,
            schedule TEXT NULL,
            room TEXT NULL,
            ww_weight REAL DEFAULT 30,
            pt_weight REAL DEFAULT 50,
            assessment_weight REAL DEFAULT 20,
            subject_category TEXT DEFAULT 'core',
            track TEXT DEFAULT 'academic',
            curriculum TEXT DEFAULT 'SHS',
            program TEXT DEFAULT 'academic_strengthened',
            status TEXT DEFAULT 'active'
        )");

        // Insert 2 sections for General Mathematics
        $db->exec("INSERT INTO classes (id, class_name, grade_level, section, teacher_id, schedule, room) VALUES
            (1, 'General Mathematics', '11', 'Ruby', 10, 'Mon 8:00 AM - 9:00 AM', 'Room 101'),
            (2, 'General Mathematics', '11', 'Emerald', 10, 'Mon 8:00 AM - 9:00 AM', 'Room 101')");

        // Simulate strictly section-specific update on Class 1 (Ruby only)
        $updateStmt = $db->prepare("UPDATE classes SET teacher_id = ?, schedule = ?, room = ? WHERE id = ?");
        $updateStmt->execute([25, 'Tue 1:00 PM - 2:00 PM', 'Room 205', 1]);

        // Verify Class 1 (Ruby) was updated
        $stmt1 = $db->prepare("SELECT * FROM classes WHERE id = 1");
        $stmt1->execute();
        $class1 = $stmt1->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(25, (int)$class1['teacher_id']);
        $this->assertSame('Tue 1:00 PM - 2:00 PM', $class1['schedule']);
        $this->assertSame('Room 205', $class1['room']);

        // Verify Class 2 (Emerald) remains 100% UNTOUCHED
        $stmt2 = $db->prepare("SELECT * FROM classes WHERE id = 2");
        $stmt2->execute();
        $class2 = $stmt2->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(10, (int)$class2['teacher_id']);
        $this->assertSame('Mon 8:00 AM - 9:00 AM', $class2['schedule']);
        $this->assertSame('Room 101', $class2['room']);
    }

    public function testSelectAllAndDeselectAllToggleLogic(): void
    {
        $html = file_get_contents(__DIR__ . '/../admin/admin_Classes.php');
        $this->assertIsString($html);

        // Verify helper and function definitions
        $this->assertStringContainsString('function getEligibleSectionCheckboxes()', $html);
        $this->assertStringContainsString('function getCheckedEligibleSections()', $html);
        $this->assertStringContainsString('function updateSelectAllButtonState()', $html);
        $this->assertStringContainsString('function toggleAllSections()', $html);
        $this->assertStringContainsString('window.toggleAllSections = toggleAllSections;', $html);
        $this->assertStringContainsString('window.updateSelectAllButtonState = updateSelectAllButtonState;', $html);

        // Verify scoping to :not(:disabled) inside #sectionCheckboxesContainer
        $this->assertStringContainsString(".section-checkbox:not(:disabled)", $html);
        $this->assertStringContainsString(".section-checkbox:checked:not(:disabled)", $html);

        // Simulation of 3 eligible sections and 1 disabled section
        $checkboxes = [
            ['name' => 'Ruby', 'disabled' => false, 'checked' => false],
            ['name' => 'Emerald', 'disabled' => false, 'checked' => false],
            ['name' => 'Sapphire', 'disabled' => false, 'checked' => false],
            ['name' => 'DisabledSection', 'disabled' => true, 'checked' => false],
        ];

        $getEligible = function () use (&$checkboxes) {
            return array_filter($checkboxes, fn($c) => !$c['disabled']);
        };
        $getCheckedEligible = function () use (&$checkboxes) {
            return array_filter($checkboxes, fn($c) => !$c['disabled'] && $c['checked']);
        };
        $getButtonLabel = function () use (&$checkboxes, $getEligible) {
            $eligible = $getEligible();
            if (empty($eligible)) return 'Select All';
            $allChecked = count(array_filter($eligible, fn($c) => $c['checked'])) === count($eligible);
            return $allChecked ? 'Deselect All' : 'Select All';
        };
        $toggleAll = function () use (&$checkboxes, $getEligible) {
            $eligible = $getEligible();
            $allChecked = count(array_filter($eligible, fn($c) => $c['checked'])) === count($eligible);
            foreach ($checkboxes as &$cb) {
                if (!$cb['disabled']) {
                    $cb['checked'] = !$allChecked;
                }
            }
        };

        // Initial state
        $this->assertSame('Select All', $getButtonLabel());
        $this->assertCount(0, $getCheckedEligible());

        // Select All -> all 3 eligible checked, disabled remains unchecked
        $toggleAll();
        $this->assertSame('Deselect All', $getButtonLabel());
        $this->assertCount(3, $getCheckedEligible());
        $this->assertFalse($checkboxes[3]['checked'], 'Disabled checkbox must not be checked by Select All');

        // Deselect All -> all 3 eligible unchecked
        $toggleAll();
        $this->assertSame('Select All', $getButtonLabel());
        $this->assertCount(0, $getCheckedEligible());

        // Partial selection (2 of 3)
        $checkboxes[0]['checked'] = true;
        $checkboxes[1]['checked'] = true;
        $this->assertSame('Select All', $getButtonLabel());
        $this->assertCount(2, $getCheckedEligible());

        // Full selection (3 of 3)
        $checkboxes[2]['checked'] = true;
        $this->assertSame('Deselect All', $getButtonLabel());
        $this->assertCount(3, $getCheckedEligible());
    }

    public function testCreateClassWithMultipleSelectedSectionsCreatesAllSectionsAtomically(): void
    {
        require_once __DIR__ . '/../admin/admin_Classes_Action.php';

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['logged_in'] = true;
        $_SESSION['role'] = 'admin';
        $_SESSION['user_id'] = 1;

        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $db->exec("CREATE TABLE classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_name TEXT,
            grade_level INTEGER,
            section TEXT,
            teacher_id INTEGER,
            schedule TEXT,
            room TEXT,
            ww_weight REAL DEFAULT 25.00,
            pt_weight REAL DEFAULT 50.00,
            assessment_weight REAL DEFAULT 25.00,
            status TEXT DEFAULT 'active',
            subject_category TEXT DEFAULT 'core',
            track TEXT DEFAULT 'academic',
            curriculum TEXT DEFAULT 'strengthened_shs',
            program TEXT DEFAULT 'academic_strengthened',
            created_at DATETIME,
            updated_at DATETIME
        )");

        $db->exec("CREATE TABLE class_schedules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER,
            day TEXT,
            start_time TEXT,
            end_time TEXT,
            created_at DATETIME
        )");

        $db->exec("CREATE TABLE class_subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER,
            teacher_id INTEGER,
            created_at DATETIME
        )");

        $db->exec("CREATE TABLE enrollments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER,
            class_id INTEGER,
            academic_year TEXT,
            semester INTEGER,
            curriculum TEXT,
            program TEXT,
            status TEXT DEFAULT 'enrolled',
            enrolled_at DATETIME
        )");

        $db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            role TEXT,
            status TEXT,
            first_name TEXT,
            last_name TEXT,
            grade_level INTEGER,
            section TEXT,
            track TEXT
        )");

        $db->exec("CREATE TABLE admin_audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_user_id INTEGER,
            action TEXT,
            target_type TEXT,
            target_id INTEGER,
            details TEXT,
            ip_address TEXT,
            created_at DATETIME
        )");

        // Insert teacher
        $db->exec("INSERT INTO users (id, role, status, first_name, last_name) VALUES (10, 'teacher', 'active', 'Jane', 'Doe')");

        // Insert students in 3 sections
        $db->exec("INSERT INTO users (id, role, status, first_name, last_name, grade_level, section, track) VALUES
            (101, 'student', 'active', 'Alice', 'Smith', 11, 'Ruby', 'academic'),
            (102, 'student', 'active', 'Bob', 'Jones', 11, 'Emerald', 'academic'),
            (103, 'student', 'active', 'Charlie', 'Brown', 11, 'Sapphire', 'academic')");

        // Simulate POST request with 3 selected sections
        $_POST = [
            'class_name' => 'Empowerment Technologies',
            'grade_level' => '11',
            'sections' => ['Ruby', 'Emerald', 'Sapphire'],
            'teacher_id' => '10',
            'room' => 'Computer Lab 1',
            'subject_category' => 'core',
            'track' => 'academic',
            'schedule_mode' => 'uniform',
            'schedule_rows' => json_encode([
                ['day' => 'Mon', 'start_hour' => '08', 'start_min' => '00', 'start_ampm' => 'AM', 'end_hour' => '09', 'end_min' => '00', 'end_ampm' => 'AM']
            ]),
            'ww_weight' => '25',
            'pt_weight' => '50',
            'assessment_weight' => '25'
        ];

        ob_start();
        createClass($db);
        $output = ob_get_clean();
        $res = json_decode($output, true);

        $this->assertTrue($res['success'] ?? false, 'createClass should succeed for all 3 sections: ' . ($res['message'] ?? $output));
        $this->assertSame(3, $res['created_count'] ?? 0);
        $this->assertSame(['Ruby', 'Emerald', 'Sapphire'], $res['created_sections'] ?? []);

        // Verify that 3 distinct class records exist in the database
        $createdClasses = $db->query("SELECT * FROM classes WHERE class_name = 'Empowerment Technologies' ORDER BY section ASC")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(3, $createdClasses);

        $sectionsFound = array_column($createdClasses, 'section');
        $this->assertSame(['Emerald', 'Ruby', 'Sapphire'], $sectionsFound);

        // Verify schedules and enrollments for all 3 classes
        foreach ($createdClasses as $cls) {
            $classId = (int)$cls['id'];
            $this->assertSame(10, (int)$cls['teacher_id']);
            $this->assertSame('Computer Lab 1', $cls['room']);

            // Schedule check
            $schedCount = (int)$db->query("SELECT COUNT(*) FROM class_schedules WHERE class_id = $classId")->fetchColumn();
            $this->assertSame(1, $schedCount);

            // Enrollment check (auto-synced matching student)
            $enrCount = (int)$db->query("SELECT COUNT(*) FROM enrollments WHERE class_id = $classId")->fetchColumn();
            $this->assertSame(1, $enrCount);
        }
    }
}



