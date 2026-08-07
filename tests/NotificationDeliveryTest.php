<?php

use PHPUnit\Framework\TestCase;

final class NotificationDeliveryTest extends TestCase
{
    public function testDispatcherPersistsRoleSpecificNotificationsWithoutPushConfiguration(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT NOT NULL)");
        $db->exec("INSERT INTO users (id, role) VALUES (1, 'student'), (2, 'parent')");

        appDispatchNotification(
            $db,
            [1, 2],
            'school_announcement_42',
            'School announcement: Test',
            'General - Tap to read more',
            'bi-megaphone',
            'warning',
            ['student' => 'Student_Announcements.php', 'parent' => 'Parent_Announcements.php'],
            ['type' => 'school_announcement', 'announcement_id' => 42]
        );

        $rows = $db->query("SELECT user_id, link, is_read FROM user_notifications ORDER BY user_id")
            ->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(2, $rows);
        $this->assertSame('/student/Student_Announcements.php#notification-school-42', $rows[0]['link']);
        $this->assertSame('/parent/Parent_Announcements.php#notification-school-42', $rows[1]['link']);
        $this->assertSame(0, (int)$rows[0]['is_read']);
    }

    public function testNotificationActionsAreScopedToAuthenticatedUser(): void
    {
        $api = file_get_contents(__DIR__ . '/../api/routes/05-notifications.php');
        $javascript = file_get_contents(__DIR__ . '/../assets/js/main.js');
        $serviceWorker = file_get_contents(__DIR__ . '/../sw.js');

        $this->assertIsString($api);
        $this->assertStringContainsString('WHERE id = ? AND user_id = ?', $api);
        $this->assertStringContainsString("\$action === 'read_all'", $api);
        $this->assertStringContainsString("\$action === 'delete_all'", $api);
        $this->assertStringContainsString("'unread_count' => (int)\$countStmt->fetchColumn()", $api);
        $this->assertStringContainsString("\$route === 'web-push-test'", $api);

        $this->assertIsString($javascript);
        $this->assertStringContainsString('initHeaderNotificationActions();', $javascript);
        $this->assertStringContainsString('updateNotificationState("delete_all")', $javascript);
        $this->assertStringContainsString('setHeaderNotificationCount(data.unread_count)', $javascript);
        $this->assertStringContainsString('focusNotificationTarget();', $javascript);

        $this->assertIsString($serviceWorker);
        $this->assertStringContainsString("const CACHE_NAME = 'bshs-ams-v11';", $serviceWorker);
        $this->assertStringContainsString('new URL(targetUrl, self.location.origin).href', $serviceWorker);
    }

    public function testAnnouncementPagesExposeExactNotificationTargets(): void
    {
        foreach (['admin/admin_Announcements.php', 'teacher/teacher_Announcements.php'] as $file) {
            $content = file_get_contents(__DIR__ . '/../' . $file);
            $this->assertIsString($content);
            $this->assertStringContainsString('notification-school-', $content);
        }
        foreach (['student/Student_Announcements.php', 'parent/Parent_Announcements.php'] as $file) {
            $content = file_get_contents(__DIR__ . '/../' . $file);
            $this->assertIsString($content);
            $this->assertStringContainsString('notification_id', $content);
            $this->assertStringContainsString('notification-<?php echo htmlspecialchars($source); ?>-', $content);
        }
    }
}
