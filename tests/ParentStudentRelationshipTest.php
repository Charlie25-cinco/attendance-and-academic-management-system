<?php
declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

final class ParentStudentRelationshipTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        if (!class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('SQLite PDO driver is not available.');
        }

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reference_code TEXT UNIQUE NOT NULL,
            email TEXT UNIQUE NOT NULL,
            lrn TEXT NULL,
            password TEXT NOT NULL,
            first_name TEXT NOT NULL,
            middle_name TEXT NULL,
            last_name TEXT NOT NULL,
            sex TEXT NULL,
            contact_number TEXT NULL,
            grade_level INTEGER NULL,
            section TEXT NULL,
            track TEXT NULL,
            role TEXT NOT NULL,
            status TEXT DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE TABLE parent_students (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            parent_id INTEGER NOT NULL,
            student_id INTEGER NOT NULL,
            relationship TEXT NULL,
            UNIQUE(parent_id, student_id)
        )");

        $this->db->exec("CREATE TABLE classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_name TEXT NOT NULL,
            grade_level INTEGER NOT NULL,
            section TEXT NOT NULL,
            teacher_id INTEGER NULL,
            status TEXT DEFAULT 'active'
        )");

        $this->db->exec("CREATE TABLE enrollments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            class_id INTEGER NOT NULL,
            academic_year TEXT NOT NULL,
            status TEXT DEFAULT 'enrolled',
            UNIQUE(student_id, class_id, academic_year)
        )");

        $this->db->exec("CREATE TABLE messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            from_user_id INTEGER NOT NULL,
            to_user_id INTEGER NOT NULL,
            message TEXT NOT NULL,
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }

    public function testOneParentLinkedToMultipleStudents(): void
    {
        $pw = password_hash('password', PASSWORD_BCRYPT);
        // Create 1 parent and 2 sibling students
        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role) VALUES ('PAR-001', 'father@example.com', ?, 'Juan', 'Dela Cruz', 'parent')")->execute([$pw]);
        $parentId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, grade_level, section) VALUES ('STU-001', 'sibling1@example.com', ?, 'Pedro', 'Dela Cruz', 'student', 11, 'Emerald')")->execute([$pw]);
        $student1Id = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, grade_level, section) VALUES ('STU-002', 'sibling2@example.com', ?, 'Ana', 'Dela Cruz', 'student', 11, 'Emerald')")->execute([$pw]);
        $student2Id = (int)$this->db->lastInsertId();

        // Link parent to both siblings
        $insertStmt = $this->db->prepare("INSERT INTO parent_students (parent_id, student_id, relationship) VALUES (?, ?, ?)");
        $insertStmt->execute([$parentId, $student1Id, 'Father']);
        $insertStmt->execute([$parentId, $student2Id, 'Father']);

        // Verify parent has 2 linked students
        $linkedStudents = $this->db->query("SELECT student_id FROM parent_students WHERE parent_id = {$parentId} ORDER BY student_id ASC")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(2, $linkedStudents);
        $this->assertEquals([$student1Id, $student2Id], array_map('intval', $linkedStudents));
    }

    public function testMultipleParentsLinkedToSameStudent(): void
    {
        $pw = password_hash('password', PASSWORD_BCRYPT);
        // Create student
        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, grade_level, section) VALUES ('STU-003', 'child@example.com', ?, 'Carlos', 'Santos', 'student', 12, 'Diamond')")->execute([$pw]);
        $studentId = (int)$this->db->lastInsertId();

        // Create Father, Mother, Guardian
        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role) VALUES ('PAR-002', 'father.santos@example.com', ?, 'Roberto', 'Santos', 'parent')")->execute([$pw]);
        $fatherId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role) VALUES ('PAR-003', 'mother.santos@example.com', ?, 'Elena', 'Santos', 'parent')")->execute([$pw]);
        $motherId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role) VALUES ('PAR-004', 'guardian.santos@example.com', ?, 'Maria', 'Reyes', 'parent')")->execute([$pw]);
        $guardianId = (int)$this->db->lastInsertId();

        // Link all 3 parents to the same student
        $insertStmt = $this->db->prepare("INSERT INTO parent_students (parent_id, student_id, relationship) VALUES (?, ?, ?)");
        $insertStmt->execute([$fatherId, $studentId, 'Father']);
        $insertStmt->execute([$motherId, $studentId, 'Mother']);
        $insertStmt->execute([$guardianId, $studentId, 'Guardian']);

        // Verify student has 3 distinct parents
        $linkedParents = $this->db->query("SELECT parent_id, relationship FROM parent_students WHERE student_id = {$studentId} ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(3, $linkedParents);
        $this->assertSame($fatherId, (int)$linkedParents[0]['parent_id']);
        $this->assertSame('Father', $linkedParents[0]['relationship']);
        $this->assertSame($motherId, (int)$linkedParents[1]['parent_id']);
        $this->assertSame('Mother', $linkedParents[1]['relationship']);
        $this->assertSame($guardianId, (int)$linkedParents[2]['parent_id']);
        $this->assertSame('Guardian', $linkedParents[2]['relationship']);
    }

    public function testDuplicateParentStudentPairIsPreventedBySchemaConstraint(): void
    {
        $pw = password_hash('password', PASSWORD_BCRYPT);
        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role) VALUES ('PAR-005', 'parent5@example.com', ?, 'Luis', 'Gomez', 'parent')")->execute([$pw]);
        $parentId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, grade_level, section) VALUES ('STU-004', 'student4@example.com', ?, 'Mark', 'Gomez', 'student', 11, 'Emerald')")->execute([$pw]);
        $studentId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO parent_students (parent_id, student_id, relationship) VALUES (?, ?, ?)")->execute([$parentId, $studentId, 'Father']);

        $this->expectException(\PDOException::class);
        $this->db->prepare("INSERT INTO parent_students (parent_id, student_id, relationship) VALUES (?, ?, ?)")->execute([$parentId, $studentId, 'Father']);
    }

    public function testSiblingStudentsSharingParentInClassNotificationLookup(): void
    {
        $pw = password_hash('password', PASSWORD_BCRYPT);
        // 1 parent
        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role) VALUES ('PAR-006', 'parent6@example.com', ?, 'Teresa', 'Mercado', 'parent')")->execute([$pw]);
        $parentId = (int)$this->db->lastInsertId();

        // 2 sibling students
        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, grade_level, section) VALUES ('STU-005', 'stu5@example.com', ?, 'Ken', 'Mercado', 'student', 11, 'Emerald')")->execute([$pw]);
        $stu1Id = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, grade_level, section) VALUES ('STU-006', 'stu6@example.com', ?, 'Kim', 'Mercado', 'student', 11, 'Emerald')")->execute([$pw]);
        $stu2Id = (int)$this->db->lastInsertId();

        // Link parent to both siblings
        $this->db->prepare("INSERT INTO parent_students (parent_id, student_id, relationship) VALUES (?, ?, 'Mother')")->execute([$parentId, $stu1Id]);
        $this->db->prepare("INSERT INTO parent_students (parent_id, student_id, relationship) VALUES (?, ?, 'Mother')")->execute([$parentId, $stu2Id]);

        // Class & enrollments
        $this->db->prepare("INSERT INTO classes (class_name, grade_level, section, status) VALUES ('General Mathematics', 11, 'Emerald', 'active')")->execute();
        $classId = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO enrollments (student_id, class_id, academic_year, status) VALUES (?, ?, '2026-2027', 'enrolled')")->execute([$stu1Id, $classId]);
        $this->db->prepare("INSERT INTO enrollments (student_id, class_id, academic_year, status) VALUES (?, ?, '2026-2027', 'enrolled')")->execute([$stu2Id, $classId]);

        // Class notification recipient lookup
        $studentStmt = $this->db->prepare("SELECT DISTINCT e.student_id
                                           FROM enrollments e
                                           JOIN users u ON u.id = e.student_id
                                           WHERE e.class_id = ? AND COALESCE(e.status, 'enrolled') = 'enrolled'
                                           AND u.role = 'student' AND u.status = 'active'");
        $studentStmt->execute([$classId]);
        $studentIds = array_map('intval', $studentStmt->fetchAll(PDO::FETCH_COLUMN));

        $this->assertCount(2, $studentIds);
        $this->assertContains($stu1Id, $studentIds);
        $this->assertContains($stu2Id, $studentIds);

        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $parentStmt = $this->db->prepare("SELECT DISTINCT p.id
                                          FROM parent_students ps
                                          JOIN users p ON p.id = ps.parent_id
                                          WHERE ps.student_id IN ($placeholders)
                                          AND p.role = 'parent' AND p.status = 'active'");
        $parentStmt->execute($studentIds);
        $parentIds = array_map('intval', $parentStmt->fetchAll(PDO::FETCH_COLUMN));

        // Parent should appear exactly once in notification recipients despite having 2 enrolled children
        $this->assertCount(1, $parentIds);
        $this->assertSame($parentId, $parentIds[0]);
    }

    public function testTeacherChatAggregatesSiblingStudentsUnderSingleParentConversation(): void
    {
        $pw = password_hash('password', PASSWORD_BCRYPT);
        // Teacher
        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role) VALUES ('TCH-001', 'teacher@example.com', ?, 'Adviser', 'One', 'teacher')")->execute([$pw]);
        $teacherId = (int)$this->db->lastInsertId();

        // Class
        $this->db->prepare("INSERT INTO classes (class_name, grade_level, section, teacher_id, status) VALUES ('11-Emerald', 11, 'Emerald', ?, 'active')")->execute([$teacherId]);

        // Parent
        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role) VALUES ('PAR-007', 'parent7@example.com', ?, 'Grace', 'Tan', 'parent')")->execute([$pw]);
        $parentId = (int)$this->db->lastInsertId();

        // 2 sibling students
        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, grade_level, section) VALUES ('STU-007', 'stu7@example.com', ?, 'Lucas', 'Tan', 'student', 11, 'Emerald')")->execute([$pw]);
        $stu1Id = (int)$this->db->lastInsertId();

        $this->db->prepare("INSERT INTO users (reference_code, email, password, first_name, last_name, role, grade_level, section) VALUES ('STU-008', 'stu8@example.com', ?, 'Liam', 'Tan', 'student', 11, 'Emerald')")->execute([$pw]);
        $stu2Id = (int)$this->db->lastInsertId();

        // Link parent to both siblings
        $this->db->prepare("INSERT INTO parent_students (parent_id, student_id, relationship) VALUES (?, ?, 'Mother')")->execute([$parentId, $stu1Id]);
        $this->db->prepare("INSERT INTO parent_students (parent_id, student_id, relationship) VALUES (?, ?, 'Mother')")->execute([$parentId, $stu2Id]);

        // Query raw conversations
        $stmt = $this->db->prepare("
            SELECT DISTINCT
                u_parent.id AS parent_id,
                u_parent.first_name AS parent_first,
                u_parent.last_name AS parent_last,
                u_parent.email AS parent_email,
                u_student.id AS student_id,
                u_student.first_name AS student_first,
                u_student.last_name AS student_last,
                u_student.grade_level,
                u_student.section
            FROM parent_students ps
            JOIN users u_parent ON u_parent.id = ps.parent_id AND u_parent.role = 'parent' AND u_parent.status = 'active'
            JOIN users u_student ON u_student.id = ps.student_id AND u_student.status = 'active'
            JOIN classes c ON c.teacher_id = ?
                AND c.status = 'active'
                AND c.grade_level = u_student.grade_level
                AND c.section = u_student.section
            WHERE ps.parent_id = ?
        ");
        $stmt->execute([$teacherId, $parentId]);
        $rawConversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(2, $rawConversations, 'Raw SQL returns 2 rows for 2 sibling students');

        // Apply our PHP aggregation logic
        $conversationsByParent = [];
        foreach ($rawConversations as $row) {
            $pid = (int)($row['parent_id'] ?? 0);
            if ($pid <= 0) continue;
            $studentName = trim(($row['student_first'] ?? '') . ' ' . ($row['student_last'] ?? ''));
            if (!isset($conversationsByParent[$pid])) {
                $row['student_names'] = $studentName !== '' ? [$studentName] : [];
                $conversationsByParent[$pid] = $row;
            } else {
                if ($studentName !== '' && !in_array($studentName, $conversationsByParent[$pid]['student_names'], true)) {
                    $conversationsByParent[$pid]['student_names'][] = $studentName;
                }
            }
        }
        $conversations = array_values($conversationsByParent);

        $this->assertCount(1, $conversations, 'Aggregated list should have exactly 1 conversation thread for the parent');
        $this->assertSame($parentId, (int)$conversations[0]['parent_id']);
        $this->assertEquals(['Lucas Tan', 'Liam Tan'], $conversations[0]['student_names']);

        $renderedStudentName = !empty($conversations[0]['student_names'])
            ? implode(', ', $conversations[0]['student_names'])
            : trim($conversations[0]['student_first'] . ' ' . $conversations[0]['student_last']);
        $this->assertSame('Lucas Tan, Liam Tan', $renderedStudentName);
    }
}
