<?php

use BshsAms\Audit\OwnActivityLogQuery;
use PHPUnit\Framework\TestCase;

final class OwnActivityLogsTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE activity_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                actor_user_id INTEGER NULL,
                actor_role TEXT NOT NULL,
                action_name TEXT NOT NULL,
                target_type TEXT NOT NULL,
                target_id INTEGER NULL,
                details_json TEXT NULL,
                ip_address TEXT NULL,
                created_at TEXT NOT NULL
            )'
        );

        $insert = $this->db->prepare(
            'INSERT INTO activity_logs
             (actor_user_id, actor_role, action_name, target_type, target_id, details_json, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([10, 'teacher', 'attendance.submit', 'class', 5, '{"status":"saved"}', '192.0.2.1', '2026-10-09 08:00:00']);
        $insert->execute([10, 'teacher', 'grade.submit', 'report_card', 6, '{"period":"first"}', '192.0.2.1', '2026-10-08 08:00:00']);
        $insert->execute([10, 'student', 'profile.update', 'user', 10, null, '192.0.2.1', '2026-10-09 09:00:00']);
        $insert->execute([11, 'teacher', 'attendance.submit', 'class', 8, null, '192.0.2.2', '2026-10-09 10:00:00']);
    }

    public function testQueryReturnsOnlyTheSignedInActorsRoleScopedRows(): void
    {
        $result = (new OwnActivityLogQuery($this->db))->search(10, 'teacher');

        self::assertSame(2, $result['total']);
        self::assertCount(2, $result['rows']);
        self::assertSame(['attendance.submit', 'grade.submit'], array_column($result['rows'], 'action_name'));
        self::assertArrayNotHasKey('ip_address', $result['rows'][0]);
        self::assertArrayNotHasKey('actor_user_id', $result['rows'][0]);
    }

    public function testFiltersCannotBroadenOwnershipScope(): void
    {
        $result = (new OwnActivityLogQuery($this->db))->search(10, 'teacher', [
            'search' => 'attendance',
            'date_from' => '2026-10-09',
            'date_to' => '2026-10-09',
        ]);

        self::assertSame(1, $result['total']);
        self::assertSame(5, (int)$result['rows'][0]['target_id']);
    }

    public function testSensitiveDetailsAreExcludedFromDisplay(): void
    {
        $formatted = OwnActivityLogQuery::formatDetails(json_encode([
            'status' => 'saved',
            'description' => 'attendance submission',
            'email' => 'private@example.test',
            'ip_address' => '192.0.2.1',
            'nested' => ['token' => 'secret', 'period' => 'first'],
        ]));

        self::assertStringContainsString('Status: saved', $formatted);
        self::assertStringContainsString('Description: attendance submission', $formatted);
        self::assertStringContainsString('Nested Period: first', $formatted);
        self::assertStringNotContainsString('private@example.test', $formatted);
        self::assertStringNotContainsString('192.0.2.1', $formatted);
        self::assertStringNotContainsString('secret', $formatted);
    }

    public function testPagesNavigationAndPermissionsAreRegistered(): void
    {
        $pages = [
            'teacher/teacher_Activity_Logs.php' => ['teacher', 'teacher_activity_logs.php'],
            'student/Student_Activity_Logs.php' => ['student', 'student_activity_logs.php'],
            'parent/Parent_Activity_Logs.php' => ['parent', 'parent_activity_logs.php'],
        ];

        foreach ($pages as $path => [$role, $script]) {
            $source = (string)file_get_contents(APP_ROOT . '/' . $path);
            self::assertStringContainsString("\$activityRole = '$role'", $source);
            self::assertStringContainsString("\$_SESSION['role']", $source);
            self::assertStringContainsString('includes/own_activity_page.php', $source);
            self::assertSame('activity_logs.view_own', permissionForScript($script));
        }

        $sidebar = (string)file_get_contents(APP_ROOT . '/includes/sidebar.php');
        self::assertSame(3, substr_count($sidebar, "'label' => 'My Activity'"));

        $schema = (string)file_get_contents(APP_ROOT . '/database/schema.sql');
        self::assertGreaterThanOrEqual(4, substr_count($schema, 'activity_logs.view_own'));
    }
}
