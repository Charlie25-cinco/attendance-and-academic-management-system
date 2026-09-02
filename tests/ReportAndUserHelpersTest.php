<?php

use BshsAms\Report\ReportFilterHelper;
use BshsAms\User\UserValidationHelper;
use PHPUnit\Framework\TestCase;

final class ReportAndUserHelpersTest extends TestCase
{
    public function testReportFilterHelperDateFilterWhitelisting(): void
    {
        $allLegitimateColumns = [
            'a.date',
            'sg.recorded_at',
            'g.created_at',
            'e.enrolled_at',
            'u.created_at',
            'c.created_at',
        ];

        foreach ($allLegitimateColumns as $column) {
            $where = [];
            $params = [];
            ReportFilterHelper::appendDateFilter($column, $where, $params, '2026-08-01', '2026-08-31');

            $this->assertCount(2, $where, "Column {$column} should produce 2 where clauses");
            $this->assertSame("DATE({$column}) >= ?", $where[0]);
            $this->assertSame("DATE({$column}) <= ?", $where[1]);
            $this->assertSame(['2026-08-01', '2026-08-31'], $params);
        }
    }

    public function testReportFilterHelperRejectsUnregisteredColumns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid or unregistered date column: 'malicious_column; DROP TABLE users'");

        $where = [];
        $params = [];
        ReportFilterHelper::appendDateFilter('malicious_column; DROP TABLE users', $where, $params, '2026-08-01', '');
    }

    public function testReportFilterHelperAdvancedFilters(): void
    {
        $where = [];
        $params = [];
        ReportFilterHelper::appendAdvancedFilters('attendance', $where, $params, [
            'class_id' => 15,
            'grade_level' => 11,
            'section' => 'Emerald',
            'status' => 'present'
        ]);

        $this->assertContains('a.class_id = ?', $where);
        $this->assertContains('c.grade_level = ?', $where);
        $this->assertContains('c.section = ?', $where);
        $this->assertContains('a.status = ?', $where);
        $this->assertSame([15, 11, 'Emerald', 'present'], $params);
    }

    public function testReportFilterHelperBuildFilterParamsAndStatusOptions(): void
    {
        $params = ReportFilterHelper::buildFilterParams('2026-08-01', '2026-08-31', 10, 12, 'Ruby', 'active', 25);
        $this->assertSame('2026-08-01', $params['date_from']);
        $this->assertSame('2026-08-31', $params['date_to']);
        $this->assertSame(10, $params['class_id']);
        $this->assertSame(12, $params['grade_level']);
        $this->assertSame('Ruby', $params['section']);
        $this->assertSame('active', $params['status']);
        $this->assertSame(25, $params['top_n']);

        $attendanceStatuses = ReportFilterHelper::getStatusOptions('attendance');
        $this->assertSame(['present', 'absent', 'late'], $attendanceStatuses);

        $this->assertSame('87.5%', ReportFilterHelper::formatPercent(87.5));
    }

    public function testUserValidationHelperWithSqliteFixture(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            first_name TEXT,
            last_name TEXT,
            role TEXT,
            grade_level INTEGER,
            section TEXT,
            status TEXT
        )");

        $db->exec("CREATE TABLE classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_name TEXT,
            grade_level INTEGER,
            section TEXT,
            status TEXT
        )");

        $db->exec("CREATE TABLE class_subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER,
            teacher_id INTEGER
        )");

        $db->exec("CREATE TABLE parent_students (
            parent_id INTEGER,
            student_id INTEGER
        )");

        // Seed users
        $db->exec("INSERT INTO users (first_name, last_name, role, grade_level, section, status)
                   VALUES ('Maria', 'Santos', 'teacher', 11, 'Emerald', 'active')");
        $teacherId = (int)$db->lastInsertId();

        $db->exec("INSERT INTO users (first_name, last_name, role, grade_level, section, status)
                   VALUES ('Juan', 'Dela Cruz', 'student', 11, 'Emerald', 'active')");
        $studentId = (int)$db->lastInsertId();

        $db->exec("INSERT INTO users (first_name, last_name, role, status)
                   VALUES ('Pedro', 'Dela Cruz', 'parent', 'active')");
        $parentId = (int)$db->lastInsertId();

        // 1. Teacher section taken test
        $this->assertTrue(UserValidationHelper::isTeacherSectionTaken($db, 11, 'Emerald'));
        $this->assertFalse(UserValidationHelper::isTeacherSectionTaken($db, 11, 'Emerald', $teacherId));
        $this->assertFalse(UserValidationHelper::isTeacherSectionTaken($db, 12, 'Diamond'));

        // 2. Student validity test
        $this->assertTrue(UserValidationHelper::areValidStudents($db, [$studentId]));
        $this->assertFalse(UserValidationHelper::areValidStudents($db, [$studentId, 9999]));
        $this->assertFalse(UserValidationHelper::areValidStudents($db, []));

        // 3. Parent student relationship test (multiple parents permitted per DepEd SF1)
        $db->exec("INSERT INTO parent_students (parent_id, student_id) VALUES ($parentId, $studentId)");
        $conflicts = UserValidationHelper::getStudentParentConflicts($db, [$studentId]);
        $this->assertEmpty($conflicts);

        // Exclude current parent also returns no conflicts
        $noConflicts = UserValidationHelper::getStudentParentConflicts($db, [$studentId], $parentId);
        $this->assertEmpty($noConflicts);
    }
}
