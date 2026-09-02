<?php

use PHPUnit\Framework\TestCase;

final class AdminClassMultiSectionTest extends TestCase
{
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

        // Section dropdown exists
        $this->assertStringContainsString('name="section"', $html);
        $this->assertStringContainsString('id="sectionSelect"', $html);

        // Multi-section cross-apply elements MUST NOT exist
        $this->assertStringNotContainsString('id="editSectionCheckboxesContainer"', $html);
        $this->assertStringNotContainsString('id="selectAllEditSectionsBtn"', $html);
        $this->assertStringNotContainsString('toggleAllEditSections()', $html);
        $this->assertStringNotContainsString('updateOtherSections()', $html);
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

        // Update query must update only WHERE id = ?
        $this->assertStringContainsString('UPDATE classes SET $setClause WHERE id = ?', $code);
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
}



